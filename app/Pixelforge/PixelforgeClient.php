<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/** Thin wrapper around the Pixelforge HTTP API. Returns decoded arrays, throws PixelforgeException. */
class PixelforgeClient
{
    private const PRICING_CACHE_KEY = 'pixelforge.pricing';

    private const PRICING_LAST_GOOD_KEY = 'pixelforge.pricing.last_good';

    /**
     * Price list for estimates. Cached briefly; if the endpoint is down we fall
     * back to the last copy that worked. Actual costs never use this: they use
     * the rates fixed on each generation.
     */
    public function pricing(): Pricing
    {
        $cached = Cache::get(self::PRICING_CACHE_KEY);

        if (is_array($cached)) {
            return Pricing::fromApi($cached);
        }

        try {
            $payload = $this->send(fn (PendingRequest $http) => $http->get('/pricing'), authenticated: false);
            $pricing = Pricing::fromApi($payload);
        } catch (PixelforgeException|InvalidArgumentException) {
            $lastGood = Cache::get(self::PRICING_LAST_GOOD_KEY);

            if (is_array($lastGood)) {
                return Pricing::fromApi($lastGood);
            }

            throw new PixelforgeException('pricing_unavailable', message: 'Pixelforge pricing is unavailable.');
        }

        Cache::put(self::PRICING_CACHE_KEY, $payload, config('pixelforge.pricing_cache_seconds'));
        Cache::forever(self::PRICING_LAST_GOOD_KEY, $payload);

        return $pricing;
    }

    /**
     * POST /generations. The same $idempotencyKey must always carry the same
     * payload, so a retry returns the existing generation instead of a new one.
     *
     * @param  array<string, mixed>  $payload  only prompt, size, quality, reference_images, metadata
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        $generation = $this->send(
            fn (PendingRequest $http) => $http
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $payload)
        );

        if (! isset($generation['id'], $generation['status'])) {
            // Accepted, but we cannot read what was created: treat like a timeout (outcome unknown).
            throw new PixelforgeException('unreadable_response', message: 'Create returned no generation object.');
        }

        return $generation;
    }

    /** @return array<string, mixed> the generation object */
    public function getGeneration(string $providerId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/generations/'.$providerId));
    }

    /**
     * GET /usage: what Pixelforge billed this key per UTC day (source of truth for provider cost).
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, bool $authenticated = true): array
    {
        $http = Http::baseUrl(config('pixelforge.base_url'))
            ->timeout(config('pixelforge.http_timeout'))
            ->acceptJson();

        if ($authenticated) {
            $http = $http->withToken((string) config('pixelforge.api_key'));
        }

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            // Timeout or network failure: no status, so the outcome is unknown.
            throw new PixelforgeException('connection_error', message: $e->getMessage());
        }

        if ($response->failed()) {
            $retryAfter = $response->header('Retry-After');

            throw new PixelforgeException(
                errorCode: (string) ($response->json('error.code') ?? 'http_'.$response->status()),
                httpStatus: $response->status(),
                retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
                message: (string) ($response->json('error.message') ?? ''),
            );
        }

        return $response->json() ?? [];
    }
}
