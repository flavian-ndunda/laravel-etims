<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

/**
 * BranchResponseDTO
 *
 * Typed response from branch registration/update operations.
 */
final class BranchResponseDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $resultCode,
        public readonly string $resultMessage,
        public readonly string $branchId,
        public readonly array $rawResponse = [],
    ) {}

    /**
     * @param array<string, mixed> $response
     */
    public static function fromKraResponse(string $branchId, array $response): self
    {
        $resultCode = (string) ($response['resultCd'] ?? '');
        $success    = in_array($resultCode, ['000', '0000', '00000000'], true);

        return new self(
            success:       $success,
            resultCode:    $resultCode,
            resultMessage: (string) ($response['resultMsg'] ?? ''),
            branchId:      $branchId,
            rawResponse:   $response,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success'        => $this->success,
            'result_code'    => $this->resultCode,
            'result_message' => $this->resultMessage,
            'branch_id'      => $this->branchId,
        ];
    }
}
