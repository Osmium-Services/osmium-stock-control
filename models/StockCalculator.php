<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The stock arithmetic, kept free of the database so it can be tested on its own.
 */
class StockCalculator
{
    /**
     * Units of each product an order takes: every line takes its own product,
     * plus each part of a kit, multiplied by the line quantity.
     *
     * @param array<int, array{product_id: int, quantity: int, parts: array<int, array{product_id: int, quantity: int}>}> $items Order items as ShopOrderService::getOrder() returns them
     * @return array<int, int> Units by product id, in the order products first appear
     */
    public function unitsByProduct(array $items): array
    {
        $units = [];

        foreach ($items as $item) {
            $lineQuantity = (int)$item['quantity'];
            $units[(int)$item['product_id']] = ($units[(int)$item['product_id']] ?? 0) + $lineQuantity;

            foreach ($item['parts'] ?? [] as $part) {
                $partProductId = (int)$part['product_id'];
                $units[$partProductId] = ($units[$partProductId] ?? 0) + $lineQuantity * (int)$part['quantity'];
            }
        }

        return $units;
    }

    /**
     * The products that want more than the shelf holds, with how many are there.
     * A product with no figure is not tracked, so it is never short.
     *
     * @param array<int, int> $units Units wanted by product id
     * @param array<int, ?int> $onHand Shelf quantity by product id, null or missing when not tracked
     * @return array<int, int> Available units by product id, only for the products that are short
     */
    public function shortages(array $units, array $onHand): array
    {
        $short = [];

        foreach ($units as $productId => $wanted) {
            $shelf = $onHand[$productId] ?? null;
            $isTracked = $shelf !== null;
            $available = \max(0, (int)$shelf);
            $isShort = $isTracked && $wanted > $available;
            if ($isShort) $short[$productId] = $available;
        }

        return $short;
    }

    /**
     * Take stock off the shelf without ever going below zero. The shelf is
     * never made negative: whatever it could not cover is reported as a shortfall.
     *
     * @return array{taken: int, short: int, remaining: int}
     */
    public function deduct(int $onHand, int $wanted): array
    {
        $available = \max(0, $onHand);
        $taken = \min($available, \max(0, $wanted));

        return [
            'taken' => $taken,
            'short' => \max(0, $wanted) - $taken,
            'remaining' => $available - $taken,
        ];
    }

    /**
     * The status a product should move to after its stock changed, or null to leave it.
     * Running out always moves it to out of stock. Stock arriving only moves it back
     * if it is currently out of stock, so a pre-order or discontinued status is never
     * overwritten.
     */
    public function statusAfter(int $quantity, int $currentStatusId, int $outOfStockStatusId, int $inStockStatusId): ?int
    {
        $isOut = $currentStatusId === $outOfStockStatusId;

        if ($quantity <= 0) return $isOut ? null : $outOfStockStatusId;

        return $isOut ? $inStockStatusId : null;
    }
}
