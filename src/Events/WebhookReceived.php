<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\WebhookPayloadDTO;

/**
 * WebhookReceived
 *
 * Fired whenever a verified webhook is received from KRA eTIMS.
 *
 * This event fires for EVERY webhook type after signature verification.
 * Use it as a catch-all when you want to log or audit all inbound KRA events.
 *
 * For type-specific handling, listen to the more targeted events:
 *   - InvoiceSubmitted (when INV_CONFIRMED webhook arrives)
 *   - InvoiceFailed    (when INV_REJECTED webhook arrives)
 *   - StockSynced      (when STOCK_CONFIRMED webhook arrives)
 *
 * Example listener:
 *   public function handle(WebhookReceived $event): void
 *   {
 *       if ($event->payload->isBranchEvent()) {
 *           // Handle branch status change
 *       }
 *   }
 */
final class WebhookReceived
{
    public function __construct(
        public readonly WebhookPayloadDTO $payload,
    ) {}
}
