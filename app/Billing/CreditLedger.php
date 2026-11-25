<?php

namespace App\Billing;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * The only place balances change. Every movement is an append-only transaction written
 * under a row lock on the user, and the (generation_id, type) unique index means a
 * generation can be held, released or charged at most once, even under races.
 *
 * A generation's money flow is: hold the maximum estimate on submit, then on completion
 * release the hold and, if it succeeded, charge the actual cost. Failures cost nothing.
 */
class CreditLedger
{
    /**
     * @throws Throwable
     */
    public function purchase(User $user, int $amountNanos): CreditTransaction
    {
        if ($amountNanos <= 0) {
            throw new LogicException('Purchases must be positive.');
        }

        return DB::transaction(fn (): CreditTransaction => $this->record(
            $this->lockUser($user),
            CreditTransactionType::Purchase,
            $amountNanos,
            'Bought $'.number_format($amountNanos / Nanodollars::PER_DOLLAR, 2).' of credits',
        ));
    }

    /**
     * @throws InsufficientCreditsException|Throwable
     */
    public function hold(Generation $generation, int $amountNanos): CreditTransaction
    {
        return DB::transaction(function () use ($generation, $amountNanos): CreditTransaction {
            $user = $this->lockUser($generation->user);

            if ($user->balance_nanos < $amountNanos) {
                throw new InsufficientCreditsException($amountNanos, $user->balance_nanos);
            }

            return $this->record($user, CreditTransactionType::Hold, -$amountNanos, 'Held while generating', $generation);
        });
    }

    /**
     * Close out a finished generation's hold. Safe to call any number of times.
     */
    public function settle(Generation $generation): void
    {
        if (! $generation->status->isFinal()) {
            throw new LogicException('Only finished generations can be settled.');
        }

        DB::transaction(function () use ($generation): void {
            $user = $this->lockUser($generation->user);
            $entries = $generation->creditTransactions()->get()->keyBy(fn (CreditTransaction $entry) => $entry->type->value);

            $hold = $entries->get(CreditTransactionType::Hold->value);

            if ($hold !== null && ! $entries->has(CreditTransactionType::HoldRelease->value)) {
                $this->record($user, CreditTransactionType::HoldRelease, -$hold->amount_nanos, 'Hold released', $generation);
            }

            if ($generation->status === GenerationStatus::Succeeded
                && $generation->charged_nanos !== null
                && ! $entries->has(CreditTransactionType::Charge->value)) {
                $this->record($user, CreditTransactionType::Charge, -$generation->charged_nanos, 'Image generation', $generation);
            }
        });
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function record(
        User $lockedUser,
        CreditTransactionType $type,
        int $amountNanos,
        string $description,
        ?Generation $generation = null,
    ): CreditTransaction {
        $lockedUser->balance_nanos += $amountNanos;
        $lockedUser->save();

        return CreditTransaction::query()->create([
            'user_id' => $lockedUser->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nanos' => $amountNanos,
            'balance_after_nanos' => $lockedUser->balance_nanos,
            'description' => $description,
        ]);
    }
}
