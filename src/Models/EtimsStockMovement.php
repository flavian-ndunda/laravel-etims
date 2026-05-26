<?php

declare(strict_types=1);

namespace Flavytech\Etims\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EtimsStockMovement
 *
 * Audit trail for every stock movement reported to KRA.
 *
 * Stock movements are the transactional record of what happened to inventory:
 * purchases, sales, adjustments, transfers, imports, exports.
 * KRA uses these to cross-check your invoice data and detect tax evasion.
 *
 * This model gives you a query-friendly store of all movement history,
 * useful for:
 *   - Compliance reports (all movements in a period)
 *   - Reconciliation (match movements against invoices)
 *   - Failed movement recovery
 *
 * @property int $id
 * @property string $item_code
 * @property string $movement_type          KRA stockIOTyCd (01-09)
 * @property string $movement_type_label    Human-readable (Purchase, Sale, etc.)
 * @property float $quantity
 * @property float $unit_price
 * @property float $total_amount
 * @property string $movement_date
 * @property string|null $reference_number
 * @property string $status                 pending|recorded|failed
 * @property array $payload
 * @property array|null $response
 * @property string|null $failure_reason
 * @property int $attempt_count
 * @property string|int|null $tenant_id
 * @property \Carbon\Carbon|null $recorded_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EtimsStockMovement extends Model
{
    protected $fillable = [
        'item_code',
        'movement_type',
        'movement_type_label',
        'quantity',
        'unit_price',
        'total_amount',
        'movement_date',
        'reference_number',
        'status',
        'payload',
        'response',
        'failure_reason',
        'attempt_count',
        'last_attempt_at',
        'tenant_id',
        'recorded_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'response'        => 'array',
        'quantity'        => 'float',
        'unit_price'      => 'float',
        'total_amount'    => 'float',
        'recorded_at'     => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempt_count'   => 'integer',
    ];

    public function getTable(): string
    {
        return config('etims.tables.stock_movements', 'etims_stock_movements');
    }

    // Scopes

    public function scopeRecorded(Builder $query): Builder
    {
        return $query->where('status', 'recorded');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeForItem(Builder $query, string $itemCode): Builder
    {
        return $query->where('item_code', $itemCode);
    }

    public function scopeForTenant(Builder $query, string|int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeInPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('movement_date', [$from, $to]);
    }

    public function isRecorded(): bool
    {
        return $this->status === 'recorded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
