<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * Takes stock off the shelf for an order that has just been paid: its own
 * product and every kit part, one audit row each, and the status flip at zero.
 */
class StockDeduction
{
    private StockLevels $levels;
    private StockMovements $movements;

    public function __construct(
        private OsmiumPDO $db,
        private StockCalculator $calculator,
        private ?int $outOfStockStatusId,
        private ?int $inStockStatusId,
    ) {
        $this->levels = new StockLevels($db);
        $this->movements = new StockMovements($db);
    }

    /**
     * Safe to call again for the same order: a line and product already recorded as a sale is skipped.
     *
     * @param array{id: int, items: array<int, array{id: int, product_id: int, quantity: int, parts: array}>} $order As ShopOrderService::getOrder() returns it
     * @return array<int, array{order_item_id: int, product_id: int, taken: int, short: int, remaining: int}> What came off the shelf, for tracked products only
     */
    public function deductForPaidOrder(array $order): array
    {
        $ownsTransaction = !$this->db->inTransaction(); // PDO has no nested transactions: respect an open one
        if ($ownsTransaction) $this->db->beginTransaction();

        try {
            $results = [];
            foreach ($order['items'] as $item) {
                foreach ($this->calculator->unitsByProduct([$item]) as $productId => $units) {
                    $result = $this->deductOne(orderId: (int)$order['id'], orderItemId: (int)$item['id'], productId: $productId, units: $units);
                    if ($result !== null) $results[] = $result;
                }
            }

            if ($ownsTransaction) $this->db->endTransaction();

            return $results;
        } catch (\Throwable $e) {
            if ($ownsTransaction) $this->db->cancelTransaction();
            throw $e;
        }
    }

    /** @return array{order_item_id: int, product_id: int, taken: int, short: int, remaining: int}|null null when untracked or already done */
    private function deductOne(int $orderId, int $orderItemId, int $productId, int $units): ?array
    {
        $onHand = $this->levels->getForUpdate($productId);
        $untracked = $onHand === null;
        if ($untracked) return null;

        $alreadyDone = $this->movements->exists($orderItemId, $productId, 'sale');
        if ($alreadyDone) return null;

        $outcome = $this->calculator->deduct(onHand: $onHand, wanted: $units);

        $this->levels->set($productId, $outcome['remaining']);
        $this->movements->record(
            productId: $productId,
            orderId: $orderId,
            orderItemId: $orderItemId,
            changeQty: -$outcome['taken'],
            shortQty: $outcome['short'],
            reason: 'sale',
        );
        $this->flipStatus($productId, $outcome['remaining']);

        return ['order_item_id' => $orderItemId, 'product_id' => $productId] + $outcome;
    }

    private function flipStatus(int $productId, int $quantity): void
    {
        $statusesNotConfigured = $this->outOfStockStatusId === null || $this->inStockStatusId === null;
        if ($statusesNotConfigured) return;

        $current = $this->levels->productStatus($productId);
        if ($current === null) return;

        $newStatus = $this->calculator->statusAfter($quantity, $current, $this->outOfStockStatusId, $this->inStockStatusId);
        if ($newStatus !== null) $this->levels->setProductStatus($productId, $newStatus);
    }
}
