<?php

declare(strict_types=1);

use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;
use Flavytech\Etims\Events\StockMovementFailed;
use Flavytech\Etims\Events\StockMovementRecorded;
use Flavytech\Etims\Events\StockSyncFailed;
use Flavytech\Etims\Events\StockSynced;
use Flavytech\Etims\Exceptions\EtimsApiException;
use Flavytech\Etims\Facades\Etims;
use Flavytech\Etims\Jobs\RecordStockMovementJob;
use Flavytech\Etims\Jobs\SyncStockJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// ========================================================================================
// Helpers
// ========================================================================================

function makeTestStockItem(string $code = 'BEER-500ML'): StockItemDTO
{
    return StockItemDTO::make([
        'item_code'     => $code,
        'item_name'     => 'Lager Beer 500ml',
        'item_category' => '10101501',
        'unit_price'    => 150.00,
        'tax_type_code' => 'E',
        'quantity'      => 500,
        'unit_of_measure' => 'BT',
    ]);
}

function makeTestMovement(string $itemCode = 'BEER-500ML', string $type = '01'): StockMovementDTO
{
    return StockMovementDTO::make([
        'item_code'       => $itemCode,
        'movement_type'   => $type,
        'quantity'        => 100,
        'unit_price'      => 120.00,
        'movement_date'   => '2024-01-15',
        'reference_number' => 'REF-001',
    ]);
}

// ========================================================================================
// Stock Item Master Sync Tests
// ========================================================================================

it('syncs a stock item and fires StockSynced event', function () {
    Event::fake();
    Etims::fake();

    $stockItem = makeTestStockItem();
    $response  = Etims::syncStock($stockItem);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->itemCode)->toBe('BEER-500ML');

    Etims::assertStockSynced('BEER-500ML');

    Event::assertDispatched(StockSynced::class, function (StockSynced $e) {
        return $e->stockItem->itemCode === 'BEER-500ML';
    });
});

it('fires StockSyncFailed event when stock sync fails', function () {
    Event::fake();
    Etims::fake()->failStockSyncWith(new EtimsApiException('KRA rejected item', 422));

    expect(fn() => Etims::syncStock(makeTestStockItem()))
        ->toThrow(EtimsApiException::class);

    Event::assertDispatched(StockSyncFailed::class);
    Event::assertNotDispatched(StockSynced::class);
});

it('returns typed StockResponseDTO (not a bare bool)', function () {
    Etims::fake();

    $response = Etims::syncStock(makeTestStockItem());

    expect($response)->toBeInstanceOf(StockResponseDTO::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->resultCode)->toBe('000');
});

it('can stub a specific stock response per item code', function () {
    $stubbed = StockResponseDTO::fromKraResponse('CUSTOM-ITEM', [
        'resultCd'  => '000',
        'resultMsg' => 'OK',
        'data'      => ['itemCd' => 'KRA-ITEM-999'],
    ]);

    Etims::fake()->respondToStockSync('CUSTOM-ITEM', $stubbed);

    $response = Etims::syncStock(makeTestStockItem('CUSTOM-ITEM'));

    expect($response->kraItemCode)->toBe('KRA-ITEM-999');
});

it('asserts no stock was synced when nothing happened', function () {
    $fake = Etims::fake();
    $fake->assertNoStockSynced();
});

it('asserts stock sync count', function () {
    $fake = Etims::fake();

    Etims::syncStock(makeTestStockItem('ITEM-A'));
    Etims::syncStock(makeTestStockItem('ITEM-B'));
    Etims::syncStock(makeTestStockItem('ITEM-C'));

    $fake->assertStockSyncedCount(3);
});

it('can assert stock sync with a custom matcher', function () {
    $fake = Etims::fake();

    Etims::syncStock(makeTestStockItem('EXCISABLE-BEER'));

    $fake->assertStockSyncedMatching(
        fn(StockItemDTO $item) => $item->taxTypeCode === 'E' && $item->itemCode === 'EXCISABLE-BEER'
    );
});

// ========================================================================================
// Stock Item Queue Tests
// ========================================================================================

it('dispatches SyncStockJob when queuing a stock sync', function () {
    Queue::fake();
    Etims::fake();

    Etims::queueStockSync(makeTestStockItem('QUEUED-ITEM'));

    Etims::assertStockSyncQueued('QUEUED-ITEM');
    Queue::assertPushedOn('etims', SyncStockJob::class);
});

