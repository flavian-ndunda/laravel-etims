<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * InvoiceLineDTO
 *
 * Represents a single line item within an invoice.
 *
 * KRA requires each item to carry its own tax classification code (taxTyCd),
 * which determines which VAT rate applies. The SDK does not auto-calculate
 * taxes — the host application is responsible for correct tax classification,
 * as this depends on business-specific rules.
 *
 * Tax Type Codes (KRA):
 *   A = Standard rate (16% VAT)
 *   B = Zero rated
 *   C = Exempt
 *   D = Non-VATable (e.g. insurance, financial services)
 *   E = Excisable goods with VAT
 *
 * Usage:
 *   $line = InvoiceLineDTO::make([
 *       'item_number'      => 1,
 *       'item_code'        => 'ITEM-001',
 *       'item_name'        => 'Widget Pro',
 *       'quantity'         => 2,
 *       'unit_price'       => 5000.00,
 *       'discount_amount'  => 0.00,
 *       'taxable_amount'   => 10000.00,
 *       'vat_amount'       => 1600.00,
 *       'total_amount'     => 11600.00,
 *       'tax_type_code'    => 'A',
 *   ]);
 */
final class InvoiceLineDTO
{
    public function __construct(
        public readonly int $itemNumber,
        public readonly string $itemCode,
        public readonly string $itemName,
        public readonly float $quantity,
        public readonly string $unitOfMeasure,
        public readonly float $unitPrice,
        public readonly float $discountAmount,
        public readonly float $taxableAmount,
        public readonly float $vatAmount,
        public readonly float $totalAmount,
        public readonly string $taxTypeCode,  // A, B, C, D, E
        public readonly ?string $itemCategory = null,
        public readonly ?string $barcode = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return new self(
            itemNumber:    (int) $data['item_number'],
            itemCode:      $data['item_code'],
            itemName:      $data['item_name'],
            quantity:      (float) $data['quantity'],
            unitOfMeasure: $data['unit_of_measure'] ?? 'EA', // EA = Each
            unitPrice:     (float) $data['unit_price'],
            discountAmount: (float) ($data['discount_amount'] ?? 0.0),
            taxableAmount:  (float) $data['taxable_amount'],
            vatAmount:      (float) $data['vat_amount'],
            totalAmount:    (float) $data['total_amount'],
            taxTypeCode:    $data['tax_type_code'] ?? 'A',
            itemCategory:   $data['item_category'] ?? null,
            barcode:        $data['barcode'] ?? null,
        );
    }

    /**
     * Serialize to KRA API payload format.
     *
     * @return array<string, mixed>
     */
    public function toKraPayload(): array
    {
        return [
            'itemSeq'     => $this->itemNumber,
            'itemCd'      => $this->itemCode,
            'itemNm'      => $this->itemName,
            'qty'         => $this->quantity,
            'qtyUnitCd'   => $this->unitOfMeasure,
            'prc'         => $this->unitPrice,
            'dcAmt'       => $this->discountAmount,
            'taxblAmt'    => $this->taxableAmount,
            'vatAmt'      => $this->vatAmount,
            'taxAmt'      => $this->vatAmount, // alias
            'totAmt'      => $this->totalAmount,
            'taxTyCd'     => $this->taxTypeCode,
            'itemClsCd'   => $this->itemCategory,
            'bcd'         => $this->barcode,
        ];
    }
}
