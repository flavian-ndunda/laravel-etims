<?php

declare(strict_types=1);

namespace Flavytech\Etims\Http\Webhooks;

use Flavytech\Etims\DTOs\WebhookPayloadDTO;
use Flavytech\Etims\Events\InvoiceFailed;
use Flavytech\Etims\Events\InvoiceSubmitted;
use Flavytech\Etims\Events\StockSynced;
use Flavytech\Etims\Events\WebhookReceived;
use Flavytech\Etims\Exceptions\EtimsException;
use Flavytech\Etims\Models\EtimsInvoice;
use Flavytech\Etims\Models\EtimsStockItem;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookProcessor
 *
 * Handles inbound webhook notifications from KRA eTIMS.
 *
 * Responsibilities:
 *   1. Signature verification — validates the webhook came from KRA
 *   2. Payload parsing — converts raw JSON to a typed WebhookPayloadDTO
 *   3. Event routing — fires the correct Laravel event for each webhook type
 *   4. Audit record updates — syncs DB records with KRA-confirmed status
 *   5. Idempotency — ignores duplicate webhook deliveries
 *
 * Architecture Decision: The processor is NOT a controller. It is a service
 * injected into the webhook controller. This allows you to use it outside
 * HTTP context (e.g. in tests or CLI commands that simulate webhooks).
 *
 * Signature Verification:
 * KRA signs webhook payloads with HMAC-SHA256 using your API secret.
 * The signature is sent in the X-KRA-Signature header.
 * NEVER process webhooks without verifying the signature in production.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {}

    /**
     * Process an incoming webhook request end-to-end.
     *
     * @throws EtimsException If signature verification fails
     */
    public function process(Request $request): WebhookPayloadDTO
    {
        // Step 1: Verify the signature
        if ($this->config['webhooks']['verify_signature'] ?? true) {
            $this->verifySignature($request);
        }

        // Step 2: Parse the payload
        $payload = WebhookPayloadDTO::fromRequest($request->all());

        Log::channel($this->config['logging']['channel'] ?? null)
            ->info('[eTIMS SDK] Webhook received', [
                'event_type'  => $payload->eventType,
                'reference'   => $payload->referenceNumber,
                'result_code' => $payload->resultCode,
            ]);

        // Step 3: Route to the correct handler
        $this->route($payload);

        // Step 4: Fire the generic WebhookReceived event (always fires)
        $this->events->dispatch(new WebhookReceived($payload));

        return $payload;
    }

    /**
     * Verify the HMAC-SHA256 signature on the incoming webhook.
     *
     * KRA sends the signature in the X-KRA-Signature header as:
     * sha256=<hex_digest>
     *
     * @throws EtimsException If signature is missing or invalid
     */
    public function verifySignature(Request $request): void
    {
        $signature = $request->header('X-KRA-Signature') ?? $request->header('X-Etims-Signature');

        if (!$signature) {
            throw new EtimsException(
                'Webhook rejected: X-KRA-Signature header is missing. ' .
                'Set ETIMS_WEBHOOK_VERIFY_SIGNATURE=false in .env to disable verification (not recommended).'
            );
        }

        $secret  = $this->config['webhooks']['secret'] ?? $this->config['credentials']['secret'] ?? '';
        $payload = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            throw new EtimsException(
                'Webhook rejected: Signature mismatch. ' .
                'Ensure ETIMS_WEBHOOK_SECRET matches the secret configured in KRA eTIMS portal.'
            );
        }
    }

    /**
     * Route a verified webhook payload to the appropriate handler.
     */
    private function route(WebhookPayloadDTO $payload): void
    {
        match (true) {
            $payload->isInvoiceEvent() => $this->handleInvoiceWebhook($payload),
            $payload->isStockEvent()   => $this->handleStockWebhook($payload),
            $payload->isBranchEvent()  => $this->handleBranchWebhook($payload),
            default                    => $this->handleUnknownWebhook($payload),
        };
    }

    private function handleInvoiceWebhook(WebhookPayloadDTO $payload): void
    {
        // Update the invoice audit record
        $record = EtimsInvoice::where('invoice_number', $payload->referenceNumber)->latest()->first();

        if (!$record) {
            Log::warning('[eTIMS SDK] Webhook received for unknown invoice', [
                'invoice_number' => $payload->referenceNumber,
            ]);
            return;
        }

        // Guard against duplicate webhook delivery
        if ($record->status === 'submitted' && $payload->eventType === WebhookPayloadDTO::EVENT_INVOICE_CONFIRMED) {
            Log::debug('[eTIMS SDK] Duplicate webhook ignored', ['reference' => $payload->referenceNumber]);
            return;
        }

        if ($payload->eventType === WebhookPayloadDTO::EVENT_INVOICE_CONFIRMED) {
            $record->update([
                'status'         => 'submitted',
                'receipt_number' => $payload->data['rcptNo'] ?? $payload->data['receipt_number'] ?? $record->receipt_number,
                'qr_code'        => $payload->data['qrCodeUrl'] ?? $payload->data['qr_code'] ?? $record->qr_code,
                'submitted_at'   => now(),
            ]);
        } elseif ($payload->eventType === WebhookPayloadDTO::EVENT_INVOICE_REJECTED) {
            $record->update([
                'status'         => 'failed',
                'failure_reason' => $payload->resultMessage,
            ]);
        }
    }

    private function handleStockWebhook(WebhookPayloadDTO $payload): void
    {
        $record = EtimsStockItem::where('item_code', $payload->referenceNumber)->latest()->first();

        if (!$record) {
            Log::warning('[eTIMS SDK] Webhook received for unknown stock item', [
                'item_code' => $payload->referenceNumber,
            ]);
            return;
        }

        if ($payload->isSuccessful()) {
            $record->update([
                'status'        => 'synced',
                'kra_item_code' => $payload->data['itemCd'] ?? null,
                'synced_at'     => now(),
            ]);
        } else {
            $record->update([
                'status'         => 'failed',
                'failure_reason' => $payload->resultMessage,
            ]);
        }
    }

    private function handleBranchWebhook(WebhookPayloadDTO $payload): void
    {
        Log::info('[eTIMS SDK] Branch webhook received', [
            'branch_id'     => $payload->referenceNumber,
            'result_code'   => $payload->resultCode,
            'result_message' => $payload->resultMessage,
        ]);
        // Host application handles this via WebhookReceived event
    }

    private function handleUnknownWebhook(WebhookPayloadDTO $payload): void
    {
        Log::warning('[eTIMS SDK] Unknown webhook event type received', [
            'event_type' => $payload->eventType,
            'reference'  => $payload->referenceNumber,
        ]);
    }
}
