<?php

declare(strict_types=1);

namespace Flavytech\Etims\Facades;

use Flavytech\Etims\DTOs\InvoiceDTO;
use Flavytech\Etims\DTOs\InvoiceResponseDTO;
use Flavytech\Etims\DTOs\PinValidationResponseDTO;
use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\Services\EtimsManager;
use Flavytech\Etims\Testing\FakeEtimsClient;
use Illuminate\Support\Facades\Facade;

/**
 * Etims Facade
 *
 * Provides a convenient static API to EtimsManager via Laravel's
 * service container. Auto-discovered and registered by the ServiceProvider.
 *
 * Available methods (delegated to EtimsManager):
 *
 * @method static InvoiceResponseDTO submitInvoice(InvoiceDTO $invoice)                          Submit invoice synchronously
 * @method static string             queueInvoice(InvoiceDTO $invoice)                            Queue invoice for async submission
 * @method static PinValidationResponseDTO validatePin(string $pin)                               Validate a KRA PIN
 * @method static StockResponseDTO   syncStock(StockItemDTO $stockItem)                           Sync a stock item master with KRA (sync)
 * @method static void               queueStockSync(StockItemDTO $stockItem)                      Queue a stock item sync (async)
 * @method static int                queueBulkStockSync(StockItemDTO[] $stockItems)               Queue multiple stock items for sync
 * @method static StockResponseDTO   recordStockMovement(StockMovementDTO $movement)              Record a stock movement (sync)
 * @method static void               queueStockMovement(StockMovementDTO $movement)               Queue a stock movement (async)
 * @method static InvoiceResponseDTO getInvoiceStatus(string $invoiceNumber)                      Check invoice submission status
 * @method static \Illuminate\Database\Eloquent\Collection failedInvoices()                       Get permanently failed invoices
 * @method static \Illuminate\Database\Eloquent\Collection failedStockSyncs()                     Get permanently failed stock syncs
 * @method static \Illuminate\Database\Eloquent\Collection failedStockMovements()                 Get permanently failed stock movements
 * @method static string             retryFailedInvoice(int $recordId)                            Re-queue a failed invoice
 * @method static void               retryFailedStockSync(int $recordId)                          Re-queue a failed stock sync
 * @method static void               retryFailedStockMovement(int $recordId)                      Re-queue a failed stock movement
 * @method static FakeEtimsClient    fake()                                                        Enable fake mode for testing
 * @method static void               assertInvoiceSubmitted(string $number)                        Assert invoice submitted in tests
 * @method static void               assertNothingSubmitted()                                       Assert no submissions in tests
 * @method static void               assertInvoiceQueued(string $number)                           Assert invoice queued in tests
 * @method static void               assertStockSynced(string $itemCode)                           Assert stock item synced in tests
 * @method static void               assertMovementRecorded(string $itemCode)                      Assert movement recorded in tests
 * @method static void               assertMovementRecordedOfType(string $itemCode, string $type)  Assert movement type in tests
 * @method static void               assertStockSyncQueued(string $itemCode)                       Assert stock sync queued in tests
 * @method static void               assertStockMovementQueued(string $itemCode)                   Assert movement queued in tests
 *
 * @see EtimsManager
 */
class Etims extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EtimsManager::class;
    }
}
