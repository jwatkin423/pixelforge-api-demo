<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakePricing(): void
{
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pixelforgePricing())]);
}

test('guests cannot generate', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
    $this->post(route('generations.store'))->assertRedirect(route('login'));
});

test('the estimate returns the cost range with markup for the chosen options', function () {
    fakePricing();
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $this->actingAs($user)
        ->postJson(route('generations.estimate'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_image_count' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('max_charge_nanos', 467_343_750)
        ->assertJsonPath('balance_nanos', 1_000_000_000)
        ->assertJsonPath('markup_percent', 25);
});

test('submitting holds the maximum estimate and queues the generation', function () {
    fakePricing();
    Queue::fake([SubmitGenerationToPixelforge::class]);
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->estimated_max_charge_nanos)->toBe(467_343_750)
        ->and($generation->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
        ->and($user->refresh()->balance_nanos)->toBe(1_000_000_000 - 467_343_750)
        ->and($generation->creditTransactions()->sole()->type)->toBe(CreditTransactionType::Hold);

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn ($job) => $job->generation->is($generation));
});

test('submitting is blocked when the balance cannot cover the maximum cost', function () {
    fakePricing();
    Queue::fake([SubmitGenerationToPixelforge::class]);
    $user = User::factory()->withBalance(10_000_000)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'high'])
        ->assertSessionHasErrors(['balance' => 'This generation can cost up to $0.340025000, but your balance is $0.010000000. Buy credits to continue.']);

    expect(Generation::query()->count())->toBe(0)
        ->and($user->refresh()->balance_nanos)->toBe(10_000_000);
    Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
});

test('submitting validates the options Pixelforge accepts', function (array $input, string $field, string $message) {
    $user = User::factory()->withBalance(1_000_000_000)->create();

    $this->actingAs($user)
        ->post(route('generations.store'), array_merge(['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium'], $input))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt', 'The prompt field is required.'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt', 'The prompt field must not be greater than 4000 characters.'],
    'unknown size' => [['size' => '512x512'], 'size', 'The selected size is invalid.'],
    'unknown quality' => [['quality' => 'ultra'], 'quality', 'The selected quality is invalid.'],
    'too many references' => [['reference_images' => array_map(fn ($i) => "https://example.com/{$i}.png", range(1, 5))], 'reference_images', 'You can add up to 4 reference images.'],
    'non-http reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0', 'Each reference image must be an http(s) URL.'],
]);

test('history only shows the user’s own generations and polls while one is active', function () {
    $user = User::factory()->create();
    $mine = Generation::factory()->for($user)->processing()->create();
    Generation::factory()->succeeded()->create();

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $mine->uuid)
            ->where('hasActiveGenerations', true));
});
