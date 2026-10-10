<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

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
}
