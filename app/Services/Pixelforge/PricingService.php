<?php

namespace App\Services\Pixelforge;

use App\Billing\Nanodollars;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;

/**
 * Pixelforge's current rates and token-counting constants, from GET /v1/pricing.
 */
final readonly class PricingService
{
    /**
     * @param  array<string, int>  $ratesNanosPerMillion  Keyed by token type, e.g. "output_tokens".
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    public function __construct(
        public array $ratesNanosPerMillion,
        public int $charactersPerTextToken,
        public float $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float|int, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}}  $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            ratesNanosPerMillion: self::parseRates($payload['rates']),
            charactersPerTextToken: (int) $payload['tokens']['characters_per_text_token'],
            textTokenVariance: (float) $payload['tokens']['text_token_variance'],
            tokensPerReferenceImage: (int) $payload['tokens']['per_reference_image'],
            outputTokens: $payload['tokens']['output'],
            reasoningTokens: $payload['tokens']['reasoning'],
        );
    }

    /**
     * Convert decimal "USD per 1M tokens" strings into integer nanodollars per 1M tokens.
     *
     * @param  array<string, string>  $rates
     * @return array<string, int>
     */
    public static function parseRates(array $rates): array
    {
        return array_map(fn (string|int|float $rate): int => Nanodollars::fromDecimal((string) $rate), $rates);
    }

    public function outputTokensFor(GenerationQuality $quality, GenerationSize $size): int
    {
        return (int) $this->outputTokens[$quality->value][$size->value];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(GenerationQuality $quality): array
    {
        return [
            'min' => (int) $this->reasoningTokens[$quality->value]['min'],
            'max' => (int) $this->reasoningTokens[$quality->value]['max'],
        ];
    }
}
