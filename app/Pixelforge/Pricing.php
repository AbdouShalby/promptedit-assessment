<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * The public price list from GET /v1/pricing, normalised once so the rest of
 * the app never touches the raw payload. Nothing here is hardcoded: rates,
 * sizes, qualities and token tables all come from the endpoint.
 */
final class Pricing
{
    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, decimal strings
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    private function __construct(
        public readonly array $rates,
        public readonly int $charactersPerTextToken,
        public readonly int $textTokenVarianceBps,
        public readonly int $tokensPerReferenceImage,
        public readonly array $outputTokens,
        public readonly array $reasoningTokens,
    ) {}

    /** @param  array<string, mixed>  $payload  the decoded GET /v1/pricing response */
    public static function fromApi(array $payload): self
    {
        $tokens = $payload['tokens'] ?? null;

        if (! is_array($payload['rates'] ?? null) || ! is_array($tokens)) {
            throw new InvalidArgumentException('Unexpected /v1/pricing payload.');
        }

        return new self(
            rates: array_map(fn ($rate) => (string) $rate, $payload['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVarianceBps: self::fractionToBps($tokens['text_token_variance']),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys(array_values($this->outputTokens)[0] ?? []);
    }

    /**
     * 0.15 -> 1500 basis points. The variance is the one JSON number in the
     * payload that is a fraction; it is turned into an integer here, through a
     * fixed 4-decimal string, so no float reaches the token or money maths.
     */
    private static function fractionToBps(int|float|string $fraction): int
    {
        $fixed = number_format((float) $fraction, 4, '.', '');   // "0.1500"

        return (int) str_replace('.', '', $fixed);                // "01500" -> 1500
    }
}
