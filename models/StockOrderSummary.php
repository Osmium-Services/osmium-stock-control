<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * Turns an order's ledger rows into what the order page shows. Free of the database.
 */
class StockOrderSummary
{
    /**
     * @param array<int, array<string, mixed>> $movements As StockMovements::forOrder() returns them
     * @return array{rows: array<int, array{product_id: int, name: string, taken: int, short: int, returned: int}>, canReturn: bool, hasShortfall: bool}
     */
    public static function from(array $movements): array
    {
        $rows = [];

        foreach ($movements as $movement) {
            $isOrderRow = \in_array($movement['reason'], ['sale', 'return'], true);
            if (!$isOrderRow) continue;

            $key = (int) $movement['order_item_id'] . ':' . (int) $movement['product_id'];
            $rows[$key] ??= [
                'product_id' => (int) $movement['product_id'],
                'name' => (string) ($movement['product_name'] ?? ('#' . (int) $movement['product_id'])),
                'taken' => 0,
                'short' => 0,
                'returned' => 0,
            ];

            $isSale = $movement['reason'] === 'sale';
            if ($isSale) {
                $rows[$key]['taken'] += -(int) $movement['change_qty'];
                $rows[$key]['short'] += (int) $movement['short_qty'];
            }
            if (!$isSale) $rows[$key]['returned'] += (int) $movement['change_qty'];
        }

        $rows = \array_values($rows);

        return [
            'rows' => $rows,
            'canReturn' => self::any($rows, static fn(array $row): bool => $row['taken'] > 0 && $row['returned'] === 0),
            'hasShortfall' => self::any($rows, static fn(array $row): bool => $row['short'] > 0),
        ];
    }

    private static function any(array $rows, callable $test): bool
    {
        foreach ($rows as $row) {
            $matches = $test($row);
            if ($matches) return true;
        }

        return false;
    }
}
