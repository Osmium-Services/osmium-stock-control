<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * One line of a product's stock history, as the History page shows it. Free of the database.
 */
class StockHistory
{
    private const REASONS = ['sale' => 'Sale', 'return' => 'Refund returned', 'manual' => 'Adjustment'];

    /**
     * @param array<string, mixed> $movement As StockMovements::forProduct() returns it
     * @return array{when: string, change: string, changeClass: string, reason: string, note: string, shortfall: string, orderId: ?int}
     */
    public static function row(array $movement): array
    {
        $change = (int) $movement['change_qty'];
        $short = (int) $movement['short_qty'];

        return [
            'when' => (new \DateTimeImmutable((string) $movement['created_at']))->format('j M Y, H:i'),
            'change' => $change > 0 ? "+{$change}" : (string) $change,
            'changeClass' => $change > 0 ? 'text-success' : ($change < 0 ? 'text-danger' : 'text-muted'),
            'reason' => self::REASONS[$movement['reason']] ?? (string) $movement['reason'],
            'note' => (string) ($movement['note'] ?? ''),
            'shortfall' => $short > 0 ? "Short by {$short}" : '',
            'orderId' => $movement['order_id'] === null ? null : (int) $movement['order_id'],
        ];
    }
}
