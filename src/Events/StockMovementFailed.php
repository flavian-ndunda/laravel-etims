<?php

declare(strict_types=1);

namespace Flavytech\Etims\Events;

use Flavytech\Etims\DTOs\StockMovementDTO;
use Throwable;

/**
 * StockMovementFailed
 *
 * Fired when a stock movement report permanently fails to reach KRA.
 */
final class StockMovementFailed
{
    public function __construct(
        public readonly StockMovementDTO $movement,
        public readonly Throwable $exception,
        public readonly string|int|null $tenantId = null,
    ) {}
}
