<?php

namespace App\Services;

use App\Billing\CostCalculator;
use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PricingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Moves a generation through its lifecycle and settles its money when it finishes.
 *
 * Webhooks, the create response and the polling fallback all funnel into
 * applySnapshot(), which only ever moves a generation forward. That makes duplicate
 * and out-of-order deliveries harmless: a stale "processing" after "succeeded" is
 * ignored, and a final state is applied (and billed) exactly once.
 */
readonly class GenerationLifecycleService
{
    public function __construct(
        private CostCalculator $costs,
        private CreditLedger $ledger,
    ) {}

    /**
     * Apply a Pixelforge generation object (from a webhook, create response or GET).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function applySnapshot(Generation $generation, array $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();
            $incoming = GenerationStatus::tryFrom((string) ($snapshot['status'] ?? ''));

            $locked->pixelforge_id ??= is_string($snapshot['id'] ?? null) ? $snapshot['id'] : null;
            $locked->last_synced_at = now();

            if ($incoming === null || ! $locked->status->canTransitionTo($incoming)) {
                $locked->save();

                return $locked;
            }

            $locked->fill([
                'status' => $incoming,
                'status_detail' => null,
                'started_at' => $this->timestamp($snapshot['started_at'] ?? null) ?? $locked->started_at,
                'completed_at' => $this->timestamp($snapshot['completed_at'] ?? null),
            ]);

            if ($incoming === GenerationStatus::Succeeded) {
                $this->recordSuccess($locked, $snapshot);
            }

            if ($incoming === GenerationStatus::Failed) {
                $locked->error_code = (string) data_get($snapshot, 'error.code', 'generation_failed');
                $locked->error_message = (string) data_get($snapshot, 'error.message', 'The generation failed.');
            }

            $locked->save();

            if ($incoming->isFinal()) {
                $this->ledger->settle($locked);
            }

            return $locked;
        });
    }

    /**
     * Fail a generation that Pixelforge never accepted (rejected or gave up retrying).
     */
    public function failBeforeSubmission(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message): Generation {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== GenerationStatus::Pending) {
                return $locked;
            }

            $locked->fill([
                'status' => GenerationStatus::Failed,
                'status_detail' => null,
                'error_code' => $errorCode,
                'error_message' => $message,
                'completed_at' => now(),
            ])->save();

            $this->ledger->settle($locked);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function recordSuccess(Generation $generation, array $snapshot): void
    {
        /** @var array<string, int> $usage */
        $usage = (array) ($snapshot['usage'] ?? []);
        /** @var array<string, string> $rates */
        $rates = (array) ($snapshot['rates'] ?? []);

        $providerCost = $this->costs->providerCost($usage, PricingService::parseRates($rates));

        $generation->fill([
            'usage' => $usage,
            'rates' => $rates,
            'provider_cost_nanos' => $providerCost,
            'charged_nanos' => $this->costs->chargeFor($providerCost),
            'image_url' => $snapshot['image_url'] ?? null,
            'image_url_expires_at' => $this->timestamp($snapshot['image_url_expires_at'] ?? null),
        ]);
    }

    private function timestamp(mixed $value): ?CarbonInterface
    {
        return is_string($value) && $value !== '' ? Date::parse($value)->utc() : null;
    }
}
