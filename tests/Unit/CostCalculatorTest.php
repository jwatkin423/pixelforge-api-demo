<?php

use App\Billing\CostCalculator;
use App\Billing\Nanodollars;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pixelforge\PricingService;

test('provider cost matches the documented usage example exactly', function () {
    $cost = (new CostCalculator(markupPercent: 25))->providerCost(
        ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184],
        PricingService::parseRates(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']),
    );

    expect(Nanodollars::toDecimal($cost))->toBe('0.305510000');
});

test('the maximum estimate matches the documented worked example', function () {
    $estimate = (new CostCalculator(markupPercent: 25))->estimate(
        str_repeat('a', 400),
        GenerationSize::Landscape,
        GenerationQuality::High,
        2,
        PricingService::fromApi(pixelforgePricing()),
    );

    expect($estimate->textTokensMax)->toBe(115)
        ->and($estimate->imageTokens)->toBe(1530)
        ->and($estimate->outputTokens)->toBe(6450)
        ->and($estimate->reasoningTokensMax)->toBe(8000)
        ->and(Nanodollars::toDecimal($estimate->maxProviderCostNanos))->toBe('0.373875000')
        ->and(Nanodollars::toDecimal($estimate->maxChargeNanos))->toBe('0.467343750');
});

test('the minimum estimate uses the lower text variance and reasoning bound', function () {
    $estimate = (new CostCalculator(markupPercent: 25))->estimate(
        str_repeat('a', 400),
        GenerationSize::Square,
        GenerationQuality::Low,
        0,
        PricingService::fromApi(pixelforgePricing()),
    );

    // 85 text tokens × $5 + 280 output × $40 + 0 reasoning, per 1M tokens.
    expect($estimate->textTokensMin)->toBe(85)
        ->and($estimate->reasoningTokensMin)->toBe(0)
        ->and($estimate->minProviderCostNanos)->toBe(425_000 + 11_200_000);
});

test('prompt length is counted in characters, not bytes', function () {
    $estimate = (new CostCalculator(markupPercent: 25))->estimate(
        str_repeat('é', 8),
        GenerationSize::Square,
        GenerationQuality::Low,
        0,
        PricingService::fromApi(pixelforgePricing()),
    );

    expect($estimate->textTokensMax)->toBe(3);
});

test('markup is added and rounded up to the next nanodollar', function (int $providerCost, int $expectedCharge) {
    expect((new CostCalculator(markupPercent: 25))->chargeFor($providerCost))->toBe($expectedCharge);
})->with([
    'exact' => [305_510_000, 381_887_500],
    'fractional nanodollar rounds up' => [1, 2],
    'free' => [0, 0],
]);
