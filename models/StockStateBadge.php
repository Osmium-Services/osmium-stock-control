<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The Ordering badge on the stock list for a StockCalculator::stockState() value.
 */
class StockStateBadge
{
    /** @return array{label: string, class: string} */
    public static function for(string $state): array
    {
        return match ($state) {
            'reorder' => ['label' => 'Reorder', 'class' => 'bg-label-danger'],
            'ordered' => ['label' => 'Ordered', 'class' => 'bg-label-warning'],
            'untracked' => ['label' => '', 'class' => 'bg-label-secondary'],
            default => ['label' => 'OK', 'class' => 'bg-label-success'],
        };
    }
}
