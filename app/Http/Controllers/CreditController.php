<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Billing\Nanodollars;
use App\Models\CreditTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('credits/Index', [
            'packs' => config('billing.credit_packs'),
            'heldNanos' => (int) $request->user()->generations()->active()->sum('estimated_max_charge_nanos'),
            'transactions' => $request->user()->creditTransactions()
                ->with('generation:id,uuid,prompt')
                ->latest()
                ->latest('id')
                ->paginate(20)
                ->through(fn (CreditTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'description' => $transaction->description,
                    'amount_nanos' => $transaction->amount_nanos,
                    'balance_after_nanos' => $transaction->balance_after_nanos,
                    'prompt' => $transaction->generation?->prompt,
                    'created_at' => $transaction->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * The fake "Buy credits" button: no payment, just a ledger entry.
     */
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in(config('billing.credit_packs'))],
        ]);

        $ledger->purchase($request->user(), (int) $validated['amount'] * Nanodollars::PER_DOLLAR);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added $:amount of credits.', ['amount' => $validated['amount']])]);

        return back();
    }
}
