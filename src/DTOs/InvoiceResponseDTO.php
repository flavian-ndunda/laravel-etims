<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * InvoiceResponseDTO
 *
 * Represents KRA's response after an invoice submission or status check.
 *
 * This DTO normalizes the KRA response into clean PHP conventions.
 * The host application should never parse raw KRA JSON — always use this DTO.
 */
final class InvoiceResponseDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $resultCode,
        public readonly string $resultMessage,
        public readonly ?string $internalData,     // KRA internal reference
        public readonly ?string $qrCode,           // KRA-generated QR code string
        public readonly ?string $receiptNumber,    // KRA receipt number
        public readonly ?string $sdcId,            // Secure Device Controller ID
        public readonly ?string $sdcDateTime,      // Timestamp from KRA
        public readonly array $rawResponse = [],   // Preserved for debugging
    ) {}

    /**
     * Build from KRA's raw API response array.
     *
     * @param array<string, mixed> $response
     */
    public static function fromKraResponse(array $response): self
    {
        $resultCode = (string) ($response['resultCd'] ?? $response['result_cd'] ?? '');
        $success    = in_array($resultCode, ['000', '0000', '00000000'], true);

        return new self(
            success:        $success,
            resultCode:     $resultCode,
            resultMessage:  (string) ($response['resultMsg'] ?? $response['result_msg'] ?? ''),
            internalData:   $response['data']['intrlData'] ?? null,
            qrCode:         $response['data']['qrCodeUrl'] ?? $response['data']['qrCode'] ?? null,
            receiptNumber:  $response['data']['rcptNo'] ?? null,
            sdcId:          $response['data']['sdcId'] ?? null,
            sdcDateTime:    $response['data']['sdcDateTime'] ?? null,
            rawResponse:    $response,
        );
    }

    /**
     * Was this a successful submission?
     *
     * Use this for control flow in jobs and controllers.
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Is this invoice pending (submitted but not yet confirmed)?
     *
     * KRA may return a pending status for asynchronous processing.
     */
    public function isPending(): bool
    {
        return in_array($this->resultCode, ['001', '002'], true);
    }

    /**
     * Convert to array for storage or event payloads.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success'        => $this->success,
            'result_code'    => $this->resultCode,
            'result_message' => $this->resultMessage,
            'internal_data'  => $this->internalData,
            'qr_code'        => $this->qrCode,
            'receipt_number' => $this->receiptNumber,
            'sdc_id'         => $this->sdcId,
            'sdc_date_time'  => $this->sdcDateTime,
        ];
    }
}
