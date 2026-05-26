<?php

declare(strict_types=1);

namespace Flavytech\Etims\DTOs;

use Flavytech\Etims\Exceptions\EtimsValidationException;

/**
 * BranchDTO
 *
 * Represents a business branch to be registered or updated with KRA.
 *
 * KRA requires each branch of a business to be individually registered
 * in eTIMS. Each branch gets its own branch ID (bhfId) which must be
 * included on all invoices and stock movements originating from that branch.
 *
 * Branch status codes:
 *   01 = Active
 *   02 = Closed
 *   03 = Suspended
 *
 * Usage:
 *   $branch = BranchDTO::make([
 *       'branch_id'      => '01',
 *       'branch_name'    => 'Nairobi CBD Branch',
 *       'branch_address' => 'Tom Mboya Street, Nairobi',
 *       'manager_name'   => 'John Kamau',
 *       'phone'          => '0712345678',
 *       'email'          => 'cbd@example.co.ke',
 *       'status'         => '01',
 *   ]);
 *   Etims::registerBranch($branch);
 */
final class BranchDTO
{
    public const STATUS_ACTIVE    = '01';
    public const STATUS_CLOSED    = '02';
    public const STATUS_SUSPENDED = '03';

    public function __construct(
        public readonly string $branchId,
        public readonly string $branchName,
        public readonly string $branchAddress,
        public readonly string $managerName,
        public readonly string $phone,
        public readonly string $status,
        public readonly ?string $email = null,
        public readonly ?string $fax = null,
        public readonly ?string $postalAddress = null,
        public readonly ?string $province = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $sector = null,
        public readonly ?string $localGovt = null,
        public readonly ?string $longitude = null,
        public readonly ?string $latitude = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws EtimsValidationException
     */
    public static function make(array $data): self
    {
        $required = ['branch_id', 'branch_name', 'branch_address', 'manager_name', 'phone'];
        $missing  = array_filter($required, fn($k) => empty($data[$k]));

        if (!empty($missing)) {
            throw new EtimsValidationException(
                'Missing required BranchDTO fields: ' . implode(', ', $missing)
            );
        }

        return new self(
            branchId:      $data['branch_id'],
            branchName:    $data['branch_name'],
            branchAddress: $data['branch_address'],
            managerName:   $data['manager_name'],
            phone:         $data['phone'],
            status:        $data['status'] ?? self::STATUS_ACTIVE,
            email:         $data['email'] ?? null,
            fax:           $data['fax'] ?? null,
            postalAddress: $data['postal_address'] ?? null,
            province:      $data['province'] ?? null,
            city:          $data['city'] ?? null,
            district:      $data['district'] ?? null,
            sector:        $data['sector'] ?? null,
            localGovt:     $data['local_govt'] ?? null,
            longitude:     $data['longitude'] ?? null,
            latitude:      $data['latitude'] ?? null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toKraPayload(): array
    {
        return [
            'bhfId'      => $this->branchId,
            'bhfNm'      => $this->branchName,
            'bhfSttsCd'  => $this->status,
            'cmrcAddr'   => $this->branchAddress,
            'mgrNm'      => $this->managerName,
            'mgrTelNo'   => $this->phone,
            'mgrEmail'   => $this->email,
            'faxNo'      => $this->fax,
            'pstlAddr'   => $this->postalAddress,
            'prvncNm'    => $this->province,
            'dstrtNm'    => $this->district,
            'sctrNm'     => $this->sector,
            'locDesc'    => $this->localGovt,
            'lon'        => $this->longitude,
            'lat'        => $this->latitude,
        ];
    }
}
