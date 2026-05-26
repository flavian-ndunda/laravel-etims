<?php

declare(strict_types=1);

namespace Flavytech\Etims\Jobs;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\Events\StockMovementFailed;
use Flavytech\Etims\Events\StockMovementRecorded;
use Flavytech\Etims\Exceptions\EtimsApiException;
use Flavytech\Etims\Models\EtimsStockMovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RecordStockMovementJob
 *
 * Queue job for asynchronous stock movement reporting to KRA.
 *
 * Stock movements (purchases, sales-driven reductions, adjustments, transfers)
 * must be reported to KRA. This job handles that asynchronously so that
 * a slow KRA API response never blocks your POS checkout flow.
 *
 * Critical for compliance: every invoice should trigger a corresponding
 * stock movement for the sold items. The recommended pattern is:
 *
 *   Listen to InvoiceSubmitted → dispatch RecordStockMovementJob per line item
 *
 * This ensures stock movements are only reported AFTER KRA has accepted the
 * invoice, maintaining consistency between your invoice and stock records.
 */
class RecordStockMovementJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;
    public int $timeout;

    /** @var int[] */
    public array $backoff;

    public function __construct(
        public readonly StockMovementDTO $movement,
        public readonly string|int|null $tenantId = null,
    ) {
        $config = config('etims.queue', []);

        $this->tries   = (int) ($config['max_tries'] ?? 5);
        $this->timeout = (int) ($config['timeout'] ?? 60);
        $this->backoff = $config['backoff'] ?? [10, 30, 60, 120, 300];
    }

    public function handle(EtimsClientContract $client): void
    {
        Log::info('[eTIMS SDK] Processing queued stock movement', [
            'item_code'     => $this->movement->itemCode,
            'movement_type' => $this->movement->movementType,
            'quantity'      => $this->movement->quantity,
            'tenant_id'     => $this->tenantId,
            'attempt'       => $this->attempts(),
        ]);

        $this->updateRecord('processing', ['last_attempt_at' => now(), 'attempt_count' => $this->attempts()]);

        try {
            $response = $client->recordStockMovement($this->movement);

            if ($response->isSuccessful()) {
                $this->updateRecord('recorded', [
                    'response'    => $response->toArray(),
                    'recorded_at' => now(),
                ]);

                event(new StockMovementRecorded($this->movement, $response, $this->tenantId));

                Log::info('[eTIMS SDK] Stock movement recorded successfully', [
                    'item_code'     => $this->movement->itemCode,
                    'movement_type' => $this->movement->movementTypeLabel(),
                    'quantity'      => $this->movement->quantity,
                ]);
            } else {
                $this->updateRecord('failed', ['failure_reason' => $response->resultMessage]);
                $this->fail(new EtimsApiException(
                    "KRA rejected stock movement for [{$this->movement->itemCode}]: {$response->resultMessage}",
                    0,
                    $response->resultCode,
                ));
            }

        } catch (EtimsApiException $e) {
            $this->updateRecord('failed', ['failure_reason' => $e->getMessage()]);

            if (!$e->isRetryable()) {
                $this->fail($e);
                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('[eTIMS SDK] Stock movement permanently failed', [
            'item_code' => $this->movement->itemCode,
            'type'      => $this->movement->movementTypeLabel(),
            'exception' => $exception->getMessage(),
        ]);

        $this->updateRecord('failed', ['failure_reason' => $exception->getMessage()]);

        event(new StockMovementFailed($this->movement, $exception, $this->tenantId));
    }

    public function retryAfter(): int
    {
        return $this->backoff[$this->attempts() - 1] ?? 60;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function updateRecord(string $status, array $extra = []): void
    {
        try {
            EtimsStockMovement::where('item_code', $this->movement->itemCode)
                ->where('reference_number', $this->movement->referenceNumber)
                ->when($this->tenantId, fn($q) => $q->where('tenant_id', $this->tenantId))
                ->latest()
                ->first()
                ?->update(array_merge(['status' => $status], $extra));
        } catch (Throwable $e) {
            Log::warning('[eTIMS SDK] Failed to update stock movement record', [
                'item_code' => $this->movement->itemCode,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
