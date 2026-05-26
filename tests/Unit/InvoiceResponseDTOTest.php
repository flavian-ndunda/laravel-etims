<?php

declare(strict_types=1);

use Flavytech\Etims\DTOs\InvoiceResponseDTO;

it('parses a successful KRA response', function () {
    $response = InvoiceResponseDTO::fromKraResponse([
        'resultCd'  => '000',
        'resultMsg' => 'Processed Successfully',
        'data'      => [
            'rcptNo'      => 'RCPT-12345',
            'intrlData'   => 'INTERNAL-ABC',
            'qrCodeUrl'   => 'https://etims.kra.go.ke/qr/abc',
            'sdcId'       => 'SDC-001',
            'sdcDateTime' => '2024-01-15T10:30:00',
        ],
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->resultCode)->toBe('000')
        ->and($response->receiptNumber)->toBe('RCPT-12345')
        ->and($response->qrCode)->toBe('https://etims.kra.go.ke/qr/abc')
        ->and($response->internalData)->toBe('INTERNAL-ABC')
        ->and($response->sdcId)->toBe('SDC-001');
});

it('parses a failed KRA response', function () {
    $response = InvoiceResponseDTO::fromKraResponse([
        'resultCd'  => '101',
        'resultMsg' => 'Invalid invoice format',
        'data'      => [],
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->resultCode)->toBe('101')
        ->and($response->resultMessage)->toBe('Invalid invoice format')
        ->and($response->receiptNumber)->toBeNull();
});

it('identifies pending responses', function () {
    $response = InvoiceResponseDTO::fromKraResponse([
        'resultCd'  => '001',
        'resultMsg' => 'Processing',
        'data'      => [],
    ]);

    expect($response->isPending())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse();
});

it('converts to array for storage', function () {
    $response = InvoiceResponseDTO::fromKraResponse([
        'resultCd'  => '000',
        'resultMsg' => 'OK',
        'data'      => ['rcptNo' => 'RCPT-001'],
    ]);

    $array = $response->toArray();

    expect($array)->toHaveKeys(['success', 'result_code', 'result_message', 'receipt_number'])
        ->and($array['success'])->toBeTrue()
        ->and($array['receipt_number'])->toBe('RCPT-001');
});
