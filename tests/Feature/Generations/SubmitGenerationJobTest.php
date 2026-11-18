<?php

namespace Tests\Feature\Generations;

use App\Generations\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** How one pending generation gets into Pixelforge, and what happens at every documented refusal. */
class SubmitGenerationJobTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }

    /** @param  array<string, string>  $headers */
    private function fakeCreateError(string $code, int $status, array $headers = []): void
    {
        Http::fake([self::API.'/generations' => Http::response($this->errorBody($code), $status, $headers)]);
    }

    public function test_success_links_the_row_and_sends_its_uuid_as_idempotency_key_and_metadata(): void
    {
        $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);
        Http::fake([self::API.'/generations' => Http::response($this->snapshot('queued', ['id' => 'gen_new']), 202)]);

        $job = $this->runJob($generation);

        $job->assertNotReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_new', $generation->provider_id);
        $this->assertSame('5.00', $generation->rates['input_text_tokens']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
            && $request->data() === [
                'prompt' => 'a red fox',
                'size' => '1024x1024',
                'quality' => 'medium',
                'metadata' => ['local_id' => $generation->uuid],
                'reference_images' => ['https://example.com/a.png'],
            ]);
    }

    public function test_reference_images_are_left_out_of_the_request_when_there_are_none(): void
    {
        $generation = Generation::factory()->create();
        Http::fake([self::API.'/generations' => Http::response($this->snapshot('queued'), 202)]);

        $this->runJob($generation);

        Http::assertSent(fn (Request $request) => array_keys($request->data()) === ['prompt', 'size', 'quality', 'metadata']);
    }

    public function test_concurrency_limit_waits_for_retry_after_and_keeps_the_row_pending(): void
    {
        $generation = Generation::factory()->create();
        $this->fakeCreateError('concurrency_limit_exceeded', 429, ['Retry-After' => '5']);

        $job = $this->runJob($generation);

        $job->assertReleased(delay: 5);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(1, $generation->rate_limit_attempts);
        $this->assertSame(0, $generation->submit_attempts);
    }

    public function test_backoff_grows_and_is_never_shorter_than_retry_after(): void
    {
        $generation = Generation::factory()->create(['rate_limit_attempts' => 3]);
        $this->fakeCreateError('rate_limit_exceeded', 429, ['Retry-After' => '5']);

        $this->runJob($generation)->assertReleased(delay: 16);   // 2^4 = 16 > Retry-After 5
    }

    public function test_rate_limit_retries_are_capped_then_the_row_fails_as_not_charged(): void
    {
        $generation = Generation::factory()->create(['rate_limit_attempts' => 5]);
        $this->fakeCreateError('concurrency_limit_exceeded', 429, ['Retry-After' => '5']);

        $job = $this->runJob($generation);

        $job->assertNotReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submission_abandoned', $generation->error_code);
        $this->assertSame(SubmitGeneration::MESSAGE_BUSY, $generation->error_message);
        $this->assertSame(0, $generation->charge_nano);
    }

    public function test_overloaded_is_retried_with_backoff_and_counted(): void
    {
        $generation = Generation::factory()->create();
        $this->fakeCreateError('overloaded', 503, ['Retry-After' => '2']);

        $job = $this->runJob($generation);

        $job->assertReleased(delay: 2);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(1, $generation->submit_attempts);
        $this->assertFalse($generation->submit_outcome_unknown);
    }

    public function test_after_five_overloaded_attempts_the_row_fails_as_not_charged(): void
    {
        $generation = Generation::factory()->create(['submit_attempts' => 4]);
        $this->fakeCreateError('overloaded', 503, ['Retry-After' => '2']);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submission_abandoned', $generation->error_code);
        $this->assertStringContainsString('you were not charged', $generation->error_message);
    }

    /** After a timeout the generation may exist at Pixelforge, so we must not promise "not charged". */
    public function test_a_timeout_is_retried_and_giving_up_makes_no_promise_about_the_charge(): void
    {
        $generation = Generation::factory()->create(['submit_attempts' => 3]);
        Http::fake([self::API.'/generations' => fn () => throw new ConnectionException('timed out')]);

        $this->runJob($generation)->assertReleased(delay: 16);
        $this->assertTrue($generation->fresh()->submit_outcome_unknown);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('submission_unconfirmed', $generation->error_code);
        $this->assertSame(SubmitGeneration::MESSAGE_UNCONFIRMED, $generation->error_message);
        $this->assertStringNotContainsStringIgnoringCase('not charged', $generation->error_message);
    }

    public function test_content_policy_rejection_fails_immediately_with_a_clear_message(): void
    {
        $generation = Generation::factory()->create(['prompt' => 'something [blocked]']);
        $this->fakeCreateError('content_policy_violation', 400);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(SubmitGeneration::MESSAGE_POLICY, $generation->error_message);
        $this->assertSame(0, $generation->charge_nano);
        $this->assertNull($generation->provider_id);
    }

    public function test_daily_limit_fails_immediately_without_retry(): void
    {
        $generation = Generation::factory()->create();
        $this->fakeCreateError('daily_limit_exceeded', 429, ['Retry-After' => '3600']);

        $this->runJob($generation)->assertNotReleased();

        $this->assertSame(SubmitGeneration::MESSAGE_DAILY_LIMIT, $generation->fresh()->error_message);
        Http::assertSentCount(1);
    }

    public function test_our_own_mistakes_fail_the_row_without_retry(): void
    {
        foreach (['validation_failed' => 422, 'invalid_api_key' => 401] as $code => $status) {
            $this->resetHttpFakes();
            $this->fakeCreateError($code, $status);
            $generation = Generation::factory()->create();

            $this->runJob($generation)->assertNotReleased();

            $this->assertSame($code, $generation->fresh()->error_code);
            $this->assertSame(SubmitGeneration::MESSAGE_REJECTED, $generation->fresh()->error_message);
        }
    }

    /** When we already hold all 3 slots we wait without spending one of the 60 requests per minute. */
    public function test_the_local_gate_waits_without_calling_the_api(): void
    {
        Http::fake();
        Generation::factory()->count(3)->accepted(GenerationStatus::Processing)->create();
        $generation = Generation::factory()->create();

        $this->runJob($generation)->assertReleased(delay: 5);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Pending, $generation->fresh()->status);
        $this->assertSame(0, $generation->fresh()->rate_limit_attempts);
    }

    public function test_a_row_waiting_longer_than_the_deadline_is_abandoned_without_calling_the_api(): void
    {
        Http::fake();
        $generation = Generation::factory()->create(['created_at' => now()->subSeconds(601)]);

        $this->runJob($generation)->assertNotReleased();

        Http::assertNothingSent();
        $this->assertSame('submission_abandoned', $generation->fresh()->error_code);
    }

    public function test_a_row_that_is_no_longer_pending_is_left_alone(): void
    {
        Http::fake();
        $generation = Generation::factory()->accepted()->create();

        $this->runJob($generation)->assertNotReleased();

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
    }

    /** The job crashed after an unknown number of calls: again no promise about the charge. */
    public function test_the_failed_hook_marks_the_row_unconfirmed(): void
    {
        $generation = Generation::factory()->create();

        (new SubmitGeneration($generation->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame('submission_unconfirmed', $generation->fresh()->error_code);
    }
}
