<?php

namespace App\Services\Pixelforge;

use App\Billing\CostCalculator;
use App\Billing\CostEstimate;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Http\Requests\Generations\EstimateGenerationCostRequest;
use Throwable;

class EstimateGenerationCostService
{
    public static function getEstimate(EstimateGenerationCostRequest $request, PixelforgeClientService $pixelforge, CostCalculator $costs): CostEstimate|false
    {
        try {
            $pricing = $pixelforge->pricing();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $costs->estimate(
            (string) $request->input('prompt', ''),
            GenerationSize::from($request->string('size')->toString()),
            GenerationQuality::from($request->string('quality')->toString()),
            $request->integer('reference_image_count'),
            $pricing,
        );
    }
}
