<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The dashboard card for products that need reordering, in the shape core's dashboard.widgets hook
 * takes. It only appears once a reorder level has been set somewhere, so a shop not using reorder
 * levels sees no card. Free of the database.
 */
class StockDashboardWidget
{
    private const MAX_LINES = 5;

    /**
     * @param array<int, array{id: int, name: string, quantity: int, reorder_level: int}> $rows Every product that has a reorder level
     * @return array<string, mixed>|null
     */
    public static function build(array $rows, StockCalculator $calculator): ?array
    {
        $noLevelsInUse = $rows === [];
        if ($noLevelsInUse) return null;

        $needing = self::needingReorder($rows, $calculator);

        return [
            'id' => 'stock-reorder',
            'title' => 'Stock to reorder',
            'icon' => 'bx-package',
            'roles' => ['admin', 'editor'],
            'order' => 20,
            'count' => \count($needing),
            'link' => ['label' => 'View all', 'route' => 'stock/?ordering=reorder'],
            'lines' => \array_map(self::line(...), \array_slice($needing, 0, self::MAX_LINES)),
            'emptyText' => 'Nothing needs reordering.',
        ];
    }

    /** @return array<int, array<string, mixed>> Most urgent first: furthest below its level, then by name */
    private static function needingReorder(array $rows, StockCalculator $calculator): array
    {
        $needing = \array_filter(
            $rows,
            static fn(array $row): bool => $calculator->stockState((int) $row['quantity'], (int) $row['reorder_level']) === 'reorder',
        );

        \usort($needing, static fn(array $a, array $b): int => [(int) $a['quantity'] - (int) $a['reorder_level'], $a['name']] <=> [(int) $b['quantity'] - (int) $b['reorder_level'], $b['name']]);

        return $needing;
    }

    /** @return array{text: string, sub: string, route: string} */
    private static function line(array $row): array
    {
        $quantity = (int) $row['quantity'];
        $left = $quantity > 0 ? "{$quantity} left" : 'none left';

        return [
            'text' => (string) $row['name'],
            'sub' => "{$left}, reorder at {$row['reorder_level']}",
            'route' => 'stock/edit/?id=' . (int) $row['id'],
        ];
    }
}
