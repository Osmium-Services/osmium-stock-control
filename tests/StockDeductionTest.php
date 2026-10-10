<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockDeduction;
use Osmium\Services\StockControl\Models\StockLevels;
use Osmium\Services\StockControl\Models\StockMovements;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/StockCalculator.php';
require_once __DIR__ . '/../models/StockLevels.php';
require_once __DIR__ . '/../models/StockMovements.php';
require_once __DIR__ . '/../models/StockDeduction.php';

/**
 * What paying an order does to the shelf: per-product deductions from the order's
 * lines and kit parts, an audit row for each, and the status flip at zero.
 */
class StockDeductionTest extends DatabaseTestCase
{
    private StockLevels $levels;
    private StockMovements $movements;
    private int $inStock;
    private int $outOfStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->levels = new StockLevels($this->db);
        $this->movements = new StockMovements($this->db);
        $this->inStock = $this->statusId(available: true);
        $this->outOfStock = $this->statusId(available: false);
    }

    private function product(?int $quantity = null): int
    {
        $productId = $this->createTestProduct(['status_id' => $this->inStock]);
        if ($quantity !== null) $this->levels->set($productId, $quantity);

        return $productId;
    }

    private function statusOf(int $productId): int
    {
        $prefix = $this->db->tablePrefix();
        $row = $this->db->prepare(sql: "SELECT status_id FROM {$prefix}products WHERE id = :id", bindings: [':id' => $productId])->single();

        return (int) $row['status_id'];
    }

    private function item(int $id, int $productId, int $quantity, array $partProductIds = []): array
    {
        return [
            'id' => $id,
            'product_id' => $productId,
            'quantity' => $quantity,
            'parts' => \array_map(fn(int $p): array => ['product_id' => $p, 'option_value_id' => null, 'quantity' => 1], $partProductIds),
        ];
    }

    private function order(array $items, int $id = 5001): array
    {
        return ['id' => $id, 'items' => $items];
    }

    private function deduction(bool $withStatuses = true): StockDeduction
    {
        return new StockDeduction(
            db: $this->db,
            calculator: new StockCalculator(),
            outOfStockStatusId: $withStatuses ? $this->outOfStock : null,
            inStockStatusId: $withStatuses ? $this->inStock : null,
        );
    }

    public function test_paying_takes_the_ordered_quantity_off_a_tracked_product(): void
    {
        $product = $this->product(10);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 3)]));

        $this->assertSame(7, $this->levels->get($product));
    }

    public function test_each_deduction_leaves_an_audit_row(): void
    {
        $product = $this->product(10);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(11, $product, 3)], id: 7001));

        $rows = $this->movements->forOrder(7001);
        $this->assertCount(expectedCount: 1, haystack: $rows);
        $this->assertSame($product, (int) $rows[0]['product_id']);
        $this->assertSame(11, (int) $rows[0]['order_item_id']);
        $this->assertSame(-3, (int) $rows[0]['change_qty']);
        $this->assertSame(0, (int) $rows[0]['short_qty']);
        $this->assertSame('sale', $rows[0]['reason']);
    }

    public function test_a_product_with_no_stock_figure_is_not_tracked_and_is_left_alone(): void
    {
        $product = $this->product(null);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 3)], id: 7002));

        $this->assertNull($this->levels->get($product));
        $this->assertSame([], $this->movements->forOrder(7002));
        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    public function test_a_kit_takes_stock_from_itself_and_from_each_tracked_part(): void
    {
        $kit = $this->product(5);
        $battery = $this->product(8);
        $untrackedGland = $this->product(null);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $kit, 2, [$battery, $untrackedGland])]));

        $this->assertSame(3, $this->levels->get($kit));
        $this->assertSame(6, $this->levels->get($battery));
        $this->assertNull($this->levels->get($untrackedGland));
    }

    public function test_running_short_takes_what_there_is_and_records_the_shortfall(): void
    {
        $product = $this->product(1);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 3)], id: 7003));

        $this->assertSame(0, $this->levels->get($product));
        $row = $this->movements->forOrder(7003)[0];
        $this->assertSame(-1, (int) $row['change_qty']);
        $this->assertSame(2, (int) $row['short_qty']);
    }

    public function test_running_the_same_paid_order_twice_only_deducts_once(): void
    {
        $product = $this->product(10);
        $order = $this->order([$this->item(1, $product, 3)], id: 7004);

        $this->deduction()->deductForPaidOrder($order);
        $this->deduction()->deductForPaidOrder($order);

        $this->assertSame(7, $this->levels->get($product));
        $this->assertCount(expectedCount: 1, haystack: $this->movements->forOrder(7004));
    }

    public function test_two_lines_of_the_same_product_are_each_deducted_and_recorded(): void
    {
        $product = $this->product(10);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 2), $this->item(2, $product, 3)], id: 7005));

        $this->assertSame(5, $this->levels->get($product));
        $this->assertCount(expectedCount: 2, haystack: $this->movements->forOrder(7005));
    }

    public function test_running_out_moves_the_product_to_the_out_of_stock_status(): void
    {
        $product = $this->product(2);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 2)]));

        $this->assertSame($this->outOfStock, $this->statusOf($product));
    }

    public function test_stock_remaining_leaves_the_status_alone(): void
    {
        $product = $this->product(5);

        $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 2)]));

        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    public function test_with_no_statuses_configured_the_status_is_never_touched(): void
    {
        $product = $this->product(2);

        $this->deduction(withStatuses: false)->deductForPaidOrder($this->order([$this->item(1, $product, 2)]));

        $this->assertSame(0, $this->levels->get($product));
        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    public function test_it_reports_what_was_taken_for_each_product(): void
    {
        $product = $this->product(1);

        $results = $this->deduction()->deductForPaidOrder($this->order([$this->item(1, $product, 3)]));

        $this->assertSame([['order_item_id' => 1, 'product_id' => $product, 'taken' => 1, 'short' => 2, 'remaining' => 0]], $results);
    }
}
