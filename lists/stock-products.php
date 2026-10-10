<?php

declare(strict_types=1);

/**
 * Products and their stock, rendered by Osmium\Modules\Admin\Core\ListView. Rows are shaped by StockController::formatProduct().
 */
return [
    'products' => [
        'id' => 'stock-products',
        'title' => 'Stock',
        'noun' => ['product', 'products'],
        'endpoint' => 'stock/action/',
        'emptyText' => 'No products yet.',
        'order' => null,
        'filters' => [
            [
                'key' => 'ordering',
                'label' => 'Ordering',
                'server' => true,
                'param' => 'ordering',
                'selected' => fn(array $context): string => (string) ($context['selectedOrdering'] ?? ''),
                'options' => [['', 'All'], ['reorder', 'Needs reorder']],
            ],
        ],
        'columns' => [
            [
                'key' => 'name',
                'label' => 'Product',
                'type' => 'text',
                'strong' => true,
                'fill' => true,
                'sub' => ['key' => 'reference', 'style' => 'plain'],
            ],
            ['key' => 'stock_label', 'label' => 'In stock', 'type' => 'text', 'nowrap' => true, 'sortKey' => 'stock_sort'],
            ['key' => 'reorder_label', 'label' => 'Reorder at', 'type' => 'text', 'nowrap' => true, 'sortKey' => 'reorder_label'],
            ['key' => 'on_order_html', 'label' => 'On order', 'type' => 'html', 'render' => fn(array $row, array $context): string => $row['on_order_html'], 'nowrap' => true, 'sortKey' => 'on_order_sort'],
            ['key' => 'state_label', 'label' => 'Ordering', 'type' => 'badge', 'text' => true, 'classKey' => 'state_class', 'nowrap' => true],
            ['key' => 'status_label', 'label' => 'Status', 'type' => 'text', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Set stock', 'icon' => 'bx-edit-alt', 'url' => '{edit_url}'],
            [
                'label' => 'Receive stock',
                'icon' => 'bx-package',
                'action' => 'receive',
                'confirm' => 'A delivery has arrived: add the units on order to the shelf and clear the on-order figure? For a partial delivery, use Set stock instead.',
                'visible' => fn(array $row): bool => (bool) $row['has_on_order'],
            ],
            [
                'label' => 'Stop tracking',
                'icon' => 'bx-x-circle',
                'action' => 'stop_tracking',
                'confirm' => 'Stop tracking this product? Its figure is removed and stock control leaves it alone.',
                'danger' => true,
                'visible' => fn(array $row): bool => (bool) $row['is_tracked'],
            ],
        ],
    ],
];
