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

        // Read the header as key=value pairs, so spacing or an extra element does not reject a valid delivery.
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || strlen($timestamp) > 12 || $signatures === []) {
            return false;
        }

        // Reject replays of old deliveries (each real attempt is freshly signed).
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        // Sign the timestamp exactly as received.
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {   // constant-time compare
                return true;
            }
        }

        return false;
    }
}
