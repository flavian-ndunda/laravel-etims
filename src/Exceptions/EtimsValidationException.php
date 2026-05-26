<?php

declare(strict_types=1);

namespace Flavytech\Etims\Exceptions;

/**
 * EtimsValidationException
 *
 * Thrown when an InvoiceDTO or other DTO fails client-side validation
 * before the request is even sent to KRA.
 *
 * This saves an API roundtrip and makes validation errors explicit.
 * Unlike EtimsApiException, this is never retryable — the data must
 * be corrected first.
 */
class EtimsValidationException extends EtimsException
{
    /**
     * @param array<string, string> $errors Keyed validation errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
