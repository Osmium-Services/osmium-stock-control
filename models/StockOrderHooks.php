<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The order page's stock panel (order.panels) and its "Return items to stock" button (order.action).
 */
class StockOrderHooks
{
    private const ACTION = 'return_stock';

    /**
     * order.panels: what this order took from stock, and the return button once it is refunded.
     * An order that touched no tracked product gets no panel.
     *
     * @param array{order: array, osmium: object} $payload
     */
    public static function orderPanel(array $payload): string
    {
        $order = $payload['order'];
        $movements = (new StockMovements($payload['osmium']->dataSource))->forOrder((int) $order['id']);
        $summary = StockOrderSummary::from($movements);

        $hasRows = $summary['rows'] !== [];
        if (!$hasRows) return '';

        $isRefunded = $order['status'] === 'refunded';
        $showReturn = $isRefunded && $summary['canReturn'];
        $returnedAll = $isRefunded && !$summary['canReturn'];

        $cspNonce = \Osmium\Core\OsmiumSecurity::requestNonce(); // The panel's inline script is blocked without it

        \ob_start();
        require __DIR__ . '/../views/stock/order-panel.phtml';

        return (string) \ob_get_clean();
    }

    /**
     * order.action: put a refunded order's items back on the shelf. Null for actions that aren't ours.
     *
     * @param array{action: string, orderId: int, osmium: object, admin?: object} $payload
     * @throws \RuntimeException When the order is not refunded or has nothing to return
     */
    public static function orderAction(array $payload): ?array
    {
        $notOurs = $payload['action'] !== self::ACTION;
        if ($notOurs) return null;

        $db = $payload['osmium']->dataSource;
        $orderId = (int) $payload['orderId'];

        $notRefunded = (new StockMovements($db))->orderStatus($orderId) !== 'refunded';
        if ($notRefunded) throw new \RuntimeException('Only a refunded order can be put back into stock.');

        $config = StockControlConfig::get();
        $units = (new StockReturn($db, new StockCalculator(), $config->outOfStockStatusId, $config->inStockStatusId))->returnOrder($orderId);

        $admin = $payload['admin'] ?? null;
        if ($admin !== null) {
            $admin->model->changelog->log(
                description: "Returned {$units} item(s) from refunded order {$orderId} to stock",
                recordType: 'order',
                recordId: $orderId,
            );
        }

        return ['success' => true, 'action' => self::ACTION, 'id' => $orderId, 'units' => $units];
    }
}
