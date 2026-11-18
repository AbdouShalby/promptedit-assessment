<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge webhooks. Does the minimum inside the request
 * (verify, store, queue) so we always answer well within the 10 second limit.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Verify on the raw bytes, before any JSON decoding.
        $rawBody = $request->getContent();

        $valid = WebhookSignature::isValid(
            $rawBody,
            $request->header('Pixelforge-Signature'),
            config('pixelforge.webhook_secret'),
            config('pixelforge.webhook_tolerance'),
        );

        if (! $valid) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['error' => 'malformed event'], 400);
        }

        // The event row and its job are committed together (database queue),
        // so we never have one without the other.
        $isNew = DB::transaction(function () use ($event, $rawBody, $request) {
            // The unique index on event_id is the dedupe: a redelivery inserts nothing.
            $inserted = WebhookEvent::insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'provider_generation_id' => $event['data']['object']['id'] ?? null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => $rawBody,
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            // "ping" and unknown event types are stored and acknowledged, nothing more.
            if (str_starts_with($event['type'], 'generation.')) {
                ProcessWebhookEvent::dispatch($event['id']);
            }

            return true;
        });

        return response()->json(['received' => true, 'duplicate' => ! $isNew]);
    }
}
