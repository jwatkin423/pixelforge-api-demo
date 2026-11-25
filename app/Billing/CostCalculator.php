<?php

namespace App\Billing;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pixelforge\PricingService;

/**
 * Every cost here is integer nanodollars. Rates are "nanodollars per 1M tokens", so a
 * component costs tokens × rate ÷ 1,000,000. We sum the numerators first and divide
 * once, rounding up, so the only rounding is a single sub-nanodollar step.
 */
class CostCalculator
{
    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private readonly int $markupPercent) {}

    /**
     * Pixelforge's cost for a finished generation, from its reported usage and the rates
     * it locked in when the generation was created.
     *
     * @param  array<string, int>  $usage  e.g. ['input_text_tokens' => 12, ...]
     * @param  array<string, int>  $ratesNanosPerMillion
     */
    public function providerCost(array $usage, array $ratesNanosPerMillion): int
    {
        $numerator = 0;

        foreach ($ratesNanosPerMillion as $tokenType => $rate) {
            $numerator += ((int) ($usage[$tokenType] ?? 0)) * $rate;
        }

        return Nanodollars::divideRoundUp($numerator, self::TOKENS_PER_RATE_UNIT);
    }

    /**
     * What the user pays for a given Pixelforge cost: cost plus markup, rounded up.
     */
    public function chargeFor(int $providerCostNanos): int
    {
        return Nanodollars::divideRoundUp($providerCostNanos * (100 + $this->markupPercent), 100);
    }

    public function estimate(
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        int $referenceImageCount,
        PricingService $pricing,
    ): CostEstimate {

        // estimate text tokens from the prompt length
        $baseTextTokens = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        // estimate the min tokens amount
        $textTokensMin = (int) floor($baseTextTokens * (1 - $pricing->textTokenVariance));
        // estimate the max tokens amount
        $textTokensMax = (int) ceil($baseTextTokens * (1 + $pricing->textTokenVariance));
        // image tokens
        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        // output tokens
        $outputTokens = $pricing->outputTokensFor($quality, $size);
        $reasoning = $pricing->reasoningRangeFor($quality);

        $minCost = $this->providerCost([
            'input_text_tokens' => $textTokensMin,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['min'],
        ], $pricing->ratesNanosPerMillion);

        $maxCost = $this->providerCost([
            'input_text_tokens' => $textTokensMax,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoning['max'],
        ], $pricing->ratesNanosPerMillion);

        return new CostEstimate(
            minProviderCostNanos: $minCost,
            maxProviderCostNanos: $maxCost,
            minChargeNanos: $this->chargeFor($minCost),
            maxChargeNanos: $this->chargeFor($maxCost),
            textTokensMin: $textTokensMin,
            textTokensMax: $textTokensMax,
            imageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokensMin: $reasoning['min'],
            reasoningTokensMax: $reasoning['max'],
        );
    }
}
