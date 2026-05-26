<?php

declare(strict_types=1);

use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceLineDTO;
use Flavytech\Etims\Exceptions\EtimsValidationException;

// ========================================================================================
// InvoiceDTO Unit Tests
// ========================================================================================

it('creates an invoice DTO from a valid array', function () {
    $invoice = InvoiceDTO::make([
        'invoice_number' => 'INV-2024-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 11600.00,
        'vat_amount'     => 1600.00,
        'invoice_date'   => '2024-01-15',
        'invoice_type'   => 'S',
    ]);

    expect($invoice->invoiceNumber)->toBe('INV-2024-001')
        ->and($invoice->supplierPin)->toBe('P000000000A')
        ->and($invoice->totalAmount)->toBe(11600.00)
        ->and($invoice->vatAmount)->toBe(1600.00)
        ->and($invoice->invoiceType)->toBe('S')
        ->and($invoice->currency)->toBe('KES'); // default
});

it('throws a validation exception when required fields are missing', function () {
    InvoiceDTO::make([
        'invoice_number' => 'INV-001',
        // missing supplier_pin, buyer_pin, total_amount, vat_amount, invoice_date
    ]);
})->throws(EtimsValidationException::class);

it('throws a validation exception for invalid invoice type', function () {
    InvoiceDTO::make([
        'invoice_number' => 'INV-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 100.00,
        'vat_amount'     => 16.00,
        'invoice_date'   => '2024-01-15',
        'invoice_type'   => 'X', // invalid
    ]);
})->throws(EtimsValidationException::class, 'invoice_type must be S');

it('serializes to correct KRA payload format', function () {
    $invoice = InvoiceDTO::make([
        'invoice_number' => 'INV-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 11600.00,
        'vat_amount'     => 1600.00,
        'taxable_amount' => 10000.00,
        'invoice_date'   => '2024-01-15',
        'invoice_type'   => 'S',
        'payment_type'   => 'CASH',
    ]);

    $payload = $invoice->toKraPayload();

    expect($payload)->toMatchArray([
        'invcNo'   => 'INV-001',
        'tpin'     => 'P000000000A',
        'custTpin' => 'P000000000B',
        'totAmt'   => 11600.00,
        'vatAmt'   => 1600.00,
        'taxblAmt' => 10000.00,
        'rcptTyCd' => 'S',
        'pmtTyCd'  => 'CASH',
    ]);
});

it('generates a deterministic idempotency key', function () {
    $invoiceA = InvoiceDTO::make([
        'invoice_number' => 'INV-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 100.00,
        'vat_amount'     => 16.00,
        'invoice_date'   => '2024-01-15',
    ]);

    $invoiceB = InvoiceDTO::make([
        'invoice_number' => 'INV-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 100.00,
        'vat_amount'     => 16.00,
        'invoice_date'   => '2024-01-15',
    ]);

    // Same data → same key
    expect($invoiceA->resolveIdempotencyKey())->toBe($invoiceB->resolveIdempotencyKey());
});

it('generates different idempotency keys for different invoices', function () {
    $invoiceA = InvoiceDTO::make([
        'invoice_number' => 'INV-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 100.00,
        'vat_amount'     => 16.00,
        'invoice_date'   => '2024-01-15',
    ]);

    $invoiceB = InvoiceDTO::make([
        'invoice_number' => 'INV-002', // different number
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 200.00,   // different amount
        'vat_amount'     => 32.00,
        'invoice_date'   => '2024-01-15',
    ]);

    expect($invoiceA->resolveIdempotencyKey())->not->toBe($invoiceB->resolveIdempotencyKey());
});

it('accepts a custom idempotency key', function () {
    $invoice = InvoiceDTO::make([
        'invoice_number'  => 'INV-001',
        'supplier_pin'    => 'P000000000A',
        'buyer_pin'       => 'P000000000B',
        'total_amount'    => 100.00,
        'vat_amount'      => 16.00,
        'invoice_date'    => '2024-01-15',
        'idempotency_key' => 'my-custom-key-12345',
    ]);

    expect($invoice->resolveIdempotencyKey())->toBe('my-custom-key-12345');
});

it('serializes line items using KRA payload format', function () {
    $line = InvoiceLineDTO::make([
        'item_number'    => 1,
        'item_code'      => 'ITEM-001',
        'item_name'      => 'Test Widget',
        'quantity'       => 2.0,
        'unit_price'     => 5000.00,
        'taxable_amount' => 10000.00,
        'vat_amount'     => 1600.00,
        'total_amount'   => 11600.00,
        'tax_type_code'  => 'A',
    ]);

    $payload = $line->toKraPayload();

    expect($payload)->toMatchArray([
        'itemSeq'  => 1,
        'itemCd'   => 'ITEM-001',
        'itemNm'   => 'Test Widget',
        'qty'      => 2.0,
        'prc'      => 5000.00,
        'vatAmt'   => 1600.00,
        'totAmt'   => 11600.00,
        'taxTyCd'  => 'A',
    ]);
});
