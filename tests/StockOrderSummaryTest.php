<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockOrderSummary;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockOrderSummary.php';

/**
 * What the order page shows: per product, how many were taken, how many were
 * short, how many were put back, and whether anything is left to return.
 */
final class StockOrderSummaryTest extends TestCase
{
    private function movement(int $item, int $product, string $reason, int $change, int $short = 0): array
    {
        return [
            'order_item_id' => $item,
            'product_id' => $product,
            'reason' => $reason,
            'change_qty' => $change,
            'short_qty' => $short,
            'product_name' => 'Product ' . $product,
        ];
    }

    public function test_an_order_with_no_movements_has_no_rows(): void
    {
        $summary = StockOrderSummary::from([]);

        $this->assertSame([], $summary['rows']);
        $this->assertFalse($summary['canReturn']);
        $this->assertFalse($summary['hasShortfall']);
    }

    public function test_a_sale_shows_what_was_taken_and_can_be_returned(): void
    {
        $summary = StockOrderSummary::from([$this->movement(1, 10, 'sale', -3)]);

        $this->assertSame(3, $summary['rows'][0]['taken']);
        $this->assertSame(0, $summary['rows'][0]['returned']);
        $this->assertTrue($summary['canReturn']);
    }

    public function test_a_shortfall_is_shown_and_flagged(): void
    {
        $summary = StockOrderSummary::from([$this->movement(1, 10, 'sale', -2, short: 3)]);

        $this->assertSame(3, $summary['rows'][0]['short']);
        $this->assertTrue($summary['hasShortfall']);
    }

    public function test_a_returned_sale_cannot_be_returned_again(): void
    {
        $summary = StockOrderSummary::from([
            $this->movement(1, 10, 'sale', -3),
            $this->movement(1, 10, 'return', 3),
        ]);

        $this->assertSame(3, $summary['rows'][0]['returned']);
        $this->assertFalse($summary['canReturn']);
    }

    public function test_a_sale_that_took_nothing_has_nothing_to_return(): void
    {
        $summary = StockOrderSummary::from([$this->movement(1, 10, 'sale', 0, short: 2)]);

        $this->assertFalse($summary['canReturn']);
    }

    public function test_a_kit_line_gives_one_row_per_product(): void
    {
        $summary = StockOrderSummary::from([
            $this->movement(1, 10, 'sale', -1),
            $this->movement(1, 20, 'sale', -1),
        ]);

        $this->assertCount(expectedCount: 2, haystack: $summary['rows']);
    }

    public function test_the_same_product_on_two_lines_keeps_each_line_apart(): void
    {
        $summary = StockOrderSummary::from([
            $this->movement(1, 10, 'sale', -1),
            $this->movement(2, 10, 'sale', -4),
            $this->movement(2, 10, 'return', 4),
        ]);

        $this->assertCount(expectedCount: 2, haystack: $summary['rows']);
        $this->assertTrue($summary['canReturn']); // line 1 was never returned
    }

    public function test_manual_changes_are_not_part_of_an_order(): void
    {
        $summary = StockOrderSummary::from([$this->movement(0, 10, 'manual', 5)]);

        $this->assertSame([], $summary['rows']);
    }
}
