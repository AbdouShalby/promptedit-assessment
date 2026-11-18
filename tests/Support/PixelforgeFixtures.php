<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Payloads shaped exactly like the Pixelforge docs. pricing.json is a real
 * capture of GET /v1/pricing; everything else is built from the documentation.
 */
trait PixelforgeFixtures
{
    protected const API = 'pixelforge.promptedit.dev/v1';

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    // Default usage of succeededSnapshot(): 3 text, 0 image, 1100 output, 800 reasoning tokens.
    protected const DEFAULT_COST_NANO = 54_015_000;      // 3x5 + 1100x40 + 800x12.5 = 54015 micro-dollars

    protected const DEFAULT_CHARGE_NANO = 67_518_750;    // cost x 1.25

    /** @return array<string, mixed> */
    protected function pricingPayload(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
    }

    protected function fakePricing(): void
    {
        Http::fake([self::API.'/pricing' => Http::response($this->pricingPayload())]);
    }

    /** Forget earlier Http::fake() stubs so a test can change what Pixelforge answers. */
    protected function resetHttpFakes(): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
    }

    /**
     * A Pixelforge generation object.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function snapshot(string $status, array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen_'.Str::random(24),
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'a red fox',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => $this->pricingPayload()['rates'],
            'error' => null,
            'created_at' => '2026-11-18T10:00:00Z',
            'started_at' => $status === 'queued' ? null : '2026-11-18T10:00:03Z',
            'completed_at' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function succeededSnapshot(array $overrides = []): array
    {
        return $this->snapshot('succeeded', array_merge([
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png?signature=abc',
            'image_url_expires_at' => '2026-11-19T10:00:20Z',
            'usage' => [
                'input_text_tokens' => 3,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 800,
                'total_tokens' => 1903,
            ],
            'completed_at' => '2026-11-18T10:00:20Z',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function failedSnapshot(array $overrides = []): array
    {
        return $this->snapshot('failed', array_merge([
            'error' => ['code' => 'generation_failed', 'message' => 'The generation failed.'],
            'completed_at' => '2026-11-18T10:00:20Z',
        ], $overrides));
    }

    /**
     * A Pixelforge error response body.
     *
     * @return array<string, mixed>
     */
    protected function errorBody(string $code, string $type = 'invalid_request_error'): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => "Test error: {$code}", 'param' => null, 'errors' => null]];
    }

    /**
     * @param  array<string, mixed>  $generation
     * @return array<string, mixed>
     */
    protected function event(string $type, array $generation, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-18T10:00:21Z',
            'data' => ['object' => $generation],
        ];
    }

    /**
     * Deliver a webhook the way Pixelforge does: raw JSON body signed with HMAC-SHA256.
     *
     * @param  array<string, mixed>  $event
     */
    protected function deliverWebhook(array $event, string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null, ?string $signatureHeader = null): TestResponse
    {
        $body = json_encode($event);
        $timestamp ??= time();
        $signatureHeader ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', '/api/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signatureHeader,
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
            'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'] ?? '',
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        ], content: $body);
    }
}
