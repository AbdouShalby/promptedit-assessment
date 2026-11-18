<?php

namespace App\Console\Commands;

use App\Generations\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

/**
 * Safety net, run every 15 seconds by the scheduler.
 *
 *  - Accepted generations with no news for a while are polled (lost webhook).
 *  - Pending generations with no live job are re-submitted (lost job). This is
 *    safe because the submit job checks the status and uses an idempotency key.
 *
 * At most 3 generations are in flight at Pixelforge, so polling costs about
 * 12 requests a minute against a limit of 60.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile';

    protected $description = 'Poll Pixelforge for generations whose webhooks are late, and re-queue stuck submissions';

    public function handle(): int
    {
        $staleSync = now()->subSeconds(config('pixelforge.stale_after_seconds'));
        $stalePending = now()->subSeconds(config('pixelforge.stale_pending_after_seconds'));

        $toSync = Generation::holdingProviderSlot()
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $staleSync))
            ->get();

        $toSubmit = Generation::where('status', GenerationStatus::Pending)
            ->where('created_at', '<', $stalePending)
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $stalePending))
            ->get();

        foreach ($toSync as $generation) {
            // Stamp first, so a slow worker does not get the same row queued on every run.
            $generation->update(['last_checked_at' => now()]);
            SyncGeneration::dispatch($generation->id);
        }

        foreach ($toSubmit as $generation) {
            $generation->update(['last_checked_at' => now()]);
            SubmitGeneration::dispatch($generation->id);
        }

        $this->info("Queued {$toSync->count()} sync(s) and {$toSubmit->count()} re-submission(s).");

        return self::SUCCESS;
    }
}
