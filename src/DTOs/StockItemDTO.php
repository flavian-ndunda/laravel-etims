<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * StockItemDTO
 *
 * Represents a stock/inventory item to be synchronized with KRA.
 *
 * KRA requires businesses to report their item master data so that
 * items can be correctly referenced on invoices. This is particularly
 * important for excisable goods.
 *
 * Usage:
 *   $item = StockItemDTO::make([
 *       'item_code'     => 'BEER-500ML',
 *       'item_name'     => 'Lager Beer 500ml',
 *       'item_category' => '10101501', // KRA item classification code
 *       'unit_price'    => 150.00,
 *       'tax_type_code' => 'E',         // Excisable
 *       'quantity'      => 500,
 *       'unit_of_measure' => 'BT',     // Bottle
 *   ]);
 */
final class StockItemDTO
{
    public function __construct(
        public readonly string $itemCode,
        public readonly string $itemName,
        public readonly string $itemCategory,
        public readonly float $unitPrice,
        public readonly string $taxTypeCode,
        public readonly float $quantity,
        public readonly string $unitOfMeasure,
        public readonly ?string $barcode = null,
        public readonly ?string $originCountry = null,
        public readonly ?float $packageQuantity = null,
        public readonly ?string $packageUnit = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return new self(
            itemCode:        $data['item_code'],
            itemName:        $data['item_name'],
            itemCategory:    $data['item_category'],
            unitPrice:       (float) $data['unit_price'],
            taxTypeCode:     $data['tax_type_code'] ?? 'A',
            quantity:        (float) ($data['quantity'] ?? 0),
            unitOfMeasure:   $data['unit_of_measure'] ?? 'EA',
            barcode:         $data['barcode'] ?? null,
            originCountry:   $data['origin_country'] ?? 'KE',
            packageQuantity: isset($data['package_quantity']) ? (float) $data['package_quantity'] : null,
            packageUnit:     $data['package_unit'] ?? null,
        );
    }

    /**
     * Serialize to KRA API payload.
     *
     * @return array<string, mixed>
     */
    public function toKraPayload(): array
    {
        return [
            'itemCd'     => $this->itemCode,
            'itemNm'     => $this->itemName,
            'itemClsCd'  => $this->itemCategory,
            'prc'        => $this->unitPrice,
            'taxTyCd'    => $this->taxTypeCode,
            'qty'        => $this->quantity,
            'qtyUnitCd'  => $this->unitOfMeasure,
            'bcd'        => $this->barcode,
            'orgnNatCd'  => $this->originCountry,
            'pkgUnitCd'  => $this->packageUnit,
            'pkgQty'     => $this->packageQuantity,
        ];
    }
}
