<?php

declare(strict_types=1);

use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\Exceptions\EtimsValidationException;

// ========================================================================================
// StockMovementDTO Unit Tests
// ========================================================================================

it('creates a movement DTO from a valid array', function () {
    $movement = StockMovementDTO::make([
        'item_code'     => 'BEER-500ML',
        'movement_type' => '01',          // Purchase
        'quantity'      => 500.0,
        'unit_price'    => 120.00,
        'movement_date' => '2024-01-15',
    ]);

    expect($movement->itemCode)->toBe('BEER-500ML')
        ->and($movement->movementType)->toBe('01')
        ->and($movement->quantity)->toBe(500.0)
        ->and($movement->unitPrice)->toBe(120.00)
        ->and($movement->unitOfMeasure)->toBe('EA'); // default
});

it('throws validation exception when required fields are missing', function () {
    StockMovementDTO::make([
        'item_code' => 'ITEM-001',
        // missing movement_type, quantity, movement_date
    ]);
})->throws(EtimsValidationException::class, 'Missing required');

it('throws validation exception for invalid movement type', function () {
    StockMovementDTO::make([
        'item_code'     => 'ITEM-001',
        'movement_type' => '99',          // invalid
        'quantity'      => 10,
        'movement_date' => '2024-01-15',
    ]);
})->throws(EtimsValidationException::class, 'Invalid movement_type');

it('creates a purchase movement using convenience factory', function () {
    $movement = StockMovementDTO::purchase(
        itemCode:    'MILK-1L',
        quantity:    200,
        unitPrice:   80.00,
        supplierPin: 'P000000000S',
        date:        '2024-01-15',
        poNumber:    'PO-2024-001',
    );

    expect($movement->movementType)->toBe(StockMovementDTO::TYPE_PURCHASE)
        ->and($movement->quantity)->toBe(200.0)        // always positive for purchases
        ->and($movement->supplierPin)->toBe('P000000000S')
        ->and($movement->referenceNumber)->toBe('PO-2024-001')
        ->and($movement->isInbound())->toBeTrue();
});

it('creates a sale movement using convenience factory', function () {
    $movement = StockMovementDTO::fromSale(
        itemCode:      'WIDGET-PRO',
        quantity:      2,
        unitPrice:     5000.00,
        customerPin:   'P000000000B',
        invoiceNumber: 'INV-001',
        date:          '2024-01-15',
    );

    expect($movement->movementType)->toBe(StockMovementDTO::TYPE_SALE)
        ->and($movement->quantity)->toBe(-2.0)         // negative — reduces stock
        ->and($movement->referenceNumber)->toBe('INV-001')
        ->and($movement->isInbound())->toBeFalse();
});

it('creates an adjustment movement using convenience factory', function () {
    $movement = StockMovementDTO::adjustment(
        itemCode: 'MILK-2L',
        quantity: -12.0,
        reason:   'Expired goods written off',
        date:     '2024-01-15',
    );

    expect($movement->movementType)->toBe(StockMovementDTO::TYPE_ADJUSTMENT)
        ->and($movement->quantity)->toBe(-12.0)
        ->and($movement->reason)->toBe('Expired goods written off')
        ->and($movement->unitPrice)->toBe(0.0);
});

it('returns correct human-readable label for each movement type', function () {
    $types = [
        ['01', 'Purchase'],
        ['02', 'Sale'],
        ['03', 'Return Inward'],
        ['04', 'Return Outward'],
        ['05', 'Adjustment'],
        ['06', 'Transfer Out'],
        ['07', 'Transfer In'],
        ['08', 'Import'],
        ['09', 'Export'],
    ];

    foreach ($types as [$code, $label]) {
        $movement = StockMovementDTO::make([
            'item_code'     => 'ITEM-001',
            'movement_type' => $code,
            'quantity'      => 1,
            'movement_date' => '2024-01-15',
        ]);
        expect($movement->movementTypeLabel())->toBe($label);
    }
});

it('serializes to correct KRA payload format', function () {
    $movement = StockMovementDTO::purchase('BEER-500ML', 100, 120.00, 'P000000000S', '2024-01-15', 'PO-001');

    $payload = $movement->toKraPayload();

    expect($payload)->toMatchArray([
        'itemCd'      => 'BEER-500ML',
        'stockIOTyCd' => '01',
        'qty'         => 100.0,
        'prc'         => 120.00,
        'totAmt'      => 12000.00, // 100 * 120
        'stockDt'     => '2024-01-15',
        'regrId'      => 'PO-001',
        'supTpin'     => 'P000000000S',
    ]);
});
