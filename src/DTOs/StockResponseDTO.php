<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * StockResponseDTO
 *
 * Typed response returned by all stock synchronization operations.
 *
 * Replaces the bare `bool` that the original syncStock() returned.
 * A bare bool loses critical information: what KRA result code was returned,
 * whether the operation is pending, and what message KRA sent back.
 * This DTO preserves all of that for logging, auditing, and event payloads.
 *
 * Usage:
 *   $response = Etims::syncStock($stockItem);
 *
 *   if ($response->isSuccessful()) {
 *       logger()->info('KRA accepted item: ' . $response->itemCode);
 *   }
 */
final class StockResponseDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $resultCode,
        public readonly string $resultMessage,
        public readonly string $itemCode,           // echoed back for correlation
        public readonly ?string $kraItemCode = null, // KRA's assigned item code (may differ)
        public readonly array $rawResponse = [],
    ) {}

    /**
     * Build from KRA's raw API response.
     *
     * @param array<string, mixed> $response
     */
    public static function fromKraResponse(string $itemCode, array $response): self
    {
        $resultCode = (string) ($response['resultCd'] ?? '');
        $success    = in_array($resultCode, ['000', '0000', '00000000'], true);
        $data       = $response['data'] ?? [];

        return new self(
            success:       $success,
            resultCode:    $resultCode,
            resultMessage: (string) ($response['resultMsg'] ?? ''),
            itemCode:      $itemCode,
            kraItemCode:   $data['itemCd'] ?? $data['item_code'] ?? null,
            rawResponse:   $response,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success'        => $this->success,
            'result_code'    => $this->resultCode,
            'result_message' => $this->resultMessage,
            'item_code'      => $this->itemCode,
            'kra_item_code'  => $this->kraItemCode,
        ];
    }
}
