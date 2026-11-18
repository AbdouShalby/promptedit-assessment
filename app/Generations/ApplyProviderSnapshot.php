<?php

namespace App\Generations;

use App\Billing\CostCalculator;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place where a generation changes status or gets a cost.
 *
 * The submit response, every webhook and every poll all carry the same thing:
 * a full Pixelforge generation object (a "snapshot"). All three call apply().
 *
 * Rules, enforced under a row lock:
 *   1. Status only moves forward (see GenerationStatus::rank). Equal or older
 *      snapshots are no-ops, so duplicates and reordering are harmless.
 *   2. The cost is written in the same transaction as the move to "succeeded",
 *      and that move can happen only once.
 *   3. A row that was never linked to a provider generation counts as rank 0
 *      even if we gave up on it locally. If Pixelforge did create it after
 *      all, the late snapshot still claims the row and the real cost is
 *      recorded, so our books keep matching GET /v1/usage.
 */
class ApplyProviderSnapshot
{
    /** @param  array<string, mixed>  $snapshot  a Pixelforge generation object */
    public function apply(Generation $generation, array $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot) {
            $row = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            $providerId = (string) ($snapshot['id'] ?? '');
            $newStatus = GenerationStatus::tryFrom((string) ($snapshot['status'] ?? ''));

            if ($providerId === '' || $newStatus === null || $newStatus === GenerationStatus::Pending) {
                Log::warning('Ignored malformed Pixelforge snapshot', ['generation' => $row->uuid]);

                return $row;
            }

            if ($row->provider_id !== null && $row->provider_id !== $providerId) {
                Log::error('Snapshot belongs to a different provider generation', [
                    'generation' => $row->uuid, 'linked' => $row->provider_id, 'received' => $providerId,
                ]);

                return $row;
            }

            $currentRank = $row->provider_id === null ? 0 : $row->status->rank();   // rule 3

            if ($row->provider_id !== null && $row->status->isFinal()) {
                return $row;   // final and settled: nothing may change
            }

            if ($row->status === GenerationStatus::Failed) {
                // Rule 3 in action: we had given up, but Pixelforge did create it. Undo our local verdict.
                Log::warning('Abandoned generation claimed by a late snapshot', ['generation' => $row->uuid]);
                $row->fill([
                    'status' => GenerationStatus::Pending,
                    'error_code' => null, 'error_message' => null,
                    'provider_cost_nano' => null, 'charge_nano' => null, 'completed_at' => null,
                ]);
            }

            $row->provider_id = $providerId;
            $row->rates ??=$snapshot['rates'] ?? null;
            $row->started_at ??= self::time($snapshot['started_at'] ?? null);
            $row->last_checked_at = now();

            if ($newStatus->rank() > $currentRank) {
                match ($newStatus) {
                    GenerationStatus::Succeeded => $this->settleSucceeded($row, $snapshot),
                    GenerationStatus::Failed => $this->settleFailed($row, $snapshot),
                    default => $row->status = $newStatus,
                };
            }

            $row->save();

            return $row;
        });
    }

    /**
     * Final failure decided by us, for a request Pixelforge never accepted
     * (rejected, or given up on). Only a still-pending row can be failed this way.
     */
    public function failWithoutProvider(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message) {
            $row = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($row->status !== GenerationStatus::Pending) {
                return $row;
            }

            $row->fill([
                'status' => GenerationStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => $message,
                'provider_cost_nano' => 0,
                'charge_nano' => 0,
                'completed_at' => now(),
            ])->save();

            return $row;
        });
    }

    /** @param  array<string, mixed>  $snapshot */
    private function settleSucceeded(Generation $row, array $snapshot): void
    {
        $usage = $snapshot['usage'] ?? null;
        $rates = $snapshot['rates'] ?? null;

        if (! is_array($usage) || ! is_array($rates)) {
            throw new IncompleteSnapshotException("Succeeded snapshot without usage or rates for {$row->uuid}");
        }

        // Rates come from the generation itself (fixed at creation), never from today's price list.
        $cost = CostCalculator::providerCost($usage, $rates);
        $charge = CostCalculator::charge($cost, $row->markup_bps);
        $completedAt = self::time($snapshot['completed_at'] ?? null) ?? now();

        if ($charge < $row->estimated_min_charge_nano || $charge > $row->estimated_max_charge_nano) {
            Log::warning('Actual charge is outside the estimate', ['generation' => $row->uuid, 'charge_nano' => $charge]);
        }

        $row->fill([
            'status' => GenerationStatus::Succeeded,
            'rates' => $rates,
            'input_text_tokens' => $usage['input_text_tokens'],
            'input_image_tokens' => $usage['input_image_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'provider_cost_nano' => $cost,
            'charge_nano' => $charge,
            'image_url' => $snapshot['image_url'] ?? null,
            'image_url_expires_at' => self::time($snapshot['image_url_expires_at'] ?? null),
            'completed_at' => $completedAt,
            'billing_date' => $completedAt->toDateString(),   // Pixelforge bills on the UTC day of completion
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /** @param  array<string, mixed>  $snapshot */
    private function settleFailed(Generation $row, array $snapshot): void
    {
        $row->fill([
            'status' => GenerationStatus::Failed,
            'error_code' => $snapshot['error']['code'] ?? 'generation_failed',
            // Pixelforge confirmed the failure, and failed generations are never billed.
            'error_message' => 'Pixelforge could not generate this image. You were not charged. Trying again usually works.',
            'provider_cost_nano' => 0,
            'charge_nano' => 0,
            'completed_at' => self::time($snapshot['completed_at'] ?? null) ?? now(),
        ]);
    }

    private static function time(?string $iso8601): ?Carbon
    {
        return $iso8601 === null ? null : Carbon::parse($iso8601)->utc();
    }
}
