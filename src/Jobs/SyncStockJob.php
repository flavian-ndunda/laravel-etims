<?php

declare(strict_types=1);

namespace Flavytech\Etims\Jobs;

use Flavytech\Etims\Contracts\EtimsClientContract;
use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\Events\StockSyncFailed;
use Flavytech\Etims\Events\StockSynced;
use Flavytech\Etims\Exceptions\EtimsApiException;
use Flavytech\Etims\Models\EtimsStockItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SyncStockJob
 *
 * Queue job for asynchronous stock item master synchronization with KRA.
 *
 * Mirrors the architecture of SubmitInvoiceJob exactly:
 * - Durable queue persistence (survives worker restarts)
 * - Exponential backoff between retries
 * - Retryable vs non-retryable error distinction
 * - Dead letter handling via failed()
 * - Audit record updates at every stage
 * - Event dispatch on success and permanent failure
 *
 * Use this for bulk item registration (importing a product catalogue into KRA)
 * since sending hundreds of items synchronously would timeout a web request.
 *
 * Usage:
 *   Etims::queueStockSync($stockItemDto);
 */
class SyncStockJob implements ShouldQueue
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
        public readonly StockItemDTO $stockItem,
        public readonly string|int|null $tenantId = null,
    ) {
        $config = config('etims.queue', []);

        $this->tries   = (int) ($config['max_tries'] ?? 5);
        $this->timeout = (int) ($config['timeout'] ?? 60);
        $this->backoff = $config['backoff'] ?? [10, 30, 60, 120, 300];
    }

    public function handle(EtimsClientContract $client): void
    {
        $itemCode = $this->stockItem->itemCode;

        Log::info('[eTIMS SDK] Processing queued stock sync', [
            'item_code' => $itemCode,
            'tenant_id' => $this->tenantId,
            'attempt'   => $this->attempts(),
        ]);

        $this->updateRecord('processing', ['last_attempt_at' => now(), 'attempt_count' => $this->attempts()]);

        try {
            $response = $client->syncStock($this->stockItem);

            if ($response->isSuccessful()) {
                $this->updateRecord('synced', [
                    'kra_item_code' => $response->kraItemCode,
                    'response'      => $response->toArray(),
                    'synced_at'     => now(),
                ]);

                event(new StockSynced($this->stockItem, $response, $this->tenantId));

                Log::info('[eTIMS SDK] Stock item synced successfully', [
                    'item_code'     => $itemCode,
                    'kra_item_code' => $response->kraItemCode,
                ]);
            } else {
                $this->updateRecord('failed', ['failure_reason' => $response->resultMessage]);
                $this->fail(new EtimsApiException(
                    "KRA rejected stock item [{$itemCode}]: {$response->resultMessage}",
                    0,
                    $response->resultCode,
                ));
            }

        } catch (EtimsApiException $e) {
            Log::error('[eTIMS SDK] Stock sync failed on attempt ' . $this->attempts(), [
                'item_code' => $itemCode,
                'error'     => $e->getMessage(),
                'retryable' => $e->isRetryable(),
            ]);

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
        Log::critical('[eTIMS SDK] Stock sync permanently failed', [
            'item_code' => $this->stockItem->itemCode,
            'tenant_id' => $this->tenantId,
            'exception' => $exception->getMessage(),
        ]);

        $this->updateRecord('failed', ['failure_reason' => $exception->getMessage()]);

        event(new StockSyncFailed($this->stockItem, $exception, $this->tenantId));
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
            EtimsStockItem::where('item_code', $this->stockItem->itemCode)
                ->when($this->tenantId, fn($q) => $q->where('tenant_id', $this->tenantId))
                ->latest()
                ->first()
                ?->update(array_merge(['status' => $status], $extra));
        } catch (Throwable $e) {
            Log::warning('[eTIMS SDK] Failed to update stock item record', [
                'item_code' => $this->stockItem->itemCode,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
