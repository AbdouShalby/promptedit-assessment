<?php

namespace App\Jobs;

use App\Generations\ApplyProviderSnapshot;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Applies one stored webhook event to its generation. */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public string $eventId) {}

    public function handle(ApplyProviderSnapshot $snapshots): void
    {
        $event = WebhookEvent::where('event_id', $this->eventId)->first();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $snapshot = $event->payload['data']['object'] ?? null;
        $generation = is_array($snapshot) ? $this->findGeneration($snapshot) : null;

        if ($generation === null) {
            // Not ours (for example created outside this app): acknowledge and move on.
            Log::warning('Webhook for an unknown generation', ['event' => $event->event_id]);
        } else {
            $snapshots->apply($generation, $snapshot);
        }

        $event->update(['processed_at' => now()]);
    }

    /**
     * By provider id, or, when the webhook beat the response of our own POST
     * and the row is not linked yet, by the UUID we sent in metadata.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function findGeneration(array $snapshot): ?Generation
    {
        $providerId = $snapshot['id'] ?? null;
        $localId = $snapshot['metadata']['local_id'] ?? null;

        if (is_string($providerId) && ($found = Generation::where('provider_id', $providerId)->first())) {
            return $found;
        }

        return is_string($localId) && Str::isUuid($localId)
            ? Generation::where('uuid', $localId)->first()
            : null;
    }
}
