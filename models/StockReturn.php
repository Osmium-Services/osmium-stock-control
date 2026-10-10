<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * Puts a refunded order's items back on the shelf: what each sale row really
 * took (never the shortfall), once, and only for products still tracked.
 */
class StockReturn
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
     * @return int Units put back on the shelf, across all products
     * @throws \RuntimeException When the order took no stock, or everything was already returned
     */
    public function returnOrder(int $orderId): int
    {
        $sales = \array_filter(
            $this->movements->forOrder($orderId),
            static fn(array $movement): bool => $movement['reason'] === 'sale' && (int) $movement['change_qty'] < 0,
        );
        $noSales = $sales === [];
        if ($noSales) throw new \RuntimeException('This order took nothing from stock.');

        $ownsTransaction = !$this->db->inTransaction(); // PDO has no nested transactions: respect an open one
        if ($ownsTransaction) $this->db->beginTransaction();

        try {
            $units = 0;
            foreach ($sales as $sale) {
                $units += $this->returnSale($orderId, $sale);
            }

            $nothingReturned = $units === 0;
            if ($nothingReturned) throw new \RuntimeException('Nothing left to return: it was already put back, or the products are no longer tracked.');

            if ($ownsTransaction) $this->db->endTransaction();

            return $units;
        } catch (\Throwable $e) {
            if ($ownsTransaction) $this->db->cancelTransaction();
            throw $e;
        }
    }

    /** @return int Units put back for this sale row (0 when skipped) */
    private function returnSale(int $orderId, array $sale): int
    {
        $productId = (int) $sale['product_id'];
        $orderItemId = (int) $sale['order_item_id'];

        $alreadyReturned = $this->movements->exists($orderItemId, $productId, 'return');
        if ($alreadyReturned) return 0;

        $onHand = $this->levels->getForUpdate($productId);
        $untracked = $onHand === null;
        if ($untracked) return 0;

        $units = -(int) $sale['change_qty'];
        $this->levels->set($productId, $onHand + $units);
        $this->movements->record(
            productId: $productId,
            orderId: $orderId,
            orderItemId: $orderItemId,
            changeQty: $units,
            shortQty: 0,
            reason: 'return',
        );
        $this->statusSync->sync($productId, $onHand + $units);

        return $units;
    }
}
