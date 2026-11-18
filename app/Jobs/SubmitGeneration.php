<?php

namespace App\Jobs;

use App\Generations\ApplyProviderSnapshot;
use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one pending generation to Pixelforge.
 *
 * The row's UUID is the Idempotency-Key, so running this job any number of
 * times creates at most one provider generation. Waiting (for a free slot or
 * after a 429/503) is done by releasing the job back to the queue.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    // Messages say "not charged" only when Pixelforge rejected the request before creating anything.
    public const MESSAGE_BUSY = 'Pixelforge was too busy to accept this request. Nothing was generated and you were not charged. Please try again.';

    public const MESSAGE_POLICY = 'Your prompt was rejected by the content policy. Nothing was generated and you were not charged.';

    public const MESSAGE_DAILY_LIMIT = 'Today\'s generation capacity is used up. Nothing was generated and you were not charged. Try again tomorrow (UTC).';

    public const MESSAGE_REJECTED = 'This request could not be sent because of a problem on our side. Nothing was generated and you were not charged.';

    public const MESSAGE_UNCONFIRMED = 'We could not confirm that Pixelforge received this request. If it did run, the image and its cost will appear here.';

    public int $maxExceptions = 3;

    public int $backoff = 10;

    public function __construct(public int $generationId) {}

    /** Releases do not count against a fixed number of tries; the job is bounded by time instead. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(config('pixelforge.submit_deadline_seconds') + 300);
    }

    public function handle(PixelforgeClient $pixelforge, ApplyProviderSnapshot $snapshots): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;   // already submitted (or settled) by an earlier run
        }

        // Tells the reconciler that a job is alive for this row.
        $generation->update(['last_checked_at' => now()]);

        if ($generation->created_at->diffInSeconds(now()) > config('pixelforge.submit_deadline_seconds')) {
            $this->giveUp($snapshots, $generation);

            return;
        }

        // Local gate: when we already hold every slot, wait without spending an API request.
        if (Generation::holdingProviderSlot()->count() >= config('pixelforge.max_concurrent')) {
            $this->release(5);

            return;
        }

        try {
            $snapshot = $pixelforge->createGeneration($generation->providerPayload(), $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleError($snapshots, $generation, $e);

            return;
        }

        $snapshots->apply($generation, $snapshot);
    }

    private function handleError(ApplyProviderSnapshot $snapshots, Generation $generation, PixelforgeException $e): void
    {
        $code = $e->errorCode;

        match (true) {
            // Rejected before anything was created: safe to retry, and safe to say "not charged".
            in_array($code, ['concurrency_limit_exceeded', 'rate_limit_exceeded'], true) => $this->retryLater(
                $snapshots, $generation, $e, 'rate_limit_attempts', config('pixelforge.max_rate_limit_attempts')),

            $code === 'overloaded' => $this->retryLater(
                $snapshots, $generation, $e, 'submit_attempts', config('pixelforge.max_overload_attempts')),

            // Timeout or unknown 5xx: the generation may exist. Retrying with the same key is still safe.
            $e->isAmbiguous() => $this->retryLater(
                $snapshots, $generation, $e, 'submit_attempts', config('pixelforge.max_overload_attempts'), outcomeUnknown: true),

            $code === 'content_policy_violation' => $snapshots->failWithoutProvider($generation, $code, self::MESSAGE_POLICY),

            $code === 'daily_limit_exceeded' => $snapshots->failWithoutProvider($generation, $code, self::MESSAGE_DAILY_LIMIT),

            // Same key, different params: a generation exists under this key, so do not promise "not charged".
            $code === 'idempotency_key_reused' => $this->reject($snapshots, $generation, $e, self::MESSAGE_UNCONFIRMED, nothingWasCreated: false),

            // validation_failed, invalid_api_key, ...: our bug or misconfiguration.
            default => $this->reject($snapshots, $generation, $e, self::MESSAGE_REJECTED),
        };
    }

    private function retryLater(
        ApplyProviderSnapshot $snapshots,
        Generation $generation,
        PixelforgeException $e,
        string $counter,
        int $maxAttempts,
        bool $outcomeUnknown = false,
    ): void {
        $generation->increment($counter, 1, $outcomeUnknown ? ['submit_outcome_unknown' => true] : []);

        if ($generation->{$counter} >= $maxAttempts) {
            $this->giveUp($snapshots, $generation);

            return;
        }

        // Growing backoff (2, 4, 8, 16, 30 s), never shorter than Retry-After.
        $backoff = min(30, 2 ** $generation->{$counter});

        $this->release(max($e->retryAfter ?? 0, $backoff));
    }

    private function giveUp(ApplyProviderSnapshot $snapshots, Generation $generation): void
    {
        $generation->submit_outcome_unknown
            ? $snapshots->failWithoutProvider($generation, 'submission_unconfirmed', self::MESSAGE_UNCONFIRMED, nothingWasCreated: false)
            : $snapshots->failWithoutProvider($generation, 'submission_abandoned', self::MESSAGE_BUSY);
    }

    private function reject(
        ApplyProviderSnapshot $snapshots,
        Generation $generation,
        PixelforgeException $e,
        string $message,
        bool $nothingWasCreated = true,
    ): void {
        Log::error('Pixelforge rejected a submission', [
            'generation' => $generation->uuid, 'code' => $e->errorCode, 'status' => $e->httpStatus, 'message' => $e->getMessage(),
        ]);

        $snapshots->failWithoutProvider($generation, $e->errorCode, $message, $nothingWasCreated);
    }

    /** Framework backstop: the job itself failed (retryUntil passed or repeated exceptions). */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        // We cannot tell here whether a create call went through, so make no promise about the charge.
        if ($generation !== null) {
            app(ApplyProviderSnapshot::class)
                ->failWithoutProvider($generation, 'submission_unconfirmed', self::MESSAGE_UNCONFIRMED, nothingWasCreated: false);
        }
    }
}
