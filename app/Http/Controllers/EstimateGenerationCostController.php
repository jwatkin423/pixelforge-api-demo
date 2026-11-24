<?php

namespace App\Http\Controllers;

use App\Billing\CostCalculator;
use App\Http\Requests\Generations\EstimateGenerationCostRequest;
use App\Services\Pixelforge\EstimateGenerationCostService;
use App\Services\Pixelforge\PixelforgeClientService;
use Illuminate\Http\JsonResponse;

/**
 * Live price preview for the generate form. The same calculator prices the hold on
 * submit, so the number the user sees is the number we reserve.
 */
class EstimateGenerationCostController extends Controller
{
    public function __invoke(EstimateGenerationCostRequest $request, PixelforgeClientService $pixelforge, CostCalculator $costs): JsonResponse
    {

        $estimate = EstimateGenerationCostService::getEstimate(
            $request,
            $pixelforge,
            $costs
        );

        if (! $estimate) {
            return response()
                ->json(['message' => 'Pricing is temporarily unavailable.'],
                    503
                );
        }

        return response()->json([
            ...$estimate->toArray(),
            'balance_nanos' => $request->user()->balance_nanos,
            'markup_percent' => (int) config('billing.markup_percent'),
        ]);
    }
}
