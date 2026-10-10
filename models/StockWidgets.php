<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The hook handler for core's dashboard.widgets.
 */
class StockWidgets
{
    /**
     * dashboard.widgets: the "Stock to reorder" card, or null when no reorder level is in use.
     *
     * @param array{osmium: object, role: string} $payload
     */
    public static function dashboard(array $payload): ?array
    {
        $rows = (new StockLevels($payload['osmium']->dataSource))->productsWithReorderLevel();

        return StockDashboardWidget::build(rows: $rows, calculator: new StockCalculator());
    }

    /**
     * dashboard.widgets: the "Deliveries overdue" card, or null when no delivery is late.
     *
     * @param array{osmium: object, role: string} $payload
     */
    public static function overdue(array $payload): ?array
    {
        $rows = (new StockLevels($payload['osmium']->dataSource))->productsOnOrder();

        return StockDashboardWidget::overdue(rows: $rows, today: \date('Y-m-d'));
    }
}
