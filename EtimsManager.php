<?php

declare(strict_types=1);

namespace Flavytech\Etims\Services;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\Contracts\TenantResolverContract;
use Flavytech\Etims\DTOs\BranchDTO;
use Flavytech\Etims\DTOs\BranchResponseDTO;
use Flavytech\Etims\DTOs\CreditNoteDTO;
use Flavytech\Etims\DTOs\DebitNoteDTO;
use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\DTOs\PinValidationResponseDTO;
use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;
use Flavytech\Etims\DTOs\WebhookPayloadDTO;
use Flavytech\Etims\Events\InvoiceFailed;
use Flavytech\Etims\Events\InvoiceQueued;
use Flavytech\Etims\Events\InvoiceSubmitted;
use Flavytech\Etims\Events\StockMovementFailed;
use Flavytech\Etims\Events\StockMovementRecorded;
use Flavytech\Etims\Events\StockSyncFailed;
use Flavytech\Etims\Events\StockSynced;
use Flavytech\Etims\Exceptions\EtimsIdempotencyException;
use Flavytech\Etims\Http\Webhooks\WebhookProcessor;
use Flavytech\Etims\Jobs\RecordStockMovementJob;
use Flavytech\Etims\Jobs\SubmitInvoiceJob;
use Flavytech\Etims\Jobs\SyncStockJob;
use Flavytech\Etims\Models\EtimsBranch;
use Flavytech\Etims\Models\EtimsInvoice;
use Flavytech\Etims\Models\EtimsStockItem;
use Flavytech\Etims\Models\EtimsStockMovement;
use Flavytech\Etims\Support\QrCodeGenerator;
use Flavytech\Etims\Support\ThermalReceiptBuilder;
use Flavytech\Etims\Testing\FakeEtimsClient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EtimsManager
 *
 * The central orchestrator for all eTIMS operations. This is the class that
 * the Facade resolves to and that all host application code interacts with.
 *
 * The Manager sits above the EtimsClient and adds:
 *   1. Idempotency protection — prevents duplicate submissions
 *   2. Event dispatching — fires events the host app can listen to
 *   3. Audit logging — writes every submission to the DB
 *   4. Multi-tenancy — can swap to a tenant-specific client
 *   5. Queue dispatch — wraps submissions in durable jobs
 *   6. Testing support — can be replaced with a fake for tests
 *
 * Usage (via Facade):
 *   $response = Etims::submitInvoice($invoiceDto);
 *   Etims::queueInvoice($invoiceDto);
 *   $valid = Etims::validatePin('P000000000A');
 *   Etims::fake();
 *
 * The Manager itself is stateless — all state lives in the database,
 * cache (for tokens), and the queue. This makes it safe to use in
 * queue workers and horizontal scaling scenarios.
 */
class EtimsManager
{
    /**
     * Whether the SDK is in fake/testing mode.
     * When true, all operations use the FakeEtimsClient.
     */
    private bool $faking = false;

    private ?FakeEtimsClient $fakeClient = null;

    public function __construct(
        private readonly EtimsClientContract $client,
        private readonly Dispatcher $events,
        private readonly array $config,
        private readonly ?TenantResolverContract $tenantResolver = null,
        private readonly ?WebhookProcessor $webhookProcessor = null,
    ) {}

    // =========================================================================
    // Core API Methods
    // =========================================================================

