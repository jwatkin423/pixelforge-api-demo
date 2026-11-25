<?php

namespace App\Billing;

/**
 * Get the range of cost a generation before it runs. Price for min and max
 * because Pixelforge only knows at the end of the generation
 */
final readonly class CostEstimate
{
    public function __construct(
        public int $minProviderCostNanos,
        public int $maxProviderCostNanos,
        public int $minChargeNanos,
        public int $maxChargeNanos,
        public int $textTokensMin,
        public int $textTokensMax,
        public int $imageTokens,
        public int $outputTokens,
        public int $reasoningTokensMin,
        public int $reasoningTokensMax,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'min_charge_nanos' => $this->minChargeNanos,
            'max_charge_nanos' => $this->maxChargeNanos,
            'text_tokens_min' => $this->textTokensMin,
            'text_tokens_max' => $this->textTokensMax,
            'image_tokens' => $this->imageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens_min' => $this->reasoningTokensMin,
            'reasoning_tokens_max' => $this->reasoningTokensMax,
        ];
    }
}
