<?php

declare(strict_types=1);

namespace Flavytech\Etims\Contracts;

use Flavytech\Etims\DTOs\BranchDTO;
use Flavytech\Etims\DTOs\BranchResponseDTO;
use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\DTOs\PinValidationResponseDTO;
use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;

/**
 * EtimsClientContract
 *
 * Defines the public contract for any eTIMS API client implementation.
 *
 * By programming against this interface rather than a concrete class,
 * you can:
 *   - Swap the real client for a fake/mock in tests
 *   - Add a caching decorator without touching business logic
 *   - Implement a different transport (e.g. gRPC if KRA ever supports it)
 *
 * All implementations must be stateless — no mutable state between calls.
 */
interface EtimsClientContract
{
    /**
     * Authenticate with the KRA API and return a bearer token.
     *
     * Implementations should cache the token and return the cached value
     * until it is close to expiry (see config etims.cache.ttl_buffer).
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsAuthException
     */
    public function authenticate(): string;

    /**
     * Submit a single invoice to KRA.
     *
     * The invoice must be fully validated before calling this method.
     * The implementation is responsible for:
     *   - Attaching auth headers
     *   - Serializing the DTO to the KRA-expected JSON format
     *   - Mapping the response to an InvoiceResponseDTO
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     * @throws \Flavytech\Etims\Exceptions\EtimsValidationException
     */
    public function submitInvoice(InvoiceDTO $invoice): InvoiceResponseDTO;

    /**
     * Check the current submission status of a previously submitted invoice.
     *
     * Use this to poll KRA when an invoice submission returned a pending state.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function getInvoiceStatus(string $invoiceNumber): InvoiceResponseDTO;

    /**
     * Validate a buyer or supplier KRA PIN against the KRA database.
     *
     * Essential for B2B invoices to ensure the counterparty PIN is valid.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function validatePin(string $pin): PinValidationResponseDTO;

    /**
     * Synchronize a stock item master record with KRA.
     *
     * This registers or updates an item in KRA's item registry.
     * Must be called before the item can appear on a fiscal invoice.
     * Returns a typed DTO (not a bare bool) so audit records and events
     * carry the full KRA response including the KRA-assigned item code.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function syncStock(StockItemDTO $stockItem): StockResponseDTO;

    /**
     * Record a stock movement (purchase, sale, adjustment, transfer) with KRA.
     *
     * KRA uses movement records to cross-check invoice data and track
     * inventory levels for excisable and regulated goods.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function recordStockMovement(StockMovementDTO $movement): StockResponseDTO;

    /**
     * Register or update a branch with KRA.
     *
     * Each physical branch must be individually registered before it can
     * submit invoices under its own branch ID.
     *
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function saveBranch(BranchDTO $branch): BranchResponseDTO;

    /**
     * Fetch all branches registered under the current PIN from KRA.
     *
     * @return BranchResponseDTO[]
     * @throws \Flavytech\Etims\Exceptions\EtimsApiException
     */
    public function getBranches(): array;
}
