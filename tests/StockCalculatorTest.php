<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockCalculator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockCalculator.php';

/**
 * The stock arithmetic: how many units of each product an order takes,
 * how much of that can come off the shelf, and when a product flips status.
 */
final class StockCalculatorTest extends TestCase
{
    private StockCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new StockCalculator();
    }

    private function item(int $productId, int $quantity, array $parts = []): array
    {
        return ['product_id' => $productId, 'quantity' => $quantity, 'parts' => $parts];
    }

    private function part(int $productId, int $quantity = 1): array
    {
        return ['product_id' => $productId, 'option_value_id' => null, 'quantity' => $quantity];
    }

    // ── what the shelf cannot cover ─────────────────────────────────────────

    public function test_nothing_is_short_when_every_tracked_product_has_enough(): void
    {
        $this->assertSame([], $this->calc->shortages([10 => 2, 20 => 1], [10 => 2, 20 => 5]));
    }

    public function test_a_product_wanted_beyond_its_shelf_is_short_by_what_is_there(): void
    {
        $this->assertSame([20 => 1], $this->calc->shortages([10 => 1, 20 => 3], [10 => 5, 20 => 1]));
    }

    public function test_an_untracked_product_is_never_short(): void
    {
        $this->assertSame([], $this->calc->shortages([10 => 99], [10 => null]));
        $this->assertSame([], $this->calc->shortages([10 => 99], []));
    }

    public function test_a_negative_shelf_counts_as_nothing_available(): void
    {
        $this->assertSame([10 => 0], $this->calc->shortages([10 => 1], [10 => -3]));
    }

    // ── when to reorder ─────────────────────────────────────────────────────

    public function test_a_product_at_or_below_its_reorder_level_is_flagged(): void
    {
        $this->assertSame('reorder', $this->calc->stockState(quantity: 5, reorderLevel: 5));
        $this->assertSame('reorder', $this->calc->stockState(quantity: 0, reorderLevel: 5));
    }

    public function test_a_product_above_its_reorder_level_is_ok(): void
    {
        $this->assertSame('ok', $this->calc->stockState(quantity: 6, reorderLevel: 5));
    }

    public function test_a_tracked_product_with_no_reorder_level_is_ok_however_low_it_is(): void
    {
        $this->assertSame('ok', $this->calc->stockState(quantity: 0, reorderLevel: null));
    }

    public function test_an_untracked_product_has_its_own_state(): void
    {
        $this->assertSame('untracked', $this->calc->stockState(quantity: null, reorderLevel: 5));
    }

    public function test_a_reorder_level_of_zero_flags_only_when_it_has_run_out(): void
    {
        $this->assertSame('reorder', $this->calc->stockState(quantity: 0, reorderLevel: 0));
        $this->assertSame('ok', $this->calc->stockState(quantity: 1, reorderLevel: 0));
    }

    public function test_a_product_below_its_level_with_stock_on_order_is_ordered_not_reorder(): void
    {
        $this->assertSame('ordered', $this->calc->stockState(quantity: 2, reorderLevel: 5, onOrder: 10));
    }

    public function test_a_product_at_its_level_with_stock_on_order_is_ordered(): void
    {
        $this->assertSame('ordered', $this->calc->stockState(quantity: 5, reorderLevel: 5, onOrder: 1));
    }

    public function test_nothing_or_zero_on_order_still_needs_reorder(): void
    {
        $this->assertSame('reorder', $this->calc->stockState(quantity: 2, reorderLevel: 5, onOrder: null));
        $this->assertSame('reorder', $this->calc->stockState(quantity: 2, reorderLevel: 5, onOrder: 0));
    }

    public function test_stock_on_order_does_not_change_a_product_that_is_ok(): void
    {
        $this->assertSame('ok', $this->calc->stockState(quantity: 9, reorderLevel: 5, onOrder: 10));
        $this->assertSame('ok', $this->calc->stockState(quantity: 0, reorderLevel: null, onOrder: 10));
    }

    public function test_stock_on_order_does_not_change_an_untracked_product(): void
    {
        $this->assertSame('untracked', $this->calc->stockState(quantity: null, reorderLevel: 5, onOrder: 10));
    }

    // ── units an order takes ────────────────────────────────────────────────

    public function test_a_plain_line_takes_its_quantity_of_its_own_product(): void
    {
        $this->assertSame([10 => 3], $this->calc->unitsByProduct([$this->item(10, 3)]));
    }

    public function test_a_kit_line_takes_the_kit_and_each_part_once_per_unit(): void
    {
        $units = $this->calc->unitsByProduct([$this->item(10, 2, [$this->part(20), $this->part(30)])]);

        $this->assertSame([10 => 2, 20 => 2, 30 => 2], $units);
    }

    public function test_a_part_quantity_multiplies_by_the_line_quantity(): void
    {
        $units = $this->calc->unitsByProduct([$this->item(10, 3, [$this->part(20, 2)])]);

        $this->assertSame(6, $units[20]);
    }

    public function test_the_same_product_across_lines_and_parts_is_added_up(): void
    {
        $units = $this->calc->unitsByProduct([
            $this->item(10, 1, [$this->part(20)]),
            $this->item(20, 4),
            $this->item(11, 2, [$this->part(20)]),
        ]);

        $this->assertSame(1 + 4 + 2, $units[20]);
    }

    public function test_an_order_with_no_lines_takes_nothing(): void
    {
        $this->assertSame([], $this->calc->unitsByProduct([]));
    }

    // ── taking stock off the shelf ──────────────────────────────────────────

    public function test_with_enough_stock_everything_is_taken(): void
    {
        $this->assertSame(['taken' => 3, 'short' => 0, 'remaining' => 7], $this->calc->deduct(onHand: 10, wanted: 3));
    }

    public function test_taking_the_last_unit_leaves_zero_and_no_shortfall(): void
    {
        $this->assertSame(['taken' => 2, 'short' => 0, 'remaining' => 0], $this->calc->deduct(onHand: 2, wanted: 2));
    }

    public function test_with_too_little_stock_it_takes_what_there_is_and_reports_the_shortfall(): void
    {
        $this->assertSame(['taken' => 2, 'short' => 3, 'remaining' => 0], $this->calc->deduct(onHand: 2, wanted: 5));
    }

    public function test_stock_never_goes_below_zero_even_if_it_already_was(): void
    {
        $this->assertSame(['taken' => 0, 'short' => 4, 'remaining' => 0], $this->calc->deduct(onHand: -1, wanted: 4));
    }

    public function test_wanting_nothing_changes_nothing(): void
    {
        $this->assertSame(['taken' => 0, 'short' => 0, 'remaining' => 5], $this->calc->deduct(onHand: 5, wanted: 0));
    }

    // ── status flipping ─────────────────────────────────────────────────────

    public function test_a_product_that_runs_out_moves_to_the_out_of_stock_status(): void
    {
        $this->assertSame(9, $this->calc->statusAfter(quantity: 0, currentStatusId: 1, outOfStockStatusId: 9, inStockStatusId: 1));
    }

    public function test_a_product_already_out_of_stock_is_left_alone_at_zero(): void
    {
        $this->assertNull($this->calc->statusAfter(quantity: 0, currentStatusId: 9, outOfStockStatusId: 9, inStockStatusId: 1));
    }

    public function test_a_product_back_in_stock_returns_to_the_in_stock_status(): void
    {
        $this->assertSame(1, $this->calc->statusAfter(quantity: 5, currentStatusId: 9, outOfStockStatusId: 9, inStockStatusId: 1));
    }

    public function test_a_product_with_stock_and_some_other_status_is_left_alone(): void
    {
        $this->assertNull($this->calc->statusAfter(quantity: 5, currentStatusId: 5, outOfStockStatusId: 9, inStockStatusId: 1), 'A pre-order or discontinued status was overwritten just because stock arrived');
    }

    public function test_a_product_with_stock_in_the_in_stock_status_is_left_alone(): void
    {
        $this->assertNull($this->calc->statusAfter(quantity: 5, currentStatusId: 1, outOfStockStatusId: 9, inStockStatusId: 1));
    }
}
