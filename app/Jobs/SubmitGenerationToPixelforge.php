<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\GenerationLifecycleService;
use App\Services\Pixelforge\PixelforgeClientService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge, waiting out busy periods.
 *
 * The generation's UUID is the Idempotency-Key, so retrying after a timeout can never
 * create (or bill) a second generation. It is also sent as metadata, so a webhook that
 * beats the create response back to us can still be matched to the right row.
 */
class SubmitGenerationToPixelforge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Transient failures (overloaded, rate limited, network) are retried this many times. */
    public const MAX_TRANSIENT_ATTEMPTS = 6;

    /** How long we keep waiting for a free slot when the account is at its concurrency limit. */
    public const CAPACITY_WAIT_MINUTES = 15;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->uuid;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::CAPACITY_WAIT_MINUTES + 5);
    }

    public function handle(PixelforgeClientService $pixelforge, GenerationLifecycleService $lifecycle): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        $generation->increment('submission_attempts');

        try {
            $snapshot = $pixelforge->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_uuid' => $generation->uuid],
            ], idempotencyKey: $generation->uuid);
        } catch (PixelforgeApiException $exception) {
            $this->handleApiError($generation, $exception, $lifecycle);

            return;
        } catch (ConnectionException) {
            $this->retryOrGiveUp($generation, $lifecycle, 'connection_error', null, 'Couldn’t reach Pixelforge, retrying…');

            return;
        }

        $generation->forceFill(['submitted_at' => now()])->save();
        $lifecycle->applySnapshot($generation, $snapshot);
    }

    /**
     * Last resort if the job crashes unexpectedly: never leave the user's credits on hold.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Submitting generation to Pixelforge failed', [
            'generation' => $this->generation->uuid,
            'exception' => $exception?->getMessage(),
        ]);

        app(GenerationLifecycleService::class)->failBeforeSubmission(
            $this->generation,
            'submission_failed',
            'We couldn’t send this to Pixelforge. You were not charged.',
        );
    }

    private function handleApiError(Generation $generation, PixelforgeApiException $exception, GenerationLifecycleService $lifecycle): void
    {
        if ($exception->errorCode === 'concurrency_limit_exceeded') {
            if ($generation->created_at?->diffInMinutes(now()) < self::CAPACITY_WAIT_MINUTES) {
                $generation->forceFill(['status_detail' => 'Waiting for a free slot at Pixelforge…'])->save();
                $this->release($exception->retryAfterSeconds ?? 5);

                return;
            }

            $lifecycle->failBeforeSubmission($generation, $exception->errorCode, 'Pixelforge stayed busy for too long, so we gave up. You were not charged.');

            return;
        }

        if ($exception->isRetryable()) {
            $this->retryOrGiveUp($generation, $lifecycle, $exception->errorCode, $exception->retryAfterSeconds, 'Pixelforge is busy, retrying…');

            return;
        }

        if ($exception->errorCode === 'invalid_api_key') {
            Log::critical('Pixelforge rejected our API key.');
        }

        $lifecycle->failBeforeSubmission($generation, $exception->errorCode, self::messageFor($exception));
    }

    private function retryOrGiveUp(Generation $generation, GenerationLifecycleService $lifecycle, string $errorCode, ?int $retryAfter, string $detail): void
    {
        if ($generation->submission_attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
            $lifecycle->failBeforeSubmission($generation, $errorCode, 'Pixelforge is having trouble right now. You were not charged, please try again shortly.');

            return;
        }

        $backoff = 2 ** $generation->submission_attempts;

        $generation->forceFill(['status_detail' => $detail])->save();
        $this->release(max($retryAfter ?? 0, $backoff));
    }

    private static function messageFor(PixelforgeApiException $exception): string
    {
        return match ($exception->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'daily_limit_exceeded' => 'We’ve hit today’s generation limit. You were not charged, please try again tomorrow (UTC).',
            'validation_failed' => 'Pixelforge rejected the request: '.$exception->getMessage(),
            'invalid_api_key' => 'Image generation is temporarily unavailable. You were not charged.',
            default => 'Pixelforge couldn’t accept this generation. You were not charged.',
        };
    }
}
