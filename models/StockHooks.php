<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Checkout\Services\ShopOrderException;

/**
 * The hook handlers core calls. Each takes the hook's payload array.
 */
class StockHooks
{
    /**
     * order.paid: take the order's stock off the shelf. Core fires this once per
     * order, after the payment has been recorded.
     *
     * @param array<string, mixed> $payload orderId, order (with items and their parts), dataSource
     */
    public static function orderPaid(array $payload): void
    {
        $config = StockControlConfig::get();

        (new StockDeduction(
            db: $payload['dataSource'],
            calculator: new StockCalculator(),
            outOfStockStatusId: $config->outOfStockStatusId,
            inStockStatusId: $config->inStockStatusId,
        ))->deductForPaidOrder($payload['order']);
    }

    /**
     * basket.line.check: refuse a line that, with the rest of the basket, wants more
     * of a tracked product than is on the shelf.
     *
     * @param array<string, mixed> $payload productId, quantity, parts, otherLines, dataSource
     * @throws ShopOrderException
     */
    public static function basketLineCheck(array $payload): ?array
    {
        $line = [
            'product_id' => $payload['productId'],
            'quantity' => $payload['quantity'],
            'parts' => $payload['parts'],
        ];

        self::refuseIfShort(items: [...$payload['otherLines'], $line], database: $payload['dataSource']);

        return null;
    }

    /**
     * order.check: the same test over the whole basket, just before the order is written.
     *
     * @param array<string, mixed> $payload lines, dataSource
     * @throws ShopOrderException
     */
    public static function orderCheck(array $payload): ?array
    {
        self::refuseIfShort(items: $payload['lines'], database: $payload['dataSource']);

        return null;
    }

    private static function refuseIfShort(array $items, OsmiumPDO $database): void
    {
        $calculator = new StockCalculator();
        $levels = new StockLevels($database);
        $units = $calculator->unitsByProduct($items);
        $onHand = [];

        foreach (\array_keys($units) as $productId) {
            $onHand[$productId] = $levels->get($productId);
        }

        $short = $calculator->shortages(units: $units, onHand: $onHand);
        $isShort = $short !== [];
        if (!$isShort) return;

        $available = \reset($short);
        throw new ShopOrderException("Only {$available} in stock");
    }
}
