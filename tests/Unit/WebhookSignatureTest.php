<?php

namespace Tests\Unit;

use App\Pixelforge\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const BODY = '{"id":"evt_1","type":"ping"}';

    private function header(string $body = self::BODY, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function test_a_correctly_signed_body_is_valid(): void
    {
        $this->assertTrue(WebhookSignature::isValid(self::BODY, $this->header(), self::SECRET, 300));
    }

    public function test_a_wrong_secret_is_invalid(): void
    {
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $this->header(secret: 'whsec_other'), self::SECRET, 300));
    }

    public function test_a_tampered_body_is_invalid(): void
    {
        $this->assertFalse(WebhookSignature::isValid(self::BODY.' ', $this->header(), self::SECRET, 300));
    }

    public function test_old_and_future_timestamps_are_invalid_even_when_signed_correctly(): void
    {
        $this->assertTrue(WebhookSignature::isValid(self::BODY, $this->header(timestamp: time() - 295), self::SECRET, 300));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $this->header(timestamp: time() - 301), self::SECRET, 300));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $this->header(timestamp: time() + 301), self::SECRET, 300));
    }

    public function test_missing_or_malformed_headers_are_invalid_not_errors(): void
    {
        foreach ([null, '', 'garbage', 't=abc,v1=def', 'v1='.str_repeat('a', 64), 't='.time()] as $header) {
            $this->assertFalse(WebhookSignature::isValid(self::BODY, $header, self::SECRET, 300));
        }
    }

    public function test_an_unconfigured_secret_never_validates(): void
    {
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $this->header(secret: ''), '', 300));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $this->header(), null, 300));
    }
}
