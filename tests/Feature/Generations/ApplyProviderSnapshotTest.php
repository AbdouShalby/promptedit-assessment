<?php

namespace Tests\Feature\Generations;

use App\Generations\ApplyProviderSnapshot;
use App\Generations\GenerationStatus;
use App\Generations\IncompleteSnapshotException;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** The core money guarantee: whatever arrives, in whatever order, the cost is recorded exactly once. */
class ApplyProviderSnapshotTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    /** @param  array<string, mixed>  $snapshot */
    private function apply(Generation $generation, array $snapshot): Generation
    {
        return app(ApplyProviderSnapshot::class)->apply($generation, $snapshot);
    }

    public function test_the_normal_lifecycle_records_the_exact_cost(): void
    {
        $generation = Generation::factory()->create();
        $id = ['id' => 'gen_abc'];

        $this->assertSame(GenerationStatus::Queued, $this->apply($generation, $this->snapshot('queued', $id))->status);
        $this->assertSame(GenerationStatus::Processing, $this->apply($generation, $this->snapshot('processing', $id))->status);

        $row = $this->apply($generation, $this->succeededSnapshot($id));

        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_abc', $row->provider_id);
        $this->assertSame(self::DEFAULT_COST_NANO, $row->provider_cost_nano);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $row->charge_nano);
        $this->assertSame([3, 0, 1100, 800], [$row->input_text_tokens, $row->input_image_tokens, $row->output_tokens, $row->reasoning_tokens]);
        $this->assertNotNull($row->image_url);
        $this->assertSame('2026-11-19T10:00:20+00:00', $row->image_url_expires_at->toIso8601String());
        $this->assertSame('2026-11-18', $row->billing_date->toDateString());
    }

    public function test_the_same_succeeded_snapshot_twice_changes_nothing(): void
    {
        $generation = Generation::factory()->create();
        $snapshot = $this->succeededSnapshot();

        $first = $this->apply($generation, $snapshot)->getAttributes();

        $this->travel(5)->minutes();
        $second = $this->apply($generation, $snapshot)->getAttributes();

        $this->assertSame($first, $second);   // every column, including updated_at
    }

    public function test_a_late_processing_event_does_not_move_a_succeeded_generation_back(): void
    {
        $generation = Generation::factory()->create();
        $id = ['id' => 'gen_abc'];

        $this->apply($generation, $this->succeededSnapshot($id));
        $row = $this->apply($generation, $this->snapshot('processing', $id));

        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $row->charge_nano);
    }

    public function test_final_states_never_change_into_each_other(): void
    {
        $succeeded = Generation::factory()->create();
        $this->apply($succeeded, $this->succeededSnapshot(['id' => 'gen_a']));
        $row = $this->apply($succeeded, $this->failedSnapshot(['id' => 'gen_a']));
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $row->charge_nano);

        $failed = Generation::factory()->create();
        $this->apply($failed, $this->failedSnapshot(['id' => 'gen_b']));
        $row = $this->apply($failed, $this->succeededSnapshot(['id' => 'gen_b']));
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(0, $row->charge_nano);
    }

    /** A webhook can arrive before our own POST has returned: the row is still pending and unlinked. */
    public function test_a_pending_row_can_jump_straight_to_succeeded(): void
    {
        $generation = Generation::factory()->create();

        $row = $this->apply($generation, $this->succeededSnapshot(['id' => 'gen_early']));

        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_early', $row->provider_id);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $row->charge_nano);
    }

    public function test_a_failed_generation_costs_nothing(): void
    {
        $generation = Generation::factory()->create();

        $row = $this->apply($generation, $this->failedSnapshot(['error' => ['code' => 'internal_error', 'message' => 'boom']]));

        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(0, $row->provider_cost_nano);
        $this->assertSame(0, $row->charge_nano);
        $this->assertSame('internal_error', $row->error_code);
        $this->assertStringContainsString('You were not charged', $row->error_message);
        $this->assertNull($row->billing_date);
    }

    public function test_a_succeeded_snapshot_without_usage_throws_and_leaves_the_row_untouched(): void
    {
        $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create();
        $before = $generation->fresh()->getAttributes();

        try {
            $this->apply($generation, $this->succeededSnapshot(['id' => $generation->provider_id, 'usage' => null]));
            $this->fail('Expected IncompleteSnapshotException');
        } catch (IncompleteSnapshotException) {
            $this->assertSame($before, $generation->fresh()->getAttributes());
        }
    }

    /** Rates are fixed at creation: the bill uses the generation's own rates, not today's price list. */
    public function test_the_cost_uses_the_rates_on_the_snapshot(): void
    {
        $generation = Generation::factory()->create();
        $doubled = ['input_text_tokens' => '10.00', 'input_image_tokens' => '20.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00'];

        $row = $this->apply($generation, $this->succeededSnapshot(['rates' => $doubled]));

        $this->assertSame(self::DEFAULT_COST_NANO * 2, $row->provider_cost_nano);
        $this->assertSame($doubled, $row->rates);
    }

    public function test_the_markup_stored_on_the_row_is_used(): void
    {
        $generation = Generation::factory()->create(['markup_bps' => 1000]);   // created when markup was 10%

        $row = $this->apply($generation, $this->succeededSnapshot());

        $this->assertSame(59_416_500, $row->charge_nano);   // 54,015,000 x 1.10
    }

    /** Pixelforge bills on the UTC day of completion, not creation. */
    public function test_billing_date_is_the_utc_day_of_completion(): void
    {
        $beforeMidnight = Generation::factory()->create();
        $afterMidnight = Generation::factory()->create();

        $this->apply($beforeMidnight, $this->succeededSnapshot(['created_at' => '2026-11-18T23:59:00Z', 'completed_at' => '2026-11-18T23:59:59Z']));
        $this->apply($afterMidnight, $this->succeededSnapshot(['created_at' => '2026-11-18T23:59:00Z', 'completed_at' => '2026-11-19T00:00:01Z']));

        $this->assertSame('2026-11-18', $beforeMidnight->fresh()->billing_date->toDateString());
        $this->assertSame('2026-11-19', $afterMidnight->fresh()->billing_date->toDateString());
    }

    public function test_a_completion_time_with_an_offset_is_converted_to_utc_first(): void
    {
        $generation = Generation::factory()->create();

        $this->apply($generation, $this->succeededSnapshot(['completed_at' => '2026-11-19T01:30:00+02:00']));   // 23:30 UTC the day before

        $this->assertSame('2026-11-18', $generation->fresh()->billing_date->toDateString());
    }

    /**
     * We gave up on a submission (for example after timeouts), but Pixelforge had created it.
     * Its late snapshot must still be recorded, or our books would not match GET /v1/usage.
     */
    public function test_an_abandoned_row_is_claimed_by_a_late_snapshot(): void
    {
        $generation = Generation::factory()->create();
        app(ApplyProviderSnapshot::class)->failWithoutProvider($generation, 'submission_unconfirmed', 'We could not confirm...');
        $this->assertSame(GenerationStatus::Failed, $generation->fresh()->status);

        $row = $this->apply($generation, $this->succeededSnapshot(['id' => 'gen_late']));

        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_late', $row->provider_id);
        $this->assertSame(self::DEFAULT_CHARGE_NANO, $row->charge_nano);
        $this->assertNull($row->error_message);
    }

    public function test_a_snapshot_for_a_different_provider_generation_is_ignored(): void
    {
        $generation = Generation::factory()->accepted()->create();

        $row = $this->apply($generation, $this->succeededSnapshot(['id' => 'gen_someone_else']));

        $this->assertSame(GenerationStatus::Queued, $row->status);
        $this->assertNull($row->charge_nano);
    }

    public function test_only_a_pending_row_can_be_failed_locally(): void
    {
        $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create();

        $row = app(ApplyProviderSnapshot::class)->failWithoutProvider($generation, 'submission_abandoned', 'busy');

        $this->assertSame(GenerationStatus::Processing, $row->status);
    }
}
