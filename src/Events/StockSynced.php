<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\StockItemDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;

/**
 * StockSynced
 *
 * Fired when an item master record is successfully synchronized with KRA.
 *
 * Listen to this event to:
 *   - Update your internal item master with KRA's assigned item code
 *   - Mark the item as "KRA-registered" in your POS/ERP
 *   - Trigger downstream workflows that depend on item registration
 *
 * Example:
 *   StockSynced::class => [UpdateItemKraCode::class]
 */
final class StockSynced
{
    public function __construct(
        public readonly StockItemDTO $stockItem,
        public readonly StockResponseDTO $response,
        public readonly string|int|null $tenantId = null,
    ) {}
}
