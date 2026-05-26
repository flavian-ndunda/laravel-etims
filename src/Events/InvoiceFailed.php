<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\InvoiceDTO;
use Throwable;

/**
 * InvoiceFailed
 *
 * Fired when an invoice submission has permanently failed (all retries exhausted).
 *
 * Listen to this event to:
 *   - Alert your operations team via Slack/email/SMS
 *   - Flag the invoice in your system for manual review
 *   - Trigger a compliance workflow to handle the deferred submission
 *
 * Note: This fires AFTER all queue retries are exhausted, not on every attempt.
 * For per-attempt failure logging, the SDK writes to the etims_audit_logs table.
 */
final class InvoiceFailed
{
    public function __construct(
        public readonly InvoiceDTO $invoice,
        public readonly Throwable $exception,
        public readonly string|int|null $tenantId = null,
    ) {}
}
