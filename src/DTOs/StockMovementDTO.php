<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

use Flavytech\Etims\Exceptions\EtimsValidationException;

/**
 * StockMovementDTO
 *
 * Represents a stock movement event to be reported to KRA.
 *
 * KRA distinguishes between the item master (StockItemDTO — the "what exists")
 * and stock movements (this DTO — the "what happened to it"). Both must be
 * reported for excisable goods, goods subject to import levies, and businesses
 * on VAT scheme with stock-based reporting requirements.
 *
 * Movement Types (KRA stockIOTyCd):
 *   01 = Purchase (incoming stock from supplier)
 *   02 = Sale (outgoing stock on invoice)
 *   03 = Return Inward (customer return)
 *   04 = Return Outward (return to supplier)
 *   05 = Stock Adjustment (stocktake correction, write-off)
 *   06 = Transfer Out (branch transfer outgoing)
 *   07 = Transfer In (branch transfer incoming)
 *   08 = Import
 *   09 = Export
 *
 * Usage:
 *   // Record a stock purchase from a supplier
 *   $movement = StockMovementDTO::make([
 *       'item_code'       => 'BEER-500ML',
 *       'movement_type'   => '01',           // Purchase
 *       'quantity'        => 500,
 *       'unit_of_measure' => 'BT',
 *       'unit_price'      => 120.00,
 *       'supplier_pin'    => 'P000000000S',
 *       'movement_date'   => '2024-01-15',
 *       'reference_number' => 'PO-2024-001',
 *   ]);
 *
 *   // Record a stock write-off adjustment
 *   $movement = StockMovementDTO::make([
 *       'item_code'      => 'MILK-1L',
 *       'movement_type'  => '05',            // Adjustment
 *       'quantity'       => -12,             // Negative = reduction
 *       'unit_price'     => 0.00,
 *       'movement_date'  => '2024-01-15',
 *       'reason'         => 'Expired goods written off',
 *   ]);
 */
final class StockMovementDTO
{
    // Movement type constants for readable code
    public const TYPE_PURCHASE        = '01';
    public const TYPE_SALE            = '02';
    public const TYPE_RETURN_INWARD   = '03';
    public const TYPE_RETURN_OUTWARD  = '04';
    public const TYPE_ADJUSTMENT      = '05';
    public const TYPE_TRANSFER_OUT    = '06';
    public const TYPE_TRANSFER_IN     = '07';
    public const TYPE_IMPORT          = '08';
    public const TYPE_EXPORT          = '09';

    private const VALID_TYPES = ['01', '02', '03', '04', '05', '06', '07', '08', '09'];

    public function __construct(
        public readonly string $itemCode,
        public readonly string $movementType,       // KRA stockIOTyCd code
        public readonly float $quantity,            // Positive = in, Negative = out
        public readonly string $unitOfMeasure,
        public readonly float $unitPrice,
        public readonly string $movementDate,       // Y-m-d
        public readonly ?string $referenceNumber = null,  // PO number, invoice number, etc.
        public readonly ?string $supplierPin = null,      // for purchases (TYPE_PURCHASE, TYPE_IMPORT)
        public readonly ?string $customerPin = null,      // for sales (TYPE_SALE, TYPE_EXPORT)
        public readonly ?string $reason = null,           // for adjustments (TYPE_ADJUSTMENT)
        public readonly ?string $originCountry = null,    // for imports (TYPE_IMPORT)
        public readonly ?string $destinationCountry = null, // for exports (TYPE_EXPORT)
        public readonly ?string $branchId = null,
    ) {}

    /**
     * Named constructor from array.
     *
     * @param array<string, mixed> $data
     * @throws EtimsValidationException
     */
    public static function make(array $data): self
    {
        self::validate($data);

        return new self(
            itemCode:           $data['item_code'],
            movementType:       (string) $data['movement_type'],
            quantity:           (float) $data['quantity'],
            unitOfMeasure:      $data['unit_of_measure'] ?? 'EA',
            unitPrice:          (float) ($data['unit_price'] ?? 0.0),
            movementDate:       $data['movement_date'],
            referenceNumber:    $data['reference_number'] ?? null,
            supplierPin:        $data['supplier_pin'] ?? null,
            customerPin:        $data['customer_pin'] ?? null,
            reason:             $data['reason'] ?? null,
            originCountry:      $data['origin_country'] ?? null,
            destinationCountry: $data['destination_country'] ?? null,
            branchId:           $data['branch_id'] ?? null,
        );
    }

