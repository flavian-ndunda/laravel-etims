<?php

declare(strict_types=1);

namespace Flavytech\Etims\Services;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\DTOs\BranchDTO;
use Flavytech\Etims\DTOs\BranchResponseDTO;
use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\DTOs\PinValidationResponseDTO;
use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;
use Flavytech\Etims\Http\EtimsHttpClient;

/**
 * EtimsClient
 *
 * Implements EtimsClientContract by delegating HTTP calls to EtimsHttpClient
 * and mapping responses to typed DTOs.
 *
 * This class owns the API endpoint paths and payload assembly logic.
 * The EtimsHttpClient below it handles authentication, retries, and logging.
 * The EtimsManager above it handles multi-tenancy, idempotency, and events.
 *
 * Architecture note: endpoint paths are defined here rather than config to keep
 * them versioned with the SDK code. If KRA changes an endpoint URL, a SDK
 * version bump is the correct communication to consumers.
 */
class EtimsClient implements EtimsClientContract
{
    private const ENDPOINT_AUTH           = '/auth/selectInitOsdcInfo';
    private const ENDPOINT_INVOICE        = '/trnsSales/saveTrnsSalesOsdc';
    private const ENDPOINT_INVOICE_STATUS = '/trnsSales/selectTrnsSalesOsdc';
    private const ENDPOINT_PIN_VALIDATE   = '/user/selectUserInfo';
    private const ENDPOINT_STOCK_SAVE     = '/stock/saveStockOsdc';
    private const ENDPOINT_STOCK_MOVEMENT = '/stock/saveStockIOOsdc';
    private const ENDPOINT_BRANCH_SAVE    = '/branches/saveBrancheOsdc';
    private const ENDPOINT_BRANCH_LIST    = '/branches/selectBrancheList';

    public function __construct(
        private readonly EtimsHttpClient $httpClient,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function authenticate(): string
    {
        return $this->httpClient->authenticate();
    }

    /**
     * {@inheritdoc}
     */
    public function submitInvoice(InvoiceDTO $invoice): InvoiceResponseDTO
    {
        $payload  = $invoice->toKraPayload();
        $response = $this->httpClient->post(self::ENDPOINT_INVOICE, $payload);

        return InvoiceResponseDTO::fromKraResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function getInvoiceStatus(string $invoiceNumber): InvoiceResponseDTO
    {
        $response = $this->httpClient->get(self::ENDPOINT_INVOICE_STATUS, [
            'invcNo' => $invoiceNumber,
        ]);

        return InvoiceResponseDTO::fromKraResponse($response);
    }

    /**
     * {@inheritdoc}
     */
    public function validatePin(string $pin): PinValidationResponseDTO
    {
        $response = $this->httpClient->get(self::ENDPOINT_PIN_VALIDATE, [
            'custTpin' => $pin,
        ]);

        return PinValidationResponseDTO::fromKraResponse($pin, $response);
    }

    /**
     * {@inheritdoc}
     */
    public function syncStock(StockItemDTO $stockItem): StockResponseDTO
    {
        $response = $this->httpClient->post(self::ENDPOINT_STOCK_SAVE, $stockItem->toKraPayload());

        return StockResponseDTO::fromKraResponse($stockItem->itemCode, $response);
    }

    /**
     * {@inheritdoc}
     */
    public function recordStockMovement(StockMovementDTO $movement): StockResponseDTO
    {
        $response = $this->httpClient->post(self::ENDPOINT_STOCK_MOVEMENT, $movement->toKraPayload());

        return StockResponseDTO::fromKraResponse($movement->itemCode, $response);
    }

    /**
     * {@inheritdoc}
     */
    public function saveBranch(BranchDTO $branch): BranchResponseDTO
    {
        $response = $this->httpClient->post(self::ENDPOINT_BRANCH_SAVE, $branch->toKraPayload());

        return BranchResponseDTO::fromKraResponse($branch->branchId, $response);
    }

    /**
     * {@inheritdoc}
     */
    public function getBranches(): array
    {
        $response = $this->httpClient->get(self::ENDPOINT_BRANCH_LIST);
        $branches = $response['data']['bhfList'] ?? $response['data'] ?? [];

        return array_map(
            fn(array $b) => BranchResponseDTO::fromKraResponse($b['bhfId'] ?? '', $response),
            $branches
        );
    }
}