    /**
     * Submit an invoice synchronously.
     *
     * This is the direct submission path. It:
     *  1. Checks idempotency (skips if already submitted)
     *  2. Saves an audit record with 'pending' status
     *  3. Calls the KRA API
     *  4. Updates the audit record with the result
     *  5. Fires InvoiceSubmitted or InvoiceFailed events
     *  6. Returns the typed response DTO
     *
     * Use this when you need an immediate result (e.g. a POS receipt flow).
     * For background jobs, use queueInvoice() instead.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     * @throws \Flavytech\Etims\Exceptions\EtimsIdempotencyException
     */
    public function submitInvoice(InvoiceDTO $invoice): InvoiceResponseDTO
    {
        // Idempotency guard
        if ($this->config['idempotency']['enabled'] ?? true) {
            $this->guardIdempotency($invoice);
        }

        // Create audit record
        $record = $this->createAuditRecord($invoice, 'pending');

        try {
            $response = $this->resolveClient()->submitInvoice($invoice);

            // Update audit record with result
            $this->updateAuditRecord($record, $response);

            // Fire success event for the host application to listen to
            $this->events->dispatch(new InvoiceSubmitted($invoice, $response, $this->currentTenantId()));

            return $response;

        } catch (Throwable $e) {
            // Update audit record as failed
            $this->markAuditRecordFailed($record, $e->getMessage());

            // Fire failure event
            $this->events->dispatch(new InvoiceFailed($invoice, $e, $this->currentTenantId()));

            throw $e;
        }
    }

    /**
     * Queue an invoice for asynchronous background submission.
     *
     * This is the RECOMMENDED method for most applications.
     *
     * The invoice is dispatched to a durable Laravel queue job.
     * If the job fails (API down, timeout, etc.) it will retry automatically
     * with exponential backoff per the queue config.
     *
     * The host application should listen to InvoiceSubmitted / InvoiceFailed
     * events to know the final outcome.
     *
     * @return string The queued job's UUID (for status tracking)
     */
    public function queueInvoice(InvoiceDTO $invoice): string
    {
        // Idempotency check even before queuing — no point queuing a duplicate
        if ($this->config['idempotency']['enabled'] ?? true) {
            $this->guardIdempotency($invoice);
        }

        $queueConfig = $this->config['queue'] ?? [];
        $tenantId    = $this->currentTenantId();

        $job = new SubmitInvoiceJob(
            invoice:  $invoice,
            tenantId: $tenantId,
        );

        $job->onConnection($queueConfig['connection'] ?? null)
            ->onQueue($queueConfig['queue'] ?? 'etims');

        dispatch($job);

        // Fire a "queued" event so the host app can show "submitted for processing"
        $this->events->dispatch(new InvoiceQueued($invoice, $tenantId));

        Log::channel($this->config['logging']['channel'] ?? null)
            ->info('[eTIMS SDK] Invoice queued for async submission', [
                'invoice_number' => $invoice->invoiceNumber,
                'tenant_id'      => $tenantId,
            ]);

        return $invoice->resolveIdempotencyKey();
    }

    /**
     * Validate a KRA PIN.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function validatePin(string $pin): PinValidationResponseDTO
    {
        return $this->resolveClient()->validatePin($pin);
    }

    // =========================================================================
    // Phase 2 — Credit & Debit Notes
    // =========================================================================

    /**
     * Submit a credit note synchronously.
     *
     * A credit note reverses or partially reverses a previously submitted invoice.
     * Internally converts the CreditNoteDTO to an InvoiceDTO with type 'C'.
     *
     * Usage — Full reversal:
     *   $credit = CreditNoteDTO::reversal($originalInvoice, 'Goods returned', 'CN-001');
     *   $response = Etims::submitCreditNote($credit);
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function submitCreditNote(CreditNoteDTO $creditNote): InvoiceResponseDTO
    {
        return $this->submitInvoice($creditNote->toInvoiceDTO());
    }

    /**
     * Queue a credit note for async background submission.
     */
    public function queueCreditNote(CreditNoteDTO $creditNote): string
    {
        return $this->queueInvoice($creditNote->toInvoiceDTO());
    }