it('dispatches one SyncStockJob per item in a bulk sync', function () {
    Queue::fake();
    Etims::fake();

    $count = Etims::queueBulkStockSync([
        makeTestStockItem('BULK-ITEM-A'),
        makeTestStockItem('BULK-ITEM-B'),
        makeTestStockItem('BULK-ITEM-C'),
    ]);

    expect($count)->toBe(3);
    Queue::assertPushed(SyncStockJob::class, 3);
});

// ========================================================================================
// Stock Movement Tests
// ========================================================================================

it('records a stock movement and fires StockMovementRecorded event', function () {
    Event::fake();
    Etims::fake();

    $movement = StockMovementDTO::purchase('BEER-500ML', 500, 120.00, 'P000000000S', '2024-01-15', 'PO-001');
    $response = Etims::recordStockMovement($movement);

    expect($response->isSuccessful())->toBeTrue();

    Etims::assertMovementRecorded('BEER-500ML');

    Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $e) {
        return $e->movement->itemCode === 'BEER-500ML'
            && $e->movement->movementType === StockMovementDTO::TYPE_PURCHASE;
    });
});

it('fires StockMovementFailed when movement recording fails', function () {
    Event::fake();
    Etims::fake()->failMovementWith(new EtimsApiException('KRA rejected movement', 422));

    expect(fn() => Etims::recordStockMovement(makeTestMovement()))
        ->toThrow(EtimsApiException::class);

    Event::assertDispatched(StockMovementFailed::class);
    Event::assertNotDispatched(StockMovementRecorded::class);
});

it('asserts movement type specifically', function () {
    $fake = Etims::fake();

    $sale = StockMovementDTO::fromSale('WIDGET', 2, 5000.00, 'P000000000B', 'INV-001', '2024-01-15');
    Etims::recordStockMovement($sale);

    $fake->assertMovementRecordedOfType('WIDGET', StockMovementDTO::TYPE_SALE);
});

it('asserts no movements recorded when nothing happened', function () {
    $fake = Etims::fake();
    $fake->assertNoMovementsRecorded();
});

it('asserts exact movement count', function () {
    $fake = Etims::fake();

    Etims::recordStockMovement(makeTestMovement('ITEM-A', '01'));
    Etims::recordStockMovement(makeTestMovement('ITEM-B', '02'));

    $fake->assertMovementRecordedCount(2);
});

it('can assert movement with a custom matcher', function () {
    $fake = Etims::fake();

    $adjustment = StockMovementDTO::adjustment('MILK-1L', -12, 'Expired stock write-off', '2024-01-15');
    Etims::recordStockMovement($adjustment);

    $fake->assertMovementRecordedMatching(
        fn(StockMovementDTO $m) => $m->movementType === StockMovementDTO::TYPE_ADJUSTMENT
            && $m->quantity === -12.0
            && str_contains((string) $m->reason, 'Expired')
    );
});

// ========================================================================================
// Stock Movement Queue Tests
// ========================================================================================

it('dispatches RecordStockMovementJob when queuing a movement', function () {
    Queue::fake();
    Etims::fake();

    $movement = StockMovementDTO::purchase('QUEUED-ITEM', 100, 50.00, 'P000000000S', '2024-01-15');
    Etims::queueStockMovement($movement);

    Etims::assertStockMovementQueued('QUEUED-ITEM');
    Queue::assertPushedOn('etims', RecordStockMovementJob::class);
});

// ========================================================================================
// FakeEtimsClient helper accessors
// ========================================================================================

it('exposes synced stock items for custom assertions', function () {
    $fake = Etims::fake();

    Etims::syncStock(makeTestStockItem('ITEM-X'));
    Etims::syncStock(makeTestStockItem('ITEM-Y'));

    $items = $fake->syncedStockItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]->itemCode)->toBe('ITEM-X')
        ->and($items[1]->itemCode)->toBe('ITEM-Y');
});

it('exposes recorded movements for custom assertions', function () {
    $fake = Etims::fake();

    Etims::recordStockMovement(StockMovementDTO::purchase('ITEM-P', 10, 100, 'P000000000S', '2024-01-15'));
    Etims::recordStockMovement(StockMovementDTO::adjustment('ITEM-A', -3, 'Damage', '2024-01-15'));

    $movements = $fake->recordedMovements();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->movementType)->toBe(StockMovementDTO::TYPE_PURCHASE)
        ->and($movements[1]->movementType)->toBe(StockMovementDTO::TYPE_ADJUSTMENT);
});
