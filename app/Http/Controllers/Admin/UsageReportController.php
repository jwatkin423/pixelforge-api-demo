<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Nanodollars;
use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClientService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and the profit,
 * per UTC day and per user. Days are bucketed by completion time, matching how
 * Pixelforge's GET /v1/usage bills, so each day can be reconciled against it.
 */
class UsageReportController extends Controller
{
    private const DEFAULT_DAYS = 14;

    private const MAX_DAYS = 92;

    public function __invoke(Request $request, PixelforgeClientService $pixelforge): Response
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = CarbonImmutable::parse($validated['end'] ?? now('UTC')->toDateString(), 'UTC')->startOfDay();
        $start = CarbonImmutable::parse($validated['start'] ?? $end->subDays(self::DEFAULT_DAYS - 1)->toDateString(), 'UTC')->startOfDay();

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        $rows = $this->ourTotals($start, $end);
        [$providerUsage, $providerError] = $this->providerUsage($pixelforge, $start, $end);

        return Inertia::render('admin/UsageReport', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $this->days($start, $end, $rows, $providerUsage),
            'users' => $this->perUser($rows),
            'providerError' => $providerError,
        ]);
    }

    /**
     * @return Collection<int, array{day: string, user_id: int, generations: int, credit_spend_nanos: int, provider_cost_nanos: int}>
     */
    private function ourTotals(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start, $end->endOfDay()])
            ->selectRaw('DATE(completed_at) as day, user_id, COUNT(*) as generations, SUM(charged_nanos) as credit_spend_nanos, SUM(provider_cost_nanos) as provider_cost_nanos')
            ->groupByRaw('DATE(completed_at), user_id')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'day' => (string) $row->day,
                'user_id' => (int) $row->user_id,
                'generations' => (int) $row->generations,
                'credit_spend_nanos' => (int) $row->credit_spend_nanos,
                'provider_cost_nanos' => (int) $row->provider_cost_nanos,
            ]);
    }

    /**
     * @return array{0: array<string, array{generations: int, cost_nanos: int}>, 1: string|null}
     */
    private function providerUsage(PixelforgeClientService $pixelforge, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $usage = $pixelforge->usage($start->toDateString(), $end->toDateString());
        } catch (Throwable $exception) {
            report($exception);

            return [[], 'Couldn’t load usage from Pixelforge: '.$exception->getMessage()];
        }

        $byDay = [];

        foreach ($usage['data'] as $day) {
            $byDay[(string) $day['date']] = [
                'generations' => (int) $day['generations'],
                'cost_nanos' => Nanodollars::fromDecimal((string) $day['cost_usd']),
            ];
        }

        return [$byDay, null];
    }

    /**
     * @param  Collection<int, array{day: string, user_id: int, generations: int, credit_spend_nanos: int, provider_cost_nanos: int}>  $rows
     * @param  array<string, array{generations: int, cost_nanos: int}>  $providerUsage
     * @return list<array<string, mixed>>
     */
    private function days(CarbonImmutable $start, CarbonImmutable $end, Collection $rows, array $providerUsage): array
    {
        $rowsByDay = $rows->groupBy('day');
        $days = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $day = $date->toDateString();
            $dayRows = $rowsByDay->get($day, collect());
            $creditSpend = (int) $dayRows->sum('credit_spend_nanos');
            $providerCost = (int) $dayRows->sum('provider_cost_nanos');
            $pixelforge = $providerUsage[$day] ?? null;

            $days[] = [
                'date' => $day,
                'generations' => (int) $dayRows->sum('generations'),
                'credit_spend_nanos' => $creditSpend,
                'provider_cost_nanos' => $providerCost,
                'profit_nanos' => $creditSpend - $providerCost,
                'pixelforge_generations' => $pixelforge['generations'] ?? null,
                'pixelforge_cost_nanos' => $pixelforge['cost_nanos'] ?? null,
                'reconciled' => $pixelforge === null ? null : $pixelforge['cost_nanos'] === $providerCost,
            ];
        }

        return array_reverse($days);
    }

    /**
     * @param  Collection<int, array{day: string, user_id: int, generations: int, credit_spend_nanos: int, provider_cost_nanos: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function perUser(Collection $rows): array
    {
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        return array_values($rows
            ->sortByDesc('day')
            ->map(function (array $row) use ($users): array {
                $user = $users->get($row['user_id']);

                return [
                    ...$row,
                    'user_name' => $user?->name,
                    'user_email' => $user?->email,
                    'profit_nanos' => $row['credit_spend_nanos'] - $row['provider_cost_nanos'],
                ];
            })
            ->all());
    }
}
