<?php

namespace App\Jobs;

use App\Generations\ApplyProviderSnapshot;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Polling fallback: webhook delivery is not guaranteed, so fetch the
 * generation ourselves and feed it to the same function the webhooks use.
 */
class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $generationId) {}

    public function handle(PixelforgeClient $pixelforge, ApplyProviderSnapshot $snapshots): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->provider_id === null || $generation->status->isFinal()) {
            return;
        }

        try {
            $snapshot = $pixelforge->getGeneration($generation->provider_id);
        } catch (PixelforgeException $e) {
            // Rate limited, overloaded or unreachable: the next reconcile run tries again.
            Log::info('Could not sync generation', ['generation' => $generation->uuid, 'code' => $e->errorCode]);

            return;
        }

        $snapshots->apply($generation, $snapshot);
    }
}
