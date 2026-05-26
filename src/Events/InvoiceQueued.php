<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\InvoiceDTO;

/**
 * InvoiceQueued
 *
 * Fired when an invoice is dispatched to the background queue.
 *
 * Use this to immediately show the user a "pending submission" status
 * in your POS UI, before the async job completes.
 */
final class InvoiceQueued
{
    public function __construct(
        public readonly InvoiceDTO $invoice,
        public readonly string|int|null $tenantId = null,
    ) {}
}
