<?php

declare(strict_types=1);

use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\Events\InvoiceFailed;
use Flavytech\Etims\Events\InvoiceQueued;
use Flavytech\Etims\Events\InvoiceSubmitted;
use Flavytech\Etims\Exceptions\EtimsApiException;
use Flavytech\Etims\Exceptions\EtimsIdempotencyException;
use Flavytech\Etims\Facades\Etims;
use Flavytech\Etims\Jobs\SubmitInvoiceJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// ========================================================================================
// EtimsManager Feature Tests — All using Etims::fake()
// ========================================================================================

function makeTestInvoice(string $number = 'INV-2024-001'): InvoiceDTO
{
    return InvoiceDTO::make([
        'invoice_number' => $number,
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 11600.00,
        'vat_amount'     => 1600.00,
        'invoice_date'   => '2024-01-15',
        'invoice_type'   => 'S',
        'payment_type'   => 'CASH',
    ]);
}

// ========================================================================================
// Direct Submission Tests
// ========================================================================================

it('submits an invoice and fires InvoiceSubmitted event', function () {
    Event::fake();
    Etims::fake();

    $invoice  = makeTestInvoice();
    $response = Etims::submitInvoice($invoice);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->receiptNumber)->toStartWith('RCPT-');

    Etims::assertInvoiceSubmitted('INV-2024-001');
    Event::assertDispatched(InvoiceSubmitted::class, function (InvoiceSubmitted $event) {
        return $event->invoice->invoiceNumber === 'INV-2024-001';
    });
});

it('fires InvoiceFailed event when submission fails', function () {
    Event::fake();
    Etims::fake()->failWith(new EtimsApiException('KRA is unreachable', 503));

    $invoice = makeTestInvoice();

    expect(fn() => Etims::submitInvoice($invoice))
        ->toThrow(EtimsApiException::class, 'KRA is unreachable');

    Event::assertDispatched(InvoiceFailed::class);
    Event::assertNotDispatched(InvoiceSubmitted::class);
});

it('throws idempotency exception on duplicate submission', function () {
    Etims::fake();

    $invoice = makeTestInvoice();

    // First submission — should succeed
    Etims::submitInvoice($invoice);

    // Second submission — same idempotency key → exception
    Etims::submitInvoice($invoice);
})->throws(EtimsIdempotencyException::class);

it('allows re-submission with different invoice number', function () {
    Etims::fake();

    Etims::submitInvoice(makeTestInvoice('INV-001'));
    Etims::submitInvoice(makeTestInvoice('INV-002')); // different invoice → no exception

    Etims::assertInvoiceSubmitted('INV-001');
    Etims::assertInvoiceSubmitted('INV-002');
});

it('records nothing when no invoices are submitted', function () {
    Etims::fake();
    Etims::assertNothingSubmitted();
});

// ========================================================================================
// Queue Tests
// ========================================================================================

it('dispatches a SubmitInvoiceJob when queuing an invoice', function () {
    Queue::fake();
    Event::fake();
    Etims::fake();

    $invoice = makeTestInvoice('INV-Q-001');
    Etims::queueInvoice($invoice);

    Etims::assertInvoiceQueued('INV-Q-001');
    Event::assertDispatched(InvoiceQueued::class);
});

it('dispatches the job to the configured etims queue', function () {
    Queue::fake();
    Etims::fake();

    Etims::queueInvoice(makeTestInvoice('INV-Q-002'));

    Queue::assertPushedOn('etims', SubmitInvoiceJob::class);
});

// ========================================================================================
// PIN Validation Tests
// ========================================================================================

it('validates a valid KRA PIN', function () {
    Etims::fake();

    $result = Etims::validatePin('P000000000A');

    expect($result->isValid())->toBeTrue()
        ->and($result->pin)->toBe('P000000000A')
        ->and($result->taxpayerName)->toBe('Test Taxpayer Ltd');
});

it('returns invalid for a PIN marked as invalid', function () {
    Etims::fake()->withInvalidPins(['P999999999Z']);

    $result = Etims::validatePin('P999999999Z');

    expect($result->isValid())->toBeFalse()
        ->and($result->taxpayerName)->toBeNull();
});

// ========================================================================================
// Fake Client Assertions
// ========================================================================================

it('can assert submission with a custom matcher', function () {
    $fake = Etims::fake();

    $invoice = InvoiceDTO::make([
        'invoice_number' => 'INV-MPESA-001',
        'supplier_pin'   => 'P000000000A',
        'buyer_pin'      => 'P000000000B',
        'total_amount'   => 5000.00,
        'vat_amount'     => 695.65,
        'invoice_date'   => '2024-01-15',
        'payment_type'   => 'MPESA',
    ]);

    Etims::submitInvoice($invoice);

    $fake->assertSubmittedMatching(
        fn(InvoiceDTO $i) => $i->paymentType === 'MPESA' && $i->totalAmount === 5000.00
    );
});

it('can assert submission count', function () {
    $fake = Etims::fake();

    Etims::submitInvoice(makeTestInvoice('INV-001'));
    Etims::submitInvoice(makeTestInvoice('INV-002'));
    Etims::submitInvoice(makeTestInvoice('INV-003'));

    $fake->assertSubmittedCount(3);
});

it('can configure stubbed responses per invoice', function () {
    $stubbedResponse = \Flavytech\Etims\DTOs\InvoiceResponseDTO::fromKraResponse([
        'resultCd'  => '000',
        'resultMsg' => 'OK',
        'data'      => [
            'rcptNo'    => 'CUSTOM-RECEIPT-999',
            'qrCodeUrl' => 'https://kra.go.ke/qr/999',
        ],
    ]);

    Etims::fake()->respondTo('INV-STUBBED-001', $stubbedResponse);

    $response = Etims::submitInvoice(makeTestInvoice('INV-STUBBED-001'));

    expect($response->receiptNumber)->toBe('CUSTOM-RECEIPT-999')
        ->and($response->qrCode)->toBe('https://kra.go.ke/qr/999');
});
