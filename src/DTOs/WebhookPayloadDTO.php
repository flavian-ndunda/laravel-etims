<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * WebhookPayloadDTO
 *
 * Represents an inbound webhook notification from the KRA eTIMS system.
 *
 * KRA sends webhook notifications to your registered endpoint when:
 *   - An invoice submission is confirmed (async processing complete)
 *   - An invoice is rejected after review
 *   - A stock item registration is confirmed
 *   - A branch status changes
 *   - A device initialization is confirmed
 *
 * Event type codes (KRA):
 *   INV_CONFIRMED  → Invoice accepted and fiscalized
 *   INV_REJECTED   → Invoice rejected after review
 *   STOCK_CONFIRMED → Stock item registered
 *   BHF_UPDATED    → Branch status updated
 *   DEVICE_INIT    → Device initialization confirmed
 *
 * Usage (in your WebhookController):
 *   $payload = WebhookPayloadDTO::fromRequest($request->all());
 *   Etims::handleWebhook($payload);
 */
final class WebhookPayloadDTO
{
    public const EVENT_INVOICE_CONFIRMED = 'INV_CONFIRMED';
    public const EVENT_INVOICE_REJECTED  = 'INV_REJECTED';
    public const EVENT_STOCK_CONFIRMED   = 'STOCK_CONFIRMED';
    public const EVENT_BRANCH_UPDATED    = 'BHF_UPDATED';
    public const EVENT_DEVICE_INIT       = 'DEVICE_INIT';

    public function __construct(
        public readonly string $eventType,
        public readonly string $referenceNumber,  // invoice/item/branch ID
        public readonly string $resultCode,
        public readonly string $resultMessage,
        public readonly string $timestamp,
        public readonly array $data,              // event-specific payload
        public readonly ?string $tenantPin = null,
        public readonly ?string $signature = null,
    ) {}

    /**
     * Build from raw incoming webhook request data.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromRequest(array $payload): self
    {
        return new self(
            eventType:       (string) ($payload['eventType'] ?? $payload['event_type'] ?? ''),
            referenceNumber: (string) ($payload['refNo'] ?? $payload['reference_number'] ?? ''),
            resultCode:      (string) ($payload['resultCd'] ?? $payload['result_code'] ?? ''),
            resultMessage:   (string) ($payload['resultMsg'] ?? $payload['result_message'] ?? ''),
            timestamp:       (string) ($payload['timestamp'] ?? now()->toIso8601String()),
            data:            $payload['data'] ?? [],
            tenantPin:       $payload['tpin'] ?? $payload['tenant_pin'] ?? null,
            signature:       $payload['signature'] ?? null,
        );
    }

    public function isInvoiceEvent(): bool
    {
        return in_array($this->eventType, [
            self::EVENT_INVOICE_CONFIRMED,
            self::EVENT_INVOICE_REJECTED,
        ], true);
    }

    public function isStockEvent(): bool
    {
        return $this->eventType === self::EVENT_STOCK_CONFIRMED;
    }

    public function isBranchEvent(): bool
    {
        return $this->eventType === self::EVENT_BRANCH_UPDATED;
    }

    public function isSuccessful(): bool
    {
        return in_array($this->resultCode, ['000', '0000', '00000000'], true);
    }
}
