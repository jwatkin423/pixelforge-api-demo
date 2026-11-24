<?php

use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A pending generation with its maximum estimate already held from a $1 balance.
 */
function heldGeneration(): Generation
{
    $user = User::factory()->withBalance(1_000_000_000)->create();
    $generation = Generation::factory()->for($user)->create(['estimated_max_charge_nanos' => 86_265_000]);
    app(CreditLedger::class)->hold($generation, 86_265_000);

    return $generation;
}

function runSubmission(Generation $generation): SubmitGenerationToPixelforge
{
    $job = (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('an accepted generation is stored as queued, sent with an idempotency key and our uuid', function () {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'queued'), 202)]);

    runSubmission($generation)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_test123');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->uuid)
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request['metadata'] === ['generation_uuid' => $generation->uuid]
        && $request['prompt'] === $generation->prompt);
});

test('a create response that arrives after the webhook does not move the generation backwards', function () {
    $generation = heldGeneration();
    $generation->update(['status' => GenerationStatus::Processing]);
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'queued'), 202)]);

    // The job skips anything already past pending, so it never even calls Pixelforge.
    runSubmission($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    Http::assertNothingSent();
});

test('a prompt rejected by the content policy fails for free', function () {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => Http::response([
        'error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.'],
    ], 400)]);

    runSubmission($generation)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->error_message)->toBe('Pixelforge rejected this prompt under its content policy. You were not charged.')
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000);
});

test('an overloaded response is retried after the Retry-After delay', function () {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => Http::response([
        'error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.'],
    ], 503, ['Retry-After' => '2'])]);

    runSubmission($generation)->assertReleased(2);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->status_detail)->toBe('Pixelforge is busy, retrying…');
});

test('the concurrency limit waits for a free slot', function () {
    $generation = heldGeneration();
    Http::fake(['pixelforge.test/v1/generations' => Http::response([
        'error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Busy.'],
    ], 429, ['Retry-After' => '5'])]);

    runSubmission($generation)->assertReleased(5);

    expect($generation->refresh()->status_detail)->toBe('Waiting for a free slot at Pixelforge…');
});

test('transient errors give up after the attempt cap and release the hold', function () {
    $generation = heldGeneration();
    $generation->update(['submission_attempts' => SubmitGenerationToPixelforge::MAX_TRANSIENT_ATTEMPTS - 1]);
    Http::fake(['pixelforge.test/v1/generations' => Http::response([
        'error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.'],
    ], 503, ['Retry-After' => '2'])]);

    runSubmission($generation)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000);
});

test('a crashed job never leaves credits on hold', function () {
    $generation = heldGeneration();

    (new SubmitGenerationToPixelforge($generation))->failed(new RuntimeException('Boom'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user->refresh()->balance_nanos)->toBe(1_000_000_000);
});