    /**
     * Submit a debit note synchronously.
     *
     * A debit note increases the amount owed on a previously submitted invoice.
     * Internally converts the DebitNoteDTO to an InvoiceDTO with type 'D'.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function submitDebitNote(DebitNoteDTO $debitNote): InvoiceResponseDTO
    {
        return $this->submitInvoice($debitNote->toInvoiceDTO());
    }

    /**
     * Queue a debit note for async background submission.
     */
    public function queueDebitNote(DebitNoteDTO $debitNote): string
    {
        return $this->queueInvoice($debitNote->toInvoiceDTO());
    }

    // =========================================================================
    // Phase 2 — Webhook Handling
    // =========================================================================

    /**
     * Process an inbound webhook from KRA.
     *
     * Verifies the signature, parses the payload, updates audit records,
     * and fires the appropriate Laravel events.
     *
     * Register the route in routes/api.php:
     *   Route::post('/webhooks/etims', fn(Request $r) => Etims::handleWebhook($r));
     *
     * Or use the built-in controller:
     *   Route::post('/webhooks/etims', EtimsWebhookController::class);
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsException If signature verification fails
     */
    public function handleWebhook(Request $request): WebhookPayloadDTO
    {
        $processor = $this->webhookProcessor ?? new WebhookProcessor($this->events, $this->config);

        return $processor->process($request);
    }

    // =========================================================================
    // Phase 3 — Branch Management
    // =========================================================================

    /**
     * Register or update a branch with KRA synchronously.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function saveBranch(BranchDTO $branch): BranchResponseDTO
    {
        $response = $this->resolveClient()->saveBranch($branch);

        EtimsBranch::updateOrCreate(
            ['branch_id' => $branch->branchId, 'tenant_id' => $this->currentTenantId()],
            [
                'branch_name'    => $branch->branchName,
                'branch_address' => $branch->branchAddress,
                'manager_name'   => $branch->managerName,
                'phone'          => $branch->phone,
                'email'          => $branch->email,
                'status'         => $branch->isActive() ? 'active' : 'inactive',
                'kra_status'     => $branch->status,
                'payload'        => $branch->toKraPayload(),
                'response'       => $response->toArray(),
                'registered_at'  => $response->isSuccessful() ? now() : null,
                'failure_reason' => $response->isSuccessful() ? null : $response->resultMessage,
            ]
        );

        return $response;
    }

    /**
     * Fetch all branches registered under the current PIN from KRA.
     *
     * @return BranchResponseDTO[]
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function getBranches(): array
    {
        return $this->resolveClient()->getBranches();
    }

    /**
     * Get locally stored branch records (from DB, without KRA API call).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EtimsBranch>
     */
    public function localBranches(): \Illuminate\Database\Eloquent\Collection
    {
        $query = EtimsBranch::query();

        if ($tenantId = $this->currentTenantId()) {
            $query->forTenant($tenantId);
        }

        return $query->orderBy('branch_id')->get();
    }

    // =========================================================================
    // Phase 3 — QR Code Support
    // =========================================================================

    /**
     * Generate a QR code SVG for a submitted invoice response.
     *
     * Returns an SVG string ready to embed in HTML or PDF.
     *
     * Usage:
     *   $svg = Etims::generateQrCode($response);
     *   // In Blade: {!! $svg !!}
     *
     * For better quality QR codes, install endroid/qr-code:
     *   composer require endroid/qr-code
     */
    public function generateQrCode(InvoiceResponseDTO $response, int $size = 200): string
    {
        return (new QrCodeGenerator($size))
            ->fromResponse($response)
            ->toSvg();
    }

    /**
     * Get the KRA verification URL for a submitted invoice.
     *
     * Use this when you want to embed a clickable link rather than a QR code.
     */
    public function getVerificationUrl(InvoiceResponseDTO $response): string
    {
        return (new QrCodeGenerator())
            ->fromResponse($response)
            ->toVerificationUrl();
    }

    /**
     * Get a QrCodeGenerator instance for advanced QR customization.
     *
     * Usage:
     *   Etims::qrCode($response)->size(300)->toDataUri();
     */
    public function qrCode(InvoiceResponseDTO $response): QrCodeGenerator
    {
        return (new QrCodeGenerator())->fromResponse($response);
    }

