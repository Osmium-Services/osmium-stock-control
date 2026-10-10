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

    /**
     * Record units on order from the supplier and when they are expected. No units (null or zero)
     * clears both, since a date means nothing without units.
     *
     * @param string|null $expectedAt Y-m-d, or null when the date is not known yet
     * @throws \InvalidArgumentException For a product that is not tracked yet, or a date that is not a real date
     */
    public function setOnOrder(int $productId, ?int $quantity, ?string $expectedAt): void
    {
        $untracked = $this->levels->get($productId) === null;
        if ($untracked) throw new \InvalidArgumentException('Set a stock figure first: only tracked products can be on order');

        $nothingOnOrder = $quantity === null || $quantity <= 0;
        if ($nothingOnOrder) {
            $this->levels->setOnOrder(productId: $productId, quantity: null, expectedAt: null);
            return;
        }

        $badDate = $expectedAt !== null && !StockOnOrder::isValidDate($expectedAt);
        if ($badDate) throw new \InvalidArgumentException('The expected date is not a valid date');

        $this->levels->setOnOrder(productId: $productId, quantity: $quantity, expectedAt: $expectedAt);
    }

    /**
     * A delivery has arrived: put everything that was on order on the shelf and clear the on-order
     * figure. For a partial delivery, set the stock figure and the on-order units by hand instead.
     *
     * @return int Units put on the shelf
     * @throws \RuntimeException When the product is not tracked or nothing is on order
     */
    public function receiveOnOrder(int $productId): int
    {
        $units = 0;

        $this->inTransaction(function () use ($productId, &$units): void {
            $before = $this->levels->getForUpdate($productId);
            $onOrder = $this->levels->onOrderQuantity($productId);

            $nothingToReceive = $before === null || $onOrder === null;
            if ($nothingToReceive) throw new \RuntimeException('Nothing is on order for this product.');

            $units = $onOrder;
            $this->levels->set($productId, $before + $units);
            $this->levels->setOnOrder(productId: $productId, quantity: null, expectedAt: null);
            $this->record(productId: $productId, change: $units, note: 'Delivery received');
            $this->statusSync->sync($productId, $before + $units);
        });

        return $units;
    }

    /**
     * Keep a lasting note with the product, shown whenever its stock is edited. A blank note clears it.
     * A changed note is also recorded in the history, as an entry that moved no stock.
     *
     * @throws \InvalidArgumentException For a product that is not tracked yet, or a note over 255 characters
     */
    public function setNote(int $productId, ?string $note): void
    {
        $untracked = $this->levels->get($productId) === null;
        if ($untracked) throw new \InvalidArgumentException('Set a stock figure first: only tracked products can have a note');

        $note = $note === null ? '' : \trim($note);
        $tooLong = \mb_strlen($note) > 255;
        if ($tooLong) throw new \InvalidArgumentException('A note can be up to 255 characters');

        $newNote = $note === '' ? null : $note;
        $oldNote = $this->levels->note($productId);
        $isUnchanged = $newNote === $oldNote;
        if ($isUnchanged) return;

        $this->inTransaction(function () use ($productId, $newNote): void {
            $this->levels->setNote(productId: $productId, note: $newNote);
            $entry = $newNote === null ? 'Note cleared' : $newNote;
            $this->record(productId: $productId, change: 0, note: $entry);
        });
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
