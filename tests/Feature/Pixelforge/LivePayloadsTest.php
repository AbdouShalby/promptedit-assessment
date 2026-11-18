<?php

namespace Tests\Feature\Pixelforge;

use App\Generations\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/**
 * Payloads captured from the real Pixelforge API on 2026-11-18 (tests/Fixtures/live).
 * Only the image URL signature was removed. They pin the code to what the API
 * really sends, not to what the documentation says.
 */
class LivePayloadsTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    /** @return array<string, mixed> */
    private function live(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/live/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_real_webhooks_settle_a_generation_at_the_cost_pixelforge_billed(): void
    {
        $succeeded = $this->live('event-succeeded');
        $generation = Generation::factory()->create(['uuid' => $succeeded['data']['object']['metadata']['local_id']]);

        // The row is found through metadata.local_id: nothing linked it to the provider id yet.
        $this->deliverWebhook($this->live('event-processing'))->assertOk();
        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);

        $this->deliverWebhook($succeeded)->assertOk();
        $generation->refresh();

        // 5 text, 0 image, 1100 output, 351 reasoning tokens at 5.00 / 10.00 / 40.00 / 12.50 per 1M.
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('gen_01M7QW7QHRF0WFE52E7QYG1BTA', $generation->provider_id);
        $this->assertSame(48_412_500, $generation->provider_cost_nano);
        $this->assertSame(60_515_625, $generation->charge_nano);
        $this->assertSame('2026-11-18', $generation->billing_date->toDateString());
        $this->assertSame('2026-11-19T17:46:10+00:00', $generation->image_url_expires_at->toIso8601String());
    }

    public function test_a_real_failed_webhook_costs_nothing(): void
    {
        $failed = $this->live('event-failed');
        $generation = Generation::factory()->create(['uuid' => $failed['data']['object']['metadata']['local_id']]);

        $this->deliverWebhook($failed)->assertOk();
        $generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertSame(0, $generation->provider_cost_nano);
        $this->assertSame(0, $generation->charge_nano);
    }

    public function test_a_real_ping_is_stored_and_acknowledged(): void
    {
        // A ping carries an event id, and its data.object has no generation id.
        $this->deliverWebhook($this->live('event-ping'))->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

        $stored = WebhookEvent::sole();
        $this->assertSame('ping', $stored->type);
        $this->assertNull($stored->provider_generation_id);
    }

    public function test_the_report_reads_a_real_usage_response(): void
    {
        $this->withoutVite();
        Http::fake([self::API.'/usage*' => Http::response($this->live('usage'))]);
        $admin = User::factory()->create(['is_admin' => true]);

        // No local rows: the day Pixelforge billed must still be shown, as a mismatch.
        $this->actingAs($admin)->get('/admin/report?start_date=2026-11-17&end_date=2026-11-18')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('error', null)
                ->has('days', 1)                       // the zero day is not listed
                ->where('days.0.date', '2026-11-18')
                ->where('days.0.usage_cost', '1.449557500')
                ->where('days.0.matches_usage', false)
                ->where('days.0.difference', '-1.449557500')
                ->where('total.usage_cost', '1.449557500'));
    }
}
