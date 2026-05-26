<?php

declare(strict_types=1);

namespace Flavytech\Etims\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EtimsStockItem
 *
 * Audit trail for every stock item master synchronization attempt with KRA.
 *
 * KRA requires that every item you sell is registered in their system before
 * it appears on a fiscal invoice. This model tracks the registration status
 * of each item so you can build dashboards showing:
 *   - Which items are pending KRA registration
 *   - Which items were rejected and why
 *   - The KRA-assigned item code for each registered item
 *
 * @property int $id
 * @property string $item_code              Your internal item code
 * @property string|null $kra_item_code     KRA's assigned code (set after successful sync)
 * @property string $item_name
 * @property string $status                 pending|synced|failed
 * @property array $payload                 Full payload sent to KRA
 * @property array|null $response           KRA response
 * @property string|null $failure_reason
 * @property int $attempt_count
 * @property string|int|null $tenant_id
 * @property \Carbon\Carbon|null $synced_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EtimsStockItem extends Model
{
    protected $fillable = [
        'item_code',
        'kra_item_code',
        'item_name',
        'status',
        'payload',
        'response',
        'failure_reason',
        'attempt_count',
        'last_attempt_at',
        'tenant_id',
        'synced_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'response'        => 'array',
        'synced_at'       => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempt_count'   => 'integer',
    ];

    public function getTable(): string
    {
        return config('etims.tables.stock_items', 'etims_stock_items');
    }

    // Scopes

    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('status', 'synced');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    public function scopeForTenant(Builder $query, string|int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
