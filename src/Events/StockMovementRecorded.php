<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\StockMovementDTO;
use Flavytech\Etims\DTOs\StockResponseDTO;

/**
 * StockMovementRecorded
 *
 * Fired when a stock movement is successfully reported to KRA.
 *
 * Listen to this to:
 *   - Update your internal stock levels after KRA confirmation
 *   - Trigger reorder alerts
 *   - Sync with your accounting system
 *
 * Architecture note: Stock levels in your application should only be
 * updated AFTER this event fires, not at the time of the movement,
 * to keep your stock in sync with what KRA has accepted.
 */
final class StockMovementRecorded
{
    public function __construct(
        public readonly StockMovementDTO $movement,
        public readonly StockResponseDTO $response,
        public readonly string|int|null $tenantId = null,
    ) {}
}