    // =========================================================================
    // Phase 3 — Thermal Receipt Support
    // =========================================================================

    /**
     * Build a KRA-compliant fiscal receipt for printing or display.
     *
     * Returns a ThermalReceiptBuilder you can chain to get HTML or ESC/POS output.
     *
     * Usage — HTML receipt:
     *   $html = Etims::receipt($invoice, $response)
     *       ->businessName('Acme Supermarket')
     *       ->businessAddress('Tom Mboya St, Nairobi')
     *       ->cashierName('Jane Wanjiru')
     *       ->toHtml();
     *
     * Usage — Thermal printer (ESC/POS):
     *   $bytes = Etims::receipt($invoice, $response)
     *       ->businessName('Acme Supermarket')
     *       ->toEscPos();
     *   // Send $bytes to your printer socket
     */
    public function receipt(InvoiceDTO $invoice, InvoiceResponseDTO $response): ThermalReceiptBuilder
    {
        return ThermalReceiptBuilder::make($invoice, $response);
    }

    /**
     * Synchronize a stock item master record with KRA synchronously.
     *
     * Use this when you need an immediate result — for example, during a
     * product import flow where you want to confirm each item is registered
     * before proceeding. For bulk imports, use queueStockSync() instead.
     *
     * Flow:
     *  1. Creates an audit record (status: pending)
     *  2. Calls KRA API
     *  3. Updates audit record with result
     *  4. Fires StockSynced or StockSyncFailed event
     *  5. Returns typed StockResponseDTO
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function syncStock(StockItemDTO $stockItem): StockResponseDTO
    {
        $record = $this->createStockItemRecord($stockItem);

        try {
            $response = $this->resolveClient()->syncStock($stockItem);

            $record->update([
                'status'        => $response->isSuccessful() ? 'synced' : 'failed',
                'kra_item_code' => $response->kraItemCode,
                'response'      => $response->toArray(),
                'synced_at'     => $response->isSuccessful() ? now() : null,
                'failure_reason' => $response->isSuccessful() ? null : $response->resultMessage,
            ]);

            if ($response->isSuccessful()) {
                $this->events->dispatch(new StockSynced($stockItem, $response, $this->currentTenantId()));
            } else {
                $this->events->dispatch(new StockSyncFailed(
                    $stockItem,
                    new \RuntimeException($response->resultMessage),
                    $this->currentTenantId(),
                ));
            }

            return $response;

        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            $this->events->dispatch(new StockSyncFailed($stockItem, $e, $this->currentTenantId()));
            throw $e;
        }
    }

    /**
     * Queue a stock item sync for asynchronous background processing.
     *
     * Recommended for:
     *   - Bulk product catalogue imports (100s of items)
     *   - Nightly synchronization jobs
     *   - Any scenario where you can't afford to wait for KRA's response
     *
     * The job retries automatically with exponential backoff if KRA is slow or down.
     * Listen to StockSynced / StockSyncFailed events to know the outcome.
     */
    public function queueStockSync(StockItemDTO $stockItem): void
    {
        $record      = $this->createStockItemRecord($stockItem);
        $queueConfig = $this->config['queue'] ?? [];

        $job = new SyncStockJob(
            stockItem: $stockItem,
            tenantId:  $this->currentTenantId(),
        );

        $job->onConnection($queueConfig['connection'] ?? null)
            ->onQueue($queueConfig['queue'] ?? 'etims');

        dispatch($job);

        Log::info('[eTIMS SDK] Stock item queued for sync', [
            'item_code' => $stockItem->itemCode,
            'record_id' => $record->id,
        ]);
    }

