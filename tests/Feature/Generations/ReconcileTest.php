<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** The polling fallback: webhooks are not guaranteed, jobs can be lost. */
class ReconcileTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    public function test_stale_accepted_generations_are_polled_and_fresh_ones_are_not(): void
    {
        Queue::fake();
        $stale = Generation::factory()->accepted(GenerationStatus::Processing)->create(['last_checked_at' => now()->subSeconds(30)]);
        $fresh = Generation::factory()->accepted(GenerationStatus::Processing)->create(['last_checked_at' => now()->subSeconds(5)]);

        $this->artisan('generations:reconcile')->assertSuccessful();

        Queue::assertPushed(SyncGeneration::class, 1);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $job) => $job->generationId === $stale->id);
        Queue::assertNotPushed(SubmitGeneration::class);
        $this->assertTrue($fresh->fresh()->last_checked_at->lt(now()));
    }

    public function test_a_second_run_does_not_queue_the_same_row_again(): void
    {
        Queue::fake();
        Generation::factory()->accepted()->create(['last_checked_at' => now()->subSeconds(30)]);

        $this->artisan('generations:reconcile');
        $this->artisan('generations:reconcile');

        Queue::assertPushed(SyncGeneration::class, 1);
    }

    public function test_a_pending_row_with_no_live_job_is_resubmitted(): void
    {
        Queue::fake();
        $lost = Generation::factory()->create(['created_at' => now()->subSeconds(120)]);
        $waiting = Generation::factory()->create(['created_at' => now()->subSeconds(120), 'last_checked_at' => now()->subSeconds(10)]);
        $new = Generation::factory()->create();

        $this->artisan('generations:reconcile');

        Queue::assertPushed(SubmitGeneration::class, 1);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $lost->id);
    }

    public function test_final_rows_are_never_touched(): void
    {
        Queue::fake();
        Generation::factory()->accepted(GenerationStatus::Succeeded)->create(['last_checked_at' => now()->subDay()]);
        Generation::factory()->accepted(GenerationStatus::Failed)->create(['last_checked_at' => now()->subDay()]);
        Generation::factory()->create(['status' => GenerationStatus::Failed, 'created_at' => now()->subDay()]);

        $this->artisan('generations:reconcile');

        Queue::assertNothingPushed();
    }

    public function test_sync_settles_a_generation_whose_webhook_never_arrived(): void
    {
        $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create();
        Http::fake([self::API.'/generations/'.$generation->provider_id => Http::response($this->succeededSnapshot(['id' => $generation->provider_id]))]);

        SyncGeneration::dispatchSync($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $generation->charge_nano);
    }

    public function test_sync_changes_nothing_when_pixelforge_is_overloaded(): void
    {
        $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create();
        Http::fake([self::API.'/generations/*' => Http::response($this->errorBody('overloaded'), 503)]);

        SyncGeneration::dispatchSync($generation->id);

        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
        $this->assertNull($generation->fresh()->charge_nano);
    }

    public function test_sync_skips_final_and_unlinked_rows_without_calling_the_api(): void
    {
        Http::fake();

        SyncGeneration::dispatchSync(Generation::factory()->accepted(GenerationStatus::Succeeded)->create()->id);
        SyncGeneration::dispatchSync(Generation::factory()->create()->id);

        Http::assertNothingSent();
    }

    public function test_the_reconciler_is_scheduled_every_fifteen_seconds(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'generations:reconcile'));

        $this->assertNotNull($event);
        $this->assertSame(15, $event->repeatSeconds);
    }
}
