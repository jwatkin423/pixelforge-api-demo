<?php

use App\Models\Generation;
use App\Services\Pixelforge\WebhookSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The GET /v1/pricing body, as published by Pixelforge.
 *
 * @return array<string, mixed>
 */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

/**
 * A Pixelforge generation object for the given local generation.
 *
 * The default succeeded usage is the documented example, which costs exactly $0.305510000.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeSnapshot(Generation $generation, string $status, array $overrides = []): array
{
    $succeeded = $status === 'succeeded';

    return array_replace([
        'id' => $generation->pixelforge_id ?? 'gen_test123',
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size->value,
        'quality' => $generation->quality->value,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_uuid' => $generation->uuid],
        'image_url' => $succeeded ? 'https://pixelforge.test/images/gen_test123.png' : null,
        'image_url_expires_at' => $succeeded ? '2026-11-21T14:03:29Z' : null,
        'usage' => $succeeded
            ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411]
            : null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'] : null,
        'created_at' => '2026-11-20T14:03:11Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed'], true) ? '2026-11-20T14:03:13Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-20T14:03:29Z' : null,
    ], $overrides);
}

/**
 * A signed webhook event envelope.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function pixelforgeEvent(string $eventId, string $type, array $snapshot): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-20T14:03:29Z',
        'data' => ['object' => $snapshot],
    ];
}

/**
 * @param  array<string, mixed>  $event
 */
function postPixelforgeWebhook(array $event, ?string $secret = 'whsec_test_secret', ?int $timestamp = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= time();

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        'HTTP_PIXELFORGE_SIGNATURE' => 't='.$timestamp.',v1='.WebhookSignatureService::sign((string) $secret, $timestamp, $body),
    ], $body);
}
