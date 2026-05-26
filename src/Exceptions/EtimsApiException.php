<?php

declare(strict_types=1);

namespace Flavytech\Etims\Exceptions;

use Throwable;

/**
 * EtimsApiException
 *
 * Thrown when the KRA API returns an error response (non-success result code)
 * or when the HTTP request itself fails (timeout, connection refused, etc.).
 *
 * Carries the HTTP status code and KRA result code for debugging and retry logic.
 */
class EtimsApiException extends EtimsException
{
    public function __construct(
        string $message,
        private readonly int $httpStatusCode = 0,
        private readonly string $kraResultCode = '',
        private readonly array $responseBody = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getKraResultCode(): string
    {
        return $this->kraResultCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    /**
     * Determine if this error is worth retrying.
     *
     * Network errors (5xx, timeouts, connection refused) are retryable.
     * Client errors (4xx, validation failures) are NOT retryable — they
     * will fail again with the same payload.
     */
    public function isRetryable(): bool
    {
        // 429 Too Many Requests → retryable with backoff
        if ($this->httpStatusCode === 429) {
            return true;
        }

        // 5xx server errors → retryable
        if ($this->httpStatusCode >= 500) {
            return true;
        }

        // Network-level failure (no HTTP response) → retryable
        if ($this->httpStatusCode === 0) {
            return true;
        }

        // 4xx client errors → NOT retryable
        return false;
    }
}
