<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Class for the PixelForge API
 */
class PixelforgeClientService
{
    private const string PRICING_CACHE_KEY = 'pixelforge.pricing';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * Create the generation
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}  $parameters
     * @return array<string, mixed> The generation object.
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        return $this->send(
            $this->authenticated()->withHeaders(['Idempotency-Key' => $idempotencyKey]),
            'post',
            'generations',
            $parameters,
        );
    }

    /**
     * @return array<string, mixed> The generation object.
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send($this->authenticated(), 'get', 'generations/'.rawurlencode($pixelforgeId));
    }

    /**
     * Billed totals per UTC day for this API key (both dates inclusive, at most 92 days).
     *
     * @return array{data: list<array<string, mixed>>, total: array<string, mixed>}
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function usage(string $startDate, string $endDate): array
    {
        /** @var array{data: list<array<string, mixed>>, total: array<string, mixed>} */
        return $this->send($this->authenticated(), 'get', 'usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    /**
     * Current pricing, cached briefly. Only used for estimates; actual charges always use
     * the rates Pixelforge fixed on the generation itself.
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function pricing(): PricingService
    {
        /** @var array{rates: array<string, string>, tokens: array{characters_per_text_token: int, text_token_variance: float|int, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>}} $payload */
        $payload = Cache::remember(
            self::PRICING_CACHE_KEY,
            now()->addMinutes(10),
            fn (): array => $this->send($this->request(), 'get', 'pricing'),
        );

        return PricingService::fromApi($payload);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($this->timeoutSeconds);
    }

    private function authenticated(): PendingRequest
    {
        return $this->request()->withToken((string) $this->apiKey);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    private function send(PendingRequest $request, string $method, string $path, array $data = []): array
    {
        /** @var Response $response */
        $response = $request->{$method}($path, $data);

        if ($response->failed()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        return (array) $response->json();
    }
}
