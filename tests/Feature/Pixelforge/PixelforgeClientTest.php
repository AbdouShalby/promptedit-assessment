<?php

namespace Tests\Feature\Pixelforge;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    use PixelforgeFixtures;

    private function client(): PixelforgeClient
    {
        return app(PixelforgeClient::class);
    }

    public function test_create_sends_the_bearer_key_the_idempotency_key_and_only_the_given_params(): void
    {
        Http::fake([self::API.'/generations' => Http::response($this->snapshot('queued'), 202)]);

        $payload = ['prompt' => 'a red fox', 'size' => '1024x1024', 'quality' => 'medium', 'metadata' => ['local_id' => 'abc']];
        $generation = $this->client()->createGeneration($payload, 'key-123');

        $this->assertSame('queued', $generation['status']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://'.self::API.'/generations'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', 'key-123')
            && $request->data() === $payload);
    }

    public function test_get_generation_and_usage_hit_the_documented_urls(): void
    {
        Http::fake([
            self::API.'/generations/gen_abc' => Http::response($this->snapshot('processing', ['id' => 'gen_abc'])),
            self::API.'/usage*' => Http::response(['object' => 'usage', 'data' => [], 'total' => ['cost_usd' => '0.000000000']]),
        ]);

        $this->assertSame('gen_abc', $this->client()->getGeneration('gen_abc')['id']);
        $this->assertSame('0.000000000', $this->client()->usage('2026-11-01', '2026-11-18')['total']['cost_usd']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://'.self::API.'/usage?start_date=2026-11-01&end_date=2026-11-18');
    }

    /** @return array<string, array{int, string, bool}> */
    public static function documentedErrors(): array
    {
        return [
            // code => [http status, code, ambiguous?]
            'invalid_api_key' => [401, 'invalid_api_key', false],
            'validation_failed' => [422, 'validation_failed', false],
            'content_policy_violation' => [400, 'content_policy_violation', false],
            'not_found' => [404, 'not_found', false],
            'idempotency_key_reused' => [409, 'idempotency_key_reused', false],
            'rate_limit_exceeded' => [429, 'rate_limit_exceeded', false],
            'concurrency_limit_exceeded' => [429, 'concurrency_limit_exceeded', false],
            'daily_limit_exceeded' => [429, 'daily_limit_exceeded', false],
            'overloaded' => [503, 'overloaded', false],
        ];
    }

    #[DataProvider('documentedErrors')]
    public function test_documented_errors_are_mapped_by_their_code(int $status, string $code, bool $ambiguous): void
    {
        Http::fake([self::API.'/generations' => Http::response($this->errorBody($code), $status, ['Retry-After' => '5'])]);

        try {
            $this->client()->createGeneration(['prompt' => 'x'], 'key');
            $this->fail('Expected a PixelforgeException');
        } catch (PixelforgeException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame(5, $e->retryAfter);
            $this->assertSame($ambiguous, $e->isAmbiguous());
        }
    }

    public function test_a_missing_retry_after_header_is_null(): void
    {
        Http::fake([self::API.'/generations' => Http::response($this->errorBody('overloaded'), 503)]);

        try {
            $this->client()->createGeneration(['prompt' => 'x'], 'key');
            $this->fail('Expected a PixelforgeException');
        } catch (PixelforgeException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    public function test_a_timeout_or_an_unknown_5xx_is_ambiguous(): void
    {
        Http::fake([self::API.'/generations' => fn () => throw new ConnectionException('timed out')]);

        try {
            $this->client()->createGeneration(['prompt' => 'x'], 'key');
            $this->fail('Expected a PixelforgeException');
        } catch (PixelforgeException $e) {
            $this->assertSame('connection_error', $e->errorCode);
            $this->assertNull($e->httpStatus);
            $this->assertTrue($e->isAmbiguous());
        }

        $unknown = new PixelforgeException('http_502', 502);
        $this->assertTrue($unknown->isAmbiguous());
    }

    public function test_pricing_needs_no_key_and_is_cached(): void
    {
        $this->fakePricing();

        $this->assertSame('5.00', $this->client()->pricing()->rates['input_text_tokens']);
        $this->client()->pricing();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_pricing_falls_back_to_the_last_good_copy_when_the_endpoint_is_down(): void
    {
        $this->fakePricing();
        $this->client()->pricing();

        Cache::forget('pixelforge.pricing');   // the 5 minute cache expired
        $this->resetHttpFakes();
        Http::fake([self::API.'/pricing' => Http::response($this->errorBody('overloaded'), 503)]);

        $this->assertSame('5.00', $this->client()->pricing()->rates['input_text_tokens']);
    }

    public function test_pricing_unavailable_without_any_copy_throws(): void
    {
        Http::fake([self::API.'/pricing' => Http::response($this->errorBody('overloaded'), 503)]);

        $this->expectException(PixelforgeException::class);

        $this->client()->pricing();
    }
}
