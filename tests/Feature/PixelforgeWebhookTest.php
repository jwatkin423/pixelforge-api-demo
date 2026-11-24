<?php

use App\Billing\CreditLedger;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

/**
 * A generation Pixelforge has accepted, with its maximum estimate held from a $1 balance.
 */
function acceptedGeneration(): Generation
{
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $generation = Generation::factory()->for($user)->queued()->create([
        'pixelforge_id' => 'gen_test123',
        'estimated_max_charge_nanos' => 467_343_750,
    ]);
    app(CreditLedger::class)->hold($generation, 467_343_750);

    return $generation;
}

test('a succeeded event charges the actual cost plus markup and releases the hold', function () {
    $generation = acceptedGeneration();

    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeSnapshot($generation, 'succeeded')))
        ->assertOk()
        ->assertJsonPath('outcome', 'processed');

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(305_510_000)
        ->and($generation->charged_nanos)->toBe(381_887_500)
        ->and($generation->image_url)->toBe('https://pixelforge.test/images/gen_test123.png')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-20T14:03:29Z')
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000 - 381_887_500);
});

test('a duplicate event is acknowledged but never recorded twice', function () {
    $generation = acceptedGeneration();
    $event = pixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeSnapshot($generation, 'succeeded'));

    postPixelforgeWebhook($event)->assertOk();
    postPixelforgeWebhook($event)->assertOk()->assertJsonPath('outcome', 'duplicate');

    expect($generation->creditTransactions()->where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000 - 381_887_500);
});

test('the same final state delivered under a new event id is not charged twice', function () {
    $generation = acceptedGeneration();

    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeSnapshot($generation, 'succeeded')))->assertOk();
    postPixelforgeWebhook(pixelforgeEvent('evt_2', 'generation.succeeded', pixelforgeSnapshot($generation, 'succeeded')))->assertOk();

    expect($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000 - 381_887_500);
});

test('a processing event that arrives after success does not move the generation back', function () {
    $generation = acceptedGeneration();

    postPixelforgeWebhook(pixelforgeEvent('evt_2', 'generation.succeeded', pixelforgeSnapshot($generation, 'succeeded')))->assertOk();
    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.processing', pixelforgeSnapshot($generation, 'processing')))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('a failed event costs nothing', function () {
    $generation = acceptedGeneration();

    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.failed', pixelforgeSnapshot($generation, 'failed')))->assertOk();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charged_nanos)->toBeNull()
        ->and($generation->error_message)->toBe('The model could not produce an image.')
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000);
});

test('an event that beats the create response is matched by our uuid in metadata', function () {
    $generation = acceptedGeneration();
    $generation->update(['pixelforge_id' => null, 'status' => GenerationStatus::Pending]);

    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.processing', pixelforgeSnapshot($generation, 'processing', ['id' => 'gen_early'])))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->pixelforge_id)->toBe('gen_early');
});

test('events with a bad signature are rejected without side effects', function (?string $secret, ?int $timestamp) {
    $generation = acceptedGeneration();

    postPixelforgeWebhook(pixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeSnapshot($generation, 'succeeded')), $secret, $timestamp)
        ->assertBadRequest();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
})->with([
    'wrong secret' => ['whsec_wrong', null],
    'replayed timestamp' => ['whsec_test_secret', time() - 301],
]);

test('ping events are acknowledged', function () {
    postPixelforgeWebhook(pixelforgeEvent('evt_ping', 'ping', []))
        ->assertOk()
        ->assertJsonPath('received', true);
});
