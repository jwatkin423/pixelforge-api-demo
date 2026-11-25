<?php

namespace App\Http\Controllers;

use App\Billing\CostCalculator;
use App\Billing\CreditLedger;
use App\Billing\Nanodollars;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GenerationController extends Controller
{
    /**
     * The user's generation history. The page polls while anything is still in flight.
     */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest()
            ->latest('id')
            ->paginate(12)
            ->through(fn (Generation $generation): array => GenerationResource::make($generation)->resolve($request));

        return Inertia::render('generations/Index', [
            'generations' => $generations,
            'hasActiveGenerations' => $request->user()->generations()->active()->exists(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => array_map(fn (GenerationSize $size): array => ['value' => $size->value, 'label' => $size->label()], GenerationSize::cases()),
            'qualities' => array_map(fn (GenerationQuality $quality): array => ['value' => $quality->value, 'label' => $quality->label()], GenerationQuality::cases()),
            'recentGenerations' => GenerationResource::collection(
                $request->user()->generations()->latest()->latest('id')->limit(4)->get(),
            )->resolve($request),
            'hasActiveGenerations' => $request->user()->generations()->active()->exists(),
        ]);
    }

    /**
     * Save the generation, hold its maximum possible cost, and queue it for Pixelforge.
     */
    public function store(StoreGenerationRequest $request, PixelforgeClientService $pixelforge, CostCalculator $costs, CreditLedger $ledger): RedirectResponse
    {
        try {
            $pricing = $pixelforge->pricing();
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'prompt' => 'We couldn’t reach Pixelforge to price this generation. Please try again in a moment.',
            ]);
        }

        $estimate = $costs->estimate(
            $request->string('prompt')->toString(),
            $request->size(),
            $request->quality(),
            count($request->referenceImages()),
            $pricing,
        );

        try {
            $generation = DB::transaction(function () use ($request, $estimate, $ledger): Generation {
                $generation = $request->user()->generations()->create([
                    'uuid' => (string) Str::uuid(),
                    'status' => GenerationStatus::Pending,
                    'prompt' => $request->string('prompt')->toString(),
                    'size' => $request->size(),
                    'quality' => $request->quality(),
                    'reference_images' => $request->referenceImages(),
                    'estimated_min_charge_nanos' => $estimate->minChargeNanos,
                    'estimated_max_charge_nanos' => $estimate->maxChargeNanos,
                ]);

                $ledger->hold($generation, $estimate->maxChargeNanos);

                return $generation;
            });
        } catch (InsufficientCreditsException $exception) {
            throw ValidationException::withMessages([
                'balance' => sprintf(
                    'This generation can cost up to $%s, but your balance is $%s. Buy credits to continue.',
                    Nanodollars::toDecimal($exception->requiredNanos),
                    Nanodollars::toDecimal($exception->balanceNanos),
                ),
            ]);
        }

        SubmitGenerationToPixelforge::dispatch($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation started. We’ll update it here live.')]);

        return to_route('generations.index');
    }
}
