<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\StockItemDTO;
use Throwable;

/**
 * StockSyncFailed
 *
 * Fired when a stock item sync permanently fails (all retries exhausted).
 *
 * Listen to this to alert your team and flag the item for manual resubmission.
 */
final class StockSyncFailed
{
    public function __construct(
        public readonly StockItemDTO $stockItem,
        public readonly Throwable $exception,
        public readonly string|int|null $tenantId = null,
    ) {}
}
