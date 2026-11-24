<?php

use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('quiet in-flight generations are polled and settled when a webhook was missed', function () {
    Http::preventStrayRequests();
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $generation = Generation::factory()->for($user)->processing()->create(['pixelforge_id' => 'gen_test123']);
    app(CreditLedger::class)->hold($generation, 467_343_750);
    $generation->newQuery()->whereKey($generation->id)->toBase()->update(['updated_at' => now()->subMinutes(5)]);
    Http::fake(['pixelforge.test/v1/generations/gen_test123' => Http::response(pixelforgeSnapshot($generation, 'succeeded'))]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($user->refresh()->balance_nanos)->toBe(1_000_000_000 - 381_887_500);
});
