<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * Keeps a product's core status in step with its stock: out of stock at zero,
 * back to in stock when it returns. Does nothing until both statuses are chosen.
 */
class StockStatusSync
{
    public function __construct(
        private StockLevels $levels,
        private StockCalculator $calculator,
        private ?int $outOfStockStatusId,
        private ?int $inStockStatusId,
    ) {}

    public function sync(int $productId, int $quantity): void
    {
        $statusesNotConfigured = $this->outOfStockStatusId === null || $this->inStockStatusId === null;
        if ($statusesNotConfigured) return;

        $current = $this->levels->productStatus($productId);
        if ($current === null) return;

        $newStatus = $this->calculator->statusAfter($quantity, $current, $this->outOfStockStatusId, $this->inStockStatusId);
        if ($newStatus !== null) $this->levels->setProductStatus($productId, $newStatus);
    }
}
