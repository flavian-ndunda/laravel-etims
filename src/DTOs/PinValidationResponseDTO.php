<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * PinValidationResponseDTO
 *
 * Result of validating a KRA PIN against the KRA registry.
 */
final class PinValidationResponseDTO
{
    public function __construct(
        public readonly bool $isValid,
        public readonly string $pin,
        public readonly ?string $taxpayerName = null,
        public readonly ?string $taxpayerType = null, // Individual, Company, etc.
        public readonly ?string $resultCode = null,
        public readonly ?string $resultMessage = null,
    ) {}

    /**
     * @param array<string, mixed> $response
     */
    public static function fromKraResponse(string $pin, array $response): self
    {
        $resultCode = (string) ($response['resultCd'] ?? '');
        $isValid    = in_array($resultCode, ['000', '0000'], true);
        $data       = $response['data'] ?? [];

        return new self(
            isValid:        $isValid,
            pin:            $pin,
            taxpayerName:   $data['custNm'] ?? $data['taxpayer_name'] ?? null,
            taxpayerType:   $data['custTpin'] ?? $data['taxpayer_type'] ?? null,
            resultCode:     $resultCode,
            resultMessage:  $response['resultMsg'] ?? null,
        );
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }
}
