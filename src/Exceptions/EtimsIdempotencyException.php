<?php

declare(strict_types=1);

namespace Flavytech\Etims\Exceptions;

/**
 * EtimsIdempotencyException
 *
 * Thrown when an invoice with the same idempotency key has already been
 * successfully submitted. This prevents duplicate submissions.
 *
 * The host application should catch this and treat it as a success —
 * the invoice was already accepted by KRA.
 */
class EtimsIdempotencyException extends EtimsException
{
    public function __construct(
        private readonly string $idempotencyKey,
        private readonly string $originalInvoiceNumber,
    ) {
        parent::__construct(
            "Invoice with idempotency key [{$idempotencyKey}] was already submitted (invoice: {$originalInvoiceNumber})."
        );
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getOriginalInvoiceNumber(): string
    {
        return $this->originalInvoiceNumber;
    }
}
