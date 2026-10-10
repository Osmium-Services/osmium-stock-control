<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * Stock that is on order from a supplier: how it is shown in the admin, and what counts as a valid
 * expected date. Free of the database.
 */
class StockOnOrder
{
    /**
     * @param string $today As Y-m-d
     * @return array{label: string, state: string} state is none, due, overdue (the date has passed) or undated
     */
    public static function describe(?int $quantity, ?string $expectedAt, string $today): array
    {
        $nothingOnOrder = $quantity === null || $quantity <= 0;
        if ($nothingOnOrder) return ['label' => '', 'state' => 'none'];

        $noDate = $expectedAt === null || $expectedAt === '';
        if ($noDate) return ['label' => "{$quantity} on order", 'state' => 'undated'];

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expectedAt);
        $overdue = $expectedAt < $today;

        return ['label' => "{$quantity} due " . $date->format('j M Y'), 'state' => $overdue ? 'overdue' : 'due'];
    }

    /** A real calendar date written exactly as Y-m-d */
    public static function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
