<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * What an admin does by hand: start tracking a product, change its figure, or
 * stop tracking it. Every change is an audit row with the reason 'manual'.
 */
class StockAdjustment
{
    private StockLevels $levels;
    private StockMovements $movements;
    private StockStatusSync $statusSync;

    public function __construct(
        private OsmiumPDO $db,
        StockCalculator $calculator,
        ?int $outOfStockStatusId,
        ?int $inStockStatusId,
    ) {
        $this->levels = new StockLevels($db);
        $this->movements = new StockMovements($db);
        $this->statusSync = new StockStatusSync($this->levels, $calculator, $outOfStockStatusId, $inStockStatusId);
    }

    /**
     * @throws \InvalidArgumentException For a negative figure or a product that does not exist
     */
    public function set(int $productId, int $quantity, ?string $note = null): void
    {
        $isNegative = $quantity < 0;
        if ($isNegative) throw new \InvalidArgumentException('Stock cannot be negative');

        $productMissing = $this->levels->productStatus($productId) === null;
        if ($productMissing) throw new \InvalidArgumentException('Product not found');

        $this->inTransaction(function () use ($productId, $quantity, $note): void {
            $before = $this->levels->getForUpdate($productId);
            $isUnchanged = $before === $quantity;
            if ($isUnchanged) return;

            $this->levels->set($productId, $quantity);
            $this->record(productId: $productId, change: $quantity - ($before ?? 0), note: $note);
            $this->statusSync->sync($productId, $quantity);
        });
    }

    /**
     * Set the figure at or below which the product shows up for reordering (null clears it).
     *
     * @throws \InvalidArgumentException For a negative level, or a product that is not tracked yet
     */
    public function setReorderLevel(int $productId, ?int $level): void
    {
        $isNegative = $level !== null && $level < 0;
        if ($isNegative) throw new \InvalidArgumentException('The reorder level cannot be negative');

        $untracked = $this->levels->get($productId) === null;
        if ($untracked) throw new \InvalidArgumentException('Set a stock figure first: only tracked products have a reorder level');

        $this->levels->setReorderLevel(productId: $productId, level: $level);
    }

    public function stopTracking(int $productId, ?string $note = null): void
    {
        $this->inTransaction(function () use ($productId, $note): void {
            $before = $this->levels->getForUpdate($productId);
            $isUntracked = $before === null;
            if ($isUntracked) return;

            $this->levels->stopTracking($productId);
            $this->record(productId: $productId, change: -$before, note: $note ?? 'Stopped tracking');
        });
    }

    private function record(int $productId, int $change, ?string $note): void
    {
        $this->movements->record(
            productId: $productId,
            orderId: null,
            orderItemId: null,
            changeQty: $change,
            shortQty: 0,
            reason: 'manual',
            note: $note,
        );
    }

    private function inTransaction(callable $work): void
    {
        $ownsTransaction = !$this->db->inTransaction(); // PDO has no nested transactions: respect an open one
        if ($ownsTransaction) $this->db->beginTransaction();

        try {
            $work();
            if ($ownsTransaction) $this->db->endTransaction();
        } catch (\Throwable $e) {
            if ($ownsTransaction) $this->db->cancelTransaction();
            throw $e;
        }
    }
}
