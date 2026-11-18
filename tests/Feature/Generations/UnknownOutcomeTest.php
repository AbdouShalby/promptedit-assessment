<?php

namespace Tests\Feature\Generations;

use App\Billing\CostCalculator;
use App\Generations\ApplyProviderSnapshot;
use App\Generations\GenerationStatus;
use App\Generations\IncompleteSnapshotException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** Edge cases found in review: never promise "not charged", never guess a cost. */
class UnknownOutcomeTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    /** An earlier call timed out, so a later clean rejection still cannot prove nothing was created. */
    public function test_a_clean_rejection_after_a_timeout_makes_no_promise_about_the_charge(): void
    {
        $generation = Generation::factory()->create(['submit_outcome_unknown' => true]);
        Http::fake([self::API.'/generations' => Http::response($this->errorBody('daily_limit_exceeded'), 429)]);

        $this->runJob($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertNull($generation->charge_nano);
        $this->assertSame(SubmitGeneration::MESSAGE_UNCONFIRMED, $generation->error_message);
    }

    /** The flag is saved before the call, so a worker that dies mid-call leaves the row marked unknown. */
    public function test_the_outcome_is_marked_unknown_before_the_call_and_cleared_by_a_clear_rejection(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::API.'/generations' => function () use ($generation) {
            $this->assertTrue($generation->fresh()->submit_outcome_unknown);

            return Http::response($this->errorBody('overloaded'), 503);
        }]);

        $this->runJob($generation);

        $this->assertFalse($generation->fresh()->submit_outcome_unknown);
    }

    public function test_an_accepted_response_we_cannot_read_is_treated_like_a_timeout(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::API.'/generations' => Http::response('', 202)]);

        $this->runJob($generation)->assertReleased(delay: 2);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertTrue($generation->submit_outcome_unknown);
    }

    public function test_a_429_without_an_error_code_is_retried_not_rejected(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::API.'/generations' => Http::response('', 429, ['Retry-After' => '7'])]);

        $this->runJob($generation)->assertReleased(delay: 7);

        $this->assertSame(GenerationStatus::Pending, $generation->fresh()->status);
    }

    public function test_token_counts_that_are_missing_or_not_whole_numbers_are_refused(): void
    {
        $rates = $this->pricingPayload()['rates'];
        $usage = ['input_text_tokens' => 3, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 800];

        foreach ([null, '12.5', 12.5, -1] as $bad) {
            try {
                CostCalculator::providerCost(['reasoning_tokens' => $bad] + $usage, $rates);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_succeeded_snapshot_without_a_completion_time_is_not_settled(): void
    {
        $generation = Generation::factory()->accepted(GenerationStatus::Processing)->create();

        $this->expectException(IncompleteSnapshotException::class);

        app(ApplyProviderSnapshot::class)->apply($generation, $this->succeededSnapshot(['id' => $generation->provider_id, 'completed_at' => null]));
    }

    public function test_signature_header_spacing_case_and_extra_elements_are_tolerated(): void
    {
        $body = '{"id":"evt_1"}';
        $t = (string) time();
        $good = hash_hmac('sha256', $t.'.'.$body, 'whsec_x');

        $this->assertTrue(WebhookSignature::isValid($body, "t={$t}, v1=".strtoupper($good), 'whsec_x', 300));
        $this->assertTrue(WebhookSignature::isValid($body, "t={$t},v1=".str_repeat('0', 64).",v1={$good},v0=abc", 'whsec_x', 300));
        $this->assertFalse(WebhookSignature::isValid($body, "t={$t},v1=".str_repeat('0', 64), 'whsec_x', 300));
    }
}