    /**
     * Sync multiple stock items, each dispatched as an individual queue job.
     *
     * This is the correct approach for product catalogue imports. Each item
     * gets its own job, so a single failure doesn't block the others.
     *
     * @param StockItemDTO[] $stockItems
     */
    public function queueBulkStockSync(array $stockItems): int
    {
        $dispatched = 0;

        foreach ($stockItems as $stockItem) {
            $this->queueStockSync($stockItem);
            $dispatched++;
        }

        Log::info('[eTIMS SDK] Bulk stock sync queued', [
            'count'     => $dispatched,
            'tenant_id' => $this->currentTenantId(),
        ]);

        return $dispatched;
    }

    /**
     * Record a stock movement with KRA synchronously.
     *
     * Use this when you need immediate confirmation — for example, recording
     * a purchase immediately after receiving goods. For sale-driven movements,
     * consider queueStockMovement() to avoid blocking the checkout flow.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function recordStockMovement(StockMovementDTO $movement): StockResponseDTO
    {
        $record = $this->createMovementRecord($movement);

        try {
            $response = $this->resolveClient()->recordStockMovement($movement);

            $record->update([
                'status'        => $response->isSuccessful() ? 'recorded' : 'failed',
                'response'      => $response->toArray(),
                'recorded_at'   => $response->isSuccessful() ? now() : null,
                'failure_reason' => $response->isSuccessful() ? null : $response->resultMessage,
            ]);

            if ($response->isSuccessful()) {
                $this->events->dispatch(new StockMovementRecorded($movement, $response, $this->currentTenantId()));
            } else {
                $this->events->dispatch(new StockMovementFailed(
                    $movement,
                    new \RuntimeException($response->resultMessage),
                    $this->currentTenantId(),
                ));
            }

            return $response;

        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            $this->events->dispatch(new StockMovementFailed($movement, $e, $this->currentTenantId()));
            throw $e;
        }
    }

    /**
     * Queue a stock movement for asynchronous background recording.
     *
     * This is the recommended path for sale-driven stock reductions.
     * Listen to StockMovementRecorded / StockMovementFailed for the outcome.
     */
    public function queueStockMovement(StockMovementDTO $movement): void
    {
        $record      = $this->createMovementRecord($movement);
        $queueConfig = $this->config['queue'] ?? [];

        $job = new RecordStockMovementJob(
            movement: $movement,
            tenantId: $this->currentTenantId(),
        );

        $job->onConnection($queueConfig['connection'] ?? null)
            ->onQueue($queueConfig['queue'] ?? 'etims');

        dispatch($job);

        Log::info('[eTIMS SDK] Stock movement queued for recording', [
            'item_code'     => $movement->itemCode,
            'movement_type' => $movement->movementTypeLabel(),
            'record_id'     => $record->id,
        ]);
    }

