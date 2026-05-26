<?php

declare(strict_types=1);

namespace Flavytech\Etims\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EtimsInvoice
 *
 * Eloquent model representing the audit trail for every invoice submission.
 *
 * Every call to submitInvoice() or queueInvoice() creates a record here.
 * This gives you:
 *   - A full history of submission attempts
 *   - KRA receipt numbers and QR codes for submitted invoices
 *   - Failed invoices with error details for manual review
 *   - The original payload (useful if you need to resubmit)
 *
 * This is the primary table for your admin "eTIMS Submissions" dashboard.
 *
 * @property int $id
 * @property string $invoice_number
 * @property string $idempotency_key
 * @property string $status                pending|processing|submitted|failed|retrying
 * @property array $payload                Original KRA payload
 * @property array|null $response          KRA response when submitted
 * @property string|null $receipt_number   KRA-assigned receipt number
 * @property string|null $qr_code          KRA QR code data
 * @property string|null $failure_reason   Error message on failure
 * @property int $attempt_count            How many times submission was attempted
 * @property string|int|null $tenant_id    For multi-tenant systems
 * @property \Carbon\Carbon|null $submitted_at
 * @property \Carbon\Carbon|null $exhausted_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EtimsInvoice extends Model
{
    protected $table = 'etims_invoices'; // overridden by config if changed

    protected $fillable = [
        'invoice_number',
        'idempotency_key',
        'status',
        'payload',
        'response',
        'receipt_number',
        'qr_code',
        'failure_reason',
        'attempt_count',
        'last_attempt_at',
        'tenant_id',
        'submitted_at',
        'exhausted_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'response'        => 'array',
        'submitted_at'    => 'datetime',
        'exhausted_at'    => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempt_count'   => 'integer',
    ];

    // =========================================================================
    // Scopes — for filtering in your admin dashboard
    // =========================================================================

    /** Scope to only submitted (successful) invoices */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    /** Scope to only failed invoices awaiting manual intervention */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /** Scope to pending/in-flight invoices */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'processing', 'retrying']);
    }

    /** Scope to a specific tenant */
    public function scopeForTenant(Builder $query, string|int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'retrying'], true);
    }

    /**
     * Get the table name respecting config overrides.
     */
    public function getTable(): string
    {
        return config('etims.tables.invoices', 'etims_invoices');
    }
}
