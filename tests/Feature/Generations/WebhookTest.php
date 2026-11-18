<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    public function test_a_valid_webhook_is_stored_once_and_queued_once(): void
    {
        Queue::fake();
        $event = $this->event('generation.processing', $this->snapshot('processing', ['id' => 'gen_abc']), 'evt_1');

        $this->deliverWebhook($event)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

        $stored = WebhookEvent::sole();
        $this->assertSame('evt_1', $stored->event_id);
        $this->assertSame('generation.processing', $stored->type);
        $this->assertSame('gen_abc', $stored->provider_generation_id);
        $this->assertSame(1, $stored->delivery_attempt);
        $this->assertSame($event, $stored->payload);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_invalid_signatures_are_rejected_and_nothing_is_stored(): void
    {
        Queue::fake();
        $event = $this->event('generation.succeeded', $this->succeededSnapshot());
        $body = json_encode($event);
        $now = time();

        $this->deliverWebhook($event, secret: 'whsec_wrong')->assertUnauthorized();
        $this->deliverWebhook($event, timestamp: $now - 301)->assertUnauthorized();
        $this->deliverWebhook($event, signatureHeader: 'not-a-signature')->assertUnauthorized();
        // Signature computed for a different body (tampering).
        $this->deliverWebhook($event, signatureHeader: 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$body.'x', self::WEBHOOK_SECRET))->assertUnauthorized();
        // No signature header at all.
        $this->call('POST', '/api/webhooks/pixelforge', content: $body)->assertUnauthorized();

        $this->assertSame(0, WebhookEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_the_same_event_delivered_twice_is_acknowledged_twice_but_recorded_once(): void
    {
        Queue::fake();
        $event = $this->event('generation.succeeded', $this->succeededSnapshot(), 'evt_dup');

        $this->deliverWebhook($event)->assertOk()->assertJson(['duplicate' => false]);
        $this->deliverWebhook($event)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_the_route_needs_no_session_and_no_csrf_token(): void
    {
        $response = $this->deliverWebhook($this->event('ping', []));

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies());   // no session cookie: not the web middleware group
    }

    public function test_ping_and_unknown_event_types_are_acknowledged_without_work(): void
    {
        Queue::fake();

        $this->deliverWebhook($this->event('ping', []))->assertOk();
        $this->deliverWebhook($this->event('generation_v2.something_new', []))->assertOk();
        $this->deliverWebhook($this->event('account.updated', ['id' => 'acct_1']))->assertOk();

        $this->assertSame(3, WebhookEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_a_signed_but_malformed_body_is_a_400(): void
    {
        $body = 'this is not json';
        $now = time();

        $this->call('POST', '/api/webhooks/pixelforge', server: [
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$body, self::WEBHOOK_SECRET),
        ], content: $body)->assertStatus(400);
    }

    /** End to end (the test queue runs jobs immediately): a succeeded webhook settles the generation. */
    public function test_a_succeeded_webhook_settles_the_generation(): void
    {
        $generation = Generation::factory()->accepted()->create();

        $this->deliverWebhook($this->event('generation.succeeded', $this->succeededSnapshot(['id' => $generation->provider_id])))->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $generation->charge_nano);
        $this->assertNotNull(WebhookEvent::sole()->processed_at);
    }

    /** At-least-once, any order: succeeded first, then a retried "processing", then succeeded again as a new event. */
    public function test_out_of_order_and_repeated_events_end_in_the_right_state_with_one_cost(): void
    {
        $generation = Generation::factory()->accepted()->create();
        $id = ['id' => $generation->provider_id];

        $this->deliverWebhook($this->event('generation.succeeded', $this->succeededSnapshot($id), 'evt_s'))->assertOk();
        $settled = $generation->fresh()->getAttributes();

        $this->deliverWebhook($this->event('generation.processing', $this->snapshot('processing', $id), 'evt_p'))->assertOk();
        $this->deliverWebhook($this->event('generation.succeeded', $this->succeededSnapshot($id), 'evt_s'))->assertOk();       // duplicate id
        $this->deliverWebhook($this->event('generation.succeeded', $this->succeededSnapshot($id), 'evt_s_again'))->assertOk(); // same content, new id

        $this->assertSame($settled, $generation->fresh()->getAttributes());
        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertSame(3, WebhookEvent::count());
    }

    /** The webhook beat the response of our own POST: the row has no provider id yet. */
    public function test_an_event_finds_an_unlinked_row_through_the_metadata_uuid(): void
    {
        $generation = Generation::factory()->create();
        $snapshot = $this->snapshot('processing', ['id' => 'gen_fast', 'metadata' => ['local_id' => $generation->uuid]]);

        $this->deliverWebhook($this->event('generation.processing', $snapshot))->assertOk();

        $generation->refresh();
        $this->assertSame('gen_fast', $generation->provider_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_a_failed_webhook_records_no_cost(): void
    {
        $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create();

        $this->deliverWebhook($this->event('generation.failed', $this->failedSnapshot(['id' => $generation->provider_id])))->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge_nano);
        $this->assertSame(0, $generation->provider_cost_nano);
    }

    public function test_an_event_for_an_unknown_generation_is_acknowledged_and_changes_nothing(): void
    {
        $other = Generation::factory()->accepted()->create();
        $snapshot = $this->succeededSnapshot(['id' => 'gen_not_ours', 'metadata' => ['local_id' => 'not-a-uuid']]);

        $this->deliverWebhook($this->event('generation.succeeded', $snapshot))->assertOk();

        $this->assertNotNull(WebhookEvent::sole()->processed_at);
        $this->assertSame(GenerationStatus::Queued, $other->fresh()->status);
    }
}
