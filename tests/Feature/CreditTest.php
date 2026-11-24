<?php

use App\Enums\CreditTransactionType;
use App\Models\User;

test('buying credits adds a purchase to the ledger', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('credits.index'))
        ->post(route('credits.store'), ['amount' => 10])
        ->assertRedirect(route('credits.index'));

    expect($user->refresh()->balance_nanos)->toBe(10_000_000_000)
        ->and($user->creditTransactions()->sole()->type)->toBe(CreditTransactionType::Purchase);
});

test('only the offered credit packs can be bought', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['amount' => 1_000_000])
        ->assertSessionHasErrors(['amount' => 'The selected amount is invalid.']);

    expect($user->refresh()->balance_nanos)->toBe(0);
});