    /**
     * Convenience factory for a purchase movement.
     * Semantically clearer than remembering movement type '01'.
     */
    public static function purchase(string $itemCode, float $quantity, float $unitPrice, string $supplierPin, string $date, ?string $poNumber = null): self
    {
        return new self(
            itemCode:        $itemCode,
            movementType:    self::TYPE_PURCHASE,
            quantity:        abs($quantity), // purchases are always positive
            unitOfMeasure:   'EA',
            unitPrice:       $unitPrice,
            movementDate:    $date,
            referenceNumber: $poNumber,
            supplierPin:     $supplierPin,
        );
    }

    /**
     * Convenience factory for a sale-driven stock reduction.
     * Typically called automatically when an invoice is submitted.
     */
    public static function fromSale(string $itemCode, float $quantity, float $unitPrice, string $customerPin, string $invoiceNumber, string $date): self
    {
        return new self(
            itemCode:        $itemCode,
            movementType:    self::TYPE_SALE,
            quantity:        -abs($quantity), // sales always reduce stock
            unitOfMeasure:   'EA',
            unitPrice:       $unitPrice,
            movementDate:    $date,
            referenceNumber: $invoiceNumber,
            customerPin:     $customerPin,
        );
    }

    /**
     * Convenience factory for a write-off or stocktake correction.
     *
     * @param float $quantity Positive to add stock, negative to reduce
     */
    public static function adjustment(string $itemCode, float $quantity, string $reason, string $date): self
    {
        return new self(
            itemCode:      $itemCode,
            movementType:  self::TYPE_ADJUSTMENT,
            quantity:      $quantity,
            unitOfMeasure: 'EA',
            unitPrice:     0.0,
            movementDate:  $date,
            reason:        $reason,
        );
    }

    /**
     * Human-readable label for the movement type.
     */
    public function movementTypeLabel(): string
    {
        return match ($this->movementType) {
            self::TYPE_PURCHASE       => 'Purchase',
            self::TYPE_SALE           => 'Sale',
            self::TYPE_RETURN_INWARD  => 'Return Inward',
            self::TYPE_RETURN_OUTWARD => 'Return Outward',
            self::TYPE_ADJUSTMENT     => 'Adjustment',
            self::TYPE_TRANSFER_OUT   => 'Transfer Out',
            self::TYPE_TRANSFER_IN    => 'Transfer In',
            self::TYPE_IMPORT         => 'Import',
            self::TYPE_EXPORT         => 'Export',
            default                   => 'Unknown',
        };
    }

    /**
     * Whether this movement increases stock (positive quantity direction).
     */
    public function isInbound(): bool
    {
        return in_array($this->movementType, [
            self::TYPE_PURCHASE,
            self::TYPE_RETURN_INWARD,
            self::TYPE_TRANSFER_IN,
            self::TYPE_IMPORT,
        ], true) || $this->quantity > 0;
    }

    /**
     * Serialize to KRA API payload format.
     *
     * @return array<string, mixed>
     */
    public function toKraPayload(): array
    {
        return [
            'itemCd'       => $this->itemCode,
            'stockIOTyCd'  => $this->movementType,
            'qty'          => $this->quantity,
            'qtyUnitCd'    => $this->unitOfMeasure,
            'prc'          => $this->unitPrice,
            'totAmt'       => round(abs($this->quantity) * $this->unitPrice, 2),
            'stockDt'      => $this->movementDate,
            'regrId'       => $this->referenceNumber,
            'custTpin'     => $this->customerPin,
            'supTpin'      => $this->supplierPin,
            'remark'       => $this->reason,
            'orgnNatCd'    => $this->originCountry,
            'dstNatCd'     => $this->destinationCountry,
            'bhfId'        => $this->branchId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws EtimsValidationException
     */
    private static function validate(array $data): void
    {
        $required = ['item_code', 'movement_type', 'quantity', 'movement_date'];
        $missing  = array_filter($required, fn($k) => !isset($data[$k]) || $data[$k] === '');

        if (!empty($missing)) {
            throw new EtimsValidationException(
                'Missing required StockMovementDTO fields: ' . implode(', ', $missing)
            );
        }

        if (!in_array((string) $data['movement_type'], self::VALID_TYPES, true)) {
            throw new EtimsValidationException(
                "Invalid movement_type [{$data['movement_type']}]. Valid codes: " . implode(', ', self::VALID_TYPES)
            );
        }
    }
}
