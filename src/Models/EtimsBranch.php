<?php

declare(strict_types=1);

namespace Flavytech\Etims\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EtimsBranch
 *
 * Audit trail for branch registration and management operations with KRA.
 *
 * @property int $id
 * @property string $branch_id         KRA branch ID (bhfId)
 * @property string $branch_name
 * @property string $branch_address
 * @property string $status            active|inactive|suspended
 * @property string $kra_status        KRA status code (01, 02, 03)
 * @property array $payload
 * @property array|null $response
 * @property string|null $failure_reason
 * @property string|int|null $tenant_id
 * @property \Carbon\Carbon|null $registered_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EtimsBranch extends Model
{
    protected $fillable = [
        'branch_id',
        'branch_name',
        'branch_address',
        'manager_name',
        'phone',
        'email',
        'status',
        'kra_status',
        'payload',
        'response',
        'failure_reason',
        'tenant_id',
        'registered_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'response'        => 'array',
        'registered_at'   => 'datetime',
    ];

    public function getTable(): string
    {
        return config('etims.tables.branches', 'etims_branches');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForTenant(Builder $query, string|int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