    /**
     * Return failed stock item sync records for manual review.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EtimsStockItem>
     */
    public function failedStockSyncs(): \Illuminate\Database\Eloquent\Collection
    {
        $query = EtimsStockItem::failed();

        if ($tenantId = $this->currentTenantId()) {
            $query->forTenant($tenantId);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Return failed stock movement records for manual review.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EtimsStockMovement>
     */
    public function failedStockMovements(): \Illuminate\Database\Eloquent\Collection
    {
        $query = EtimsStockMovement::failed();

        if ($tenantId = $this->currentTenantId()) {
            $query->forTenant($tenantId);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Re-queue a failed stock item sync by its audit record ID.
     */
    public function retryFailedStockSync(int $recordId): void
    {
        $record = EtimsStockItem::findOrFail($recordId);

        if ($record->status !== 'failed') {
            throw new \LogicException("Stock item record #{$recordId} is not in 'failed' status.");
        }

        $stockItem = StockItemDTO::make($record->payload);
        $record->update(['status' => 'pending', 'attempt_count' => $record->attempt_count + 1]);
        $this->queueStockSync($stockItem);
    }

    /**
     * Re-queue a failed stock movement by its audit record ID.
     */
    public function retryFailedStockMovement(int $recordId): void
    {
        $record = EtimsStockMovement::findOrFail($recordId);

        if ($record->status !== 'failed') {
            throw new \LogicException("Stock movement record #{$recordId} is not in 'failed' status.");
        }

        $movement = StockMovementDTO::make($record->payload);
        $record->update(['status' => 'pending', 'attempt_count' => $record->attempt_count + 1]);
        $this->queueStockMovement($movement);
    }

    /**
     * Check the submission status of an invoice by number.
     *
     * Use for polling when an invoice returned a 'pending' status.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function getInvoiceStatus(string $invoiceNumber): InvoiceResponseDTO
    {
        return $this->resolveClient()->getInvoiceStatus($invoiceNumber);
    }

    /**
     * Return a list of invoices that have permanently failed submission.
     *
     * These are invoices that exceeded max retry attempts in the queue.
     * Review and resubmit manually or build a dashboard UI on top of this.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EtimsInvoice>
     */
    public function failedInvoices(): \Illuminate\Database\Eloquent\Collection
    {
        $query = EtimsInvoice::where('status', 'failed');

        if ($tenantId = $this->currentTenantId()) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Retry a previously failed invoice by its audit record ID.
     *
     * This re-queues the invoice for another attempt, resetting the
     * retry counter. Use this from your admin dashboard.
     */
    public function retryFailedInvoice(int $recordId): string
    {
        $record = EtimsInvoice::findOrFail($recordId);

        if ($record->status !== 'failed') {
            throw new \LogicException("Invoice #{$recordId} is not in 'failed' status.");
        }

        $invoice = InvoiceDTO::make($record->payload);

        $record->update(['status' => 'retrying', 'retry_count' => $record->retry_count + 1]);

        return $this->queueInvoice($invoice);
    }

    // =========================================================================
    // Testing Support
    // =========================================================================

    /**
     * Put the SDK into fake mode for tests.
     *
     * Usage in tests:
     *   Etims::fake();
     *   // ... code that calls Etims::submitInvoice(...)
     *   Etims::assertInvoiceSubmitted('INV-001');
     *
     * @return FakeEtimsClient The fake client, for fluent assertions
     */
    public function fake(): FakeEtimsClient
    {
        $this->faking     = true;
        $this->fakeClient = new FakeEtimsClient();

        return $this->fakeClient;
    }

    /**
     * Assert that an invoice with the given number was submitted during the test.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    public function assertInvoiceSubmitted(string $invoiceNumber): void
    {
        if (!$this->faking || !$this->fakeClient) {
            throw new \LogicException('Call Etims::fake() before making assertions.');
        }

        $this->fakeClient->assertSubmitted($invoiceNumber);
    }

    /**
     * Assert that no invoices were submitted during the test.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    public function assertNothingSubmitted(): void
    {
        if (!$this->faking || !$this->fakeClient) {
            throw new \LogicException('Call Etims::fake() before making assertions.');
        }

        $this->fakeClient->assertNothingSubmitted();
    }

    /**
     * Assert that a stock item was synced during the test.
     */
    public function assertStockSynced(string $itemCode): void
    {
        if (!$this->faking || !$this->fakeClient) {
            throw new \LogicException('Call Etims::fake() before making assertions.');
        }

        $this->fakeClient->assertStockSynced($itemCode);
    }

    /**
     * Assert that a stock movement was recorded for the given item.
     */
    public function assertMovementRecorded(string $itemCode): void
    {
        if (!$this->faking || !$this->fakeClient) {
            throw new \LogicException('Call Etims::fake() before making assertions.');
        }

        $this->fakeClient->assertMovementRecorded($itemCode);
    }

    /**
     * Assert a stock movement of a specific type was recorded.
     */
    public function assertMovementRecordedOfType(string $itemCode, string $movementType): void
    {
        if (!$this->faking || !$this->fakeClient) {
            throw new \LogicException('Call Etims::fake() before making assertions.');
        }

        $this->fakeClient->assertMovementRecordedOfType($itemCode, $movementType);
    }

    /**
     * Assert a stock sync job was dispatched to the queue.
     */
    public function assertStockSyncQueued(string $itemCode): void
    {
        \Illuminate\Support\Facades\Queue::assertPushed(
            SyncStockJob::class,
            fn(SyncStockJob $job) => $job->stockItem->itemCode === $itemCode
        );
    }

    /**
     * Assert a stock movement job was dispatched to the queue.
     */
    public function assertStockMovementQueued(string $itemCode): void
    {
        \Illuminate\Support\Facades\Queue::assertPushed(
            RecordStockMovementJob::class,
            fn(RecordStockMovementJob $job) => $job->movement->itemCode === $itemCode
        );
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Resolve the correct client — fake or real.
     */
    private function resolveClient(): EtimsClientContract
    {
        if ($this->faking && $this->fakeClient) {
            return $this->fakeClient;
        }

        return $this->client;
    }

    /**
     * Guard against submitting the same invoice twice.
     *
     * @throws EtimsIdempotencyException
     */
    private function guardIdempotency(InvoiceDTO $invoice): void
    {
        $key = $invoice->resolveIdempotencyKey();

        $existing = EtimsInvoice::where('idempotency_key', $key)
            ->where('status', 'submitted')
            ->first();

        if ($existing) {
            throw new EtimsIdempotencyException($key, $invoice->invoiceNumber);
        }
    }

    /**
     * Create a pending audit record for this invoice submission.
     */
    private function createAuditRecord(InvoiceDTO $invoice, string $status): EtimsInvoice
    {
        return EtimsInvoice::create([
            'invoice_number'  => $invoice->invoiceNumber,
            'idempotency_key' => $invoice->resolveIdempotencyKey(),
            'status'          => $status,
            'payload'         => $invoice->toKraPayload(),
            'tenant_id'       => $this->currentTenantId(),
            'submitted_at'    => null,
        ]);
    }

    private function createStockItemRecord(StockItemDTO $stockItem): EtimsStockItem
    {
        return EtimsStockItem::create([
            'item_code'  => $stockItem->itemCode,
            'item_name'  => $stockItem->itemName,
            'status'     => 'pending',
            'payload'    => $stockItem->toKraPayload(),
            'tenant_id'  => $this->currentTenantId(),
        ]);
    }

    private function createMovementRecord(StockMovementDTO $movement): EtimsStockMovement
    {
        return EtimsStockMovement::create([
            'item_code'          => $movement->itemCode,
            'movement_type'      => $movement->movementType,
            'movement_type_label' => $movement->movementTypeLabel(),
            'quantity'           => $movement->quantity,
            'unit_price'         => $movement->unitPrice,
            'total_amount'       => round(abs($movement->quantity) * $movement->unitPrice, 2),
            'movement_date'      => $movement->movementDate,
            'reference_number'   => $movement->referenceNumber,
            'status'             => 'pending',
            'payload'            => $movement->toKraPayload(),
            'tenant_id'          => $this->currentTenantId(),
        ]);
    }

    private function updateAuditRecord(EtimsInvoice $record, InvoiceResponseDTO $response): void
    {
        $record->update([
            'status'       => $response->isSuccessful() ? 'submitted' : 'failed',
            'response'     => $response->toArray(),
            'receipt_number' => $response->receiptNumber,
            'qr_code'      => $response->qrCode,
            'submitted_at' => now(),
        ]);
    }

    private function markAuditRecordFailed(EtimsInvoice $record, string $reason): void
    {
        $record->update([
            'status'        => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    private function currentTenantId(): string|int|null
    {
        if (!($this->config['multi_tenancy']['enabled'] ?? false)) {
            return null;
        }

        return $this->tenantResolver?->tenantId();
    }
}
