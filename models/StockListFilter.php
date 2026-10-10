<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The Ordering filter on the stock list. Free of the database.
 */
class StockListFilter
{
    private const OPTIONS = [
        ['', 'All'],
        ['reorder', 'Needs reorder'],
        ['ordered', 'Ordered'],
        ['overdue', 'Overdue delivery'],
    ];

    /** @return array<int, array{0: string, 1: string}> value and label for each choice */
    public static function options(): array
    {
        return self::OPTIONS;
    }

    /** A known choice, or '' (all) for anything else, so a made-up value in the address shows everything */
    public static function normalise(string $selected): string
    {
        $values = \array_column(self::OPTIONS, 0);

        return \in_array($selected, $values, true) ? $selected : '';
    }

    /**
     * @param array<int, array<string, mixed>> $products Rows as StockController::formatProduct() shapes them
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $products, string $selected): array
    {
        $keep = match ($selected) {
            'reorder' => static fn(array $product): bool => $product['stock_state'] === 'reorder',
            'ordered' => static fn(array $product): bool => $product['stock_state'] === 'ordered',
            'overdue' => static fn(array $product): bool => $product['on_order_state'] === 'overdue',
            default => static fn(array $product): bool => true,
        };

        return \array_values(\array_filter($products, $keep));
    }
}
