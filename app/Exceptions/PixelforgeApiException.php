<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A non-2xx response from Pixelforge, carrying its machine-readable error code.
 */
class PixelforgeApiException extends RuntimeException
{
    /**
     * Error codes where nothing was created and the same request may succeed later.
     */
    private const RETRYABLE_CODES = [
        'overloaded',
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
    ];

    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            httpStatus: $response->status(),
            errorCode: (string) $response->json('error.code', 'unknown_error'),
            message: (string) $response->json('error.message', 'Unexpected response from Pixelforge.'),
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true)
            || ($this->httpStatus >= 500 && $this->errorCode !== 'content_policy_violation');
    }
}
