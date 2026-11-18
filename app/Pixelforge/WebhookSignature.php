<?php

namespace App\Pixelforge;

/**
 * Verifies the `Pixelforge-Signature: t=<unix>,v1=<hex>` header.
 * The signature is HMAC-SHA256 of "<t>.<raw body>" with the key's webhook secret.
 */
final class WebhookSignature
{
    /** Never throws: any malformed input is simply an invalid signature. */
    public static function isValid(string $rawBody, ?string $header, ?string $secret, int $toleranceSeconds): bool
    {
        if ($header === null || $secret === null || $secret === '') {
            return false;
        }

        if (! preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/', trim($header), $parts)) {
            return false;
        }

        [$timestamp, $signature] = [(int) $parts[1], $parts[2]];

        // Reject replays of old deliveries (each real attempt is freshly signed).
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);   // constant-time compare
    }
}
