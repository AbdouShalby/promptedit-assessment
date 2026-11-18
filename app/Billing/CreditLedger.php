<?php

namespace App\Billing;

use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Users' prepaid credit.
 *
 * Submitting a generation reserves its maximum estimated price; when it is
 * final the reserve is released and, on success, the actual price is charged.
 * Without the reserve, a user with $0.10 could start three $0.40 generations
 * at once.
 *
 * Every change is one ledger row plus the same amount on users.balance_nano,
 * in one transaction. A unique index on (generation_id, type) makes each step
 * idempotent: a second charge for the same generation inserts nothing.
 */
class CreditLedger
{
    /** Fake "Buy credits". Returns false when this idempotency key was already used. */
    public function purchase(User $user, int $amountNano, string $idempotencyKey): bool
    {
        return $this->record($user->id, null, CreditEntry::PURCHASE, $amountNano, $idempotencyKey);
    }

    /** Hold the most this generation can cost. The caller has checked the balance under a lock. */
    public function reserve(Generation $generation): void
    {
        $this->record($generation->user_id, $generation->id, CreditEntry::RESERVE, -$generation->estimated_max_charge_nano);
    }

    /** Give the hold back (any final state). Does nothing if nothing was held or it was already released. */
    public function release(Generation $generation): void
    {
        $reserved = CreditEntry::where('generation_id', $generation->id)
            ->where('type', CreditEntry::RESERVE)
            ->value('amount_nano');

        if ($reserved !== null) {
            $this->record($generation->user_id, $generation->id, CreditEntry::RELEASE, -(int) $reserved);
        }
    }

    /** Take the actual price of a succeeded generation, once. */
    public function charge(Generation $generation, int $chargeNano): void
    {
        $this->record($generation->user_id, $generation->id, CreditEntry::CHARGE, -$chargeNano);
    }

    private function record(int $userId, ?int $generationId, string $type, int $amountNano, ?string $idempotencyKey = null): bool
    {
        return DB::transaction(function () use ($userId, $generationId, $type, $amountNano, $idempotencyKey) {
            // ON CONFLICT DO NOTHING: a repeated entry is silently skipped...
            $inserted = CreditEntry::insertOrIgnore([
                'user_id' => $userId,
                'generation_id' => $generationId,
                'type' => $type,
                'amount_nano' => $amountNano,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            // ...and the balance moves only together with a new entry.
            User::whereKey($userId)->increment('balance_nano', $amountNano);

            return true;
        });
    }
}
