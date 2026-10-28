<?php

declare(strict_types=1);

/**
 * One product's stock history, rendered by Osmium\Modules\Admin\Core\ListView. Rows are StockHistory::row() lines;
 * StockController::history() sets the list title to the product name.
 */
return [
    'page' => [
        'title' => 'Stock History',
        'buttons' => fn(array $context): array => [
            ['label' => 'Set stock', 'icon' => 'bx-edit-alt', 'variant' => 'outline-secondary', 'href' => 'stock/edit/?id=' . (int) $context['productId']],
            ['label' => 'All stock', 'icon' => 'bx-arrow-back', 'variant' => 'label-secondary', 'href' => 'stock/'],
        ],
    ],
    'history' => [
        'id' => 'stock-history',
        'title' => 'Stock history',
        'noun' => ['change', 'changes'],
        'emptyText' => 'No changes recorded yet.',
        'order' => null,
        'columns' => [
            ['key' => 'when', 'label' => 'When', 'type' => 'text', 'nowrap' => true],
            [
                'key' => 'change',
                'label' => 'Change',
                'type' => 'html',
                'nowrap' => true,
                'render' => fn(array $row, array $context): string => '<span class="fw-semibold ' . \htmlspecialchars($row['changeClass']) . '">'
                    . \htmlspecialchars($row['change']) . '</span>',
            ],
            [
                'key' => 'reason',
                'label' => 'What',
                'type' => 'html',
                'nowrap' => true,
                'render' => fn(array $row, array $context): string => \htmlspecialchars($row['reason'])
                    . ($row['orderId'] === null ? '' : ' <a href="' . \htmlspecialchars($context['adminUrl']('shop/orders/view/?id=' . $row['orderId'])) . '">order ' . $row['orderId'] . '</a>')
                    . ($row['shortfall'] === '' ? '' : ' <span class="badge bg-label-warning">' . \htmlspecialchars($row['shortfall']) . '</span>'),
            ],
            ['key' => 'note', 'label' => 'Note', 'type' => 'text', 'fill' => true],
        ],
    ],
];
