<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockAdjustment;
use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockCsvImport;
use Osmium\Services\StockControl\Models\StockLevels;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/StockCalculator.php';
require_once __DIR__ . '/../models/StockLevels.php';
require_once __DIR__ . '/../models/StockMovements.php';
require_once __DIR__ . '/../models/StockStatusSync.php';
require_once __DIR__ . '/../models/StockAdjustment.php';
require_once __DIR__ . '/../models/StockCsv.php';
require_once __DIR__ . '/../models/StockCsvImport.php';

/**
 * Applying an uploaded stock spreadsheet: every figure goes through the same
 * code as the Set stock page, so each change is audited and the status follows.
 */
class StockCsvImportTest extends DatabaseTestCase
{
    private StockLevels $levels;
    private int $inStock;
    private int $outOfStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->levels = new StockLevels($this->db);
        $this->inStock = $this->statusId(available: true);
        $this->outOfStock = $this->statusId(available: false);
    }

    private function import(string $csv): array
    {
        $adjustment = new StockAdjustment($this->db, new StockCalculator(), $this->outOfStock, $this->inStock);

        return (new StockCsvImport(levels: $this->levels, adjustment: $adjustment))->apply($csv);
    }

    private function product(): int
    {
        return $this->createTestProduct(['status_id' => $this->inStock]);
    }

    private function movements(int $productId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->prepare(
            sql: "SELECT change_qty, reason, note FROM {$prefix}stock_movements WHERE product_id = :id ORDER BY id",
            bindings: [':id' => $productId],
        )->resultset();
    }

    public function test_a_figure_for_an_untracked_product_starts_tracking_it(): void
    {
        $product = $this->product();

        $result = $this->import("ID,In stock\n{$product},8\n");

        $this->assertSame(8, $this->levels->get($product));
        $this->assertSame(1, $result['updated']);
        $this->assertSame([['change_qty' => 8, 'reason' => 'manual', 'note' => 'CSV import']], $this->movements($product));
    }

    public function test_a_changed_figure_is_updated(): void
    {
        $product = $this->product();
        $this->levels->set($product, 10);

        $result = $this->import("ID,In stock\n{$product},4\n");

        $this->assertSame(4, $this->levels->get($product));
        $this->assertSame(1, $result['updated']);
    }

    public function test_the_same_figure_is_counted_as_unchanged_and_writes_nothing(): void
    {
        $product = $this->product();
        $this->levels->set($product, 10);

        $result = $this->import("ID,In stock\n{$product},10\n");

        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame([], $this->movements($product));
    }

    public function test_a_blank_cell_leaves_the_product_alone(): void
    {
        $product = $this->product();
        $this->levels->set($product, 10);

        $result = $this->import("ID,In stock\n{$product},\n");

        $this->assertSame(10, $this->levels->get($product));
        $this->assertSame(1, $result['skipped']);
    }

    public function test_a_blank_cell_does_not_start_tracking(): void
    {
        $product = $this->product();

        $this->import("ID,In stock\n{$product},\n");

        $this->assertNull($this->levels->get($product));
    }

    public function test_an_unknown_product_is_an_error_for_that_row_and_the_rest_still_apply(): void
    {
        $product = $this->product();

        $result = $this->import("ID,In stock\n999999,3\n{$product},5\n");

        $this->assertSame(5, $this->levels->get($product));
        $this->assertSame(['Row 2: Product ID 999999 not found'], $result['errors']);
        $this->assertSame(1, $result['updated']);
    }

    public function test_a_bad_figure_is_reported_with_its_row_and_nothing_is_changed_for_it(): void
    {
        $product = $this->product();
        $this->levels->set($product, 10);

        $result = $this->import("ID,In stock\n{$product},lots\n");

        $this->assertSame(10, $this->levels->get($product));
        $this->assertSame(["Row 2: 'lots' is not a whole number, zero or more"], $result['errors']);
    }

    public function test_zero_moves_the_product_to_out_of_stock(): void
    {
        $product = $this->product();

        $this->import("ID,In stock\n{$product},0\n");

        $prefix = $this->db->tablePrefix();
        $row = $this->db->prepare(sql: "SELECT status_id FROM {$prefix}products WHERE id = :id", bindings: [':id' => $product])->single();
        $this->assertSame($this->outOfStock, (int) $row['status_id']);
    }

    public function test_a_file_that_cannot_be_read_returns_its_error_and_changes_nothing(): void
    {
        $result = $this->import("Name,Qty\nPanel,3\n");

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $result['updated']);
    }

    public function test_a_mixed_file_gives_the_right_counts(): void
    {
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();
        $this->levels->set($b, 2);

        $result = $this->import("ID,In stock\n{$a},1\n{$b},2\n{$c},\n999999,1\n");

        $this->assertSame([1, 1, 1, 1], [$result['updated'], $result['unchanged'], $result['skipped'], \count($result['errors'])]);
    }
}
