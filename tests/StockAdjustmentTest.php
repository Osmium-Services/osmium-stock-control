<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockAdjustment;
use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockLevels;
use Osmium\Services\StockControl\Models\StockMovements;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/StockCalculator.php';
require_once __DIR__ . '/../models/StockLevels.php';
require_once __DIR__ . '/../models/StockMovements.php';
require_once __DIR__ . '/../models/StockStatusSync.php';
require_once __DIR__ . '/../models/StockAdjustment.php';

/**
 * What an admin does from the stock list: start tracking a product, change its
 * figure, or stop tracking it. Every change leaves a manual audit row.
 */
class StockAdjustmentTest extends DatabaseTestCase
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

    private function adjustment(bool $withStatuses = true): StockAdjustment
    {
        return new StockAdjustment(
            db: $this->db,
            calculator: new StockCalculator(),
            outOfStockStatusId: $withStatuses ? $this->outOfStock : null,
            inStockStatusId: $withStatuses ? $this->inStock : null,
        );
    }

    private function product(): int
    {
        return $this->createTestProduct(['status_id' => $this->inStock]);
    }

    private function statusOf(int $productId): int
    {
        $prefix = $this->db->tablePrefix();
        $row = $this->db->prepare(sql: "SELECT status_id FROM {$prefix}products WHERE id = :id", bindings: [':id' => $productId])->single();

        return (int) $row['status_id'];
    }

    private function movementsFor(int $productId): array
    {
        $prefix = $this->db->tablePrefix();

        return $this->db->prepare(
            sql: "SELECT change_qty, reason, note FROM {$prefix}stock_movements WHERE product_id = :id ORDER BY id",
            bindings: [':id' => $productId],
        )->resultset();
    }

    public function test_setting_a_figure_for_an_untracked_product_starts_tracking_it(): void
    {
        $product = $this->product();

        $this->adjustment()->set(productId: $product, quantity: 12, note: 'Counted the shelf');

        $this->assertSame(12, $this->levels->get($product));
        $this->assertSame([['change_qty' => 12, 'reason' => 'manual', 'note' => 'Counted the shelf']], $this->movementsFor($product));
    }

    public function test_changing_a_figure_records_the_difference(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 10);

        $this->adjustment()->set(productId: $product, quantity: 7);

        $this->assertSame(7, $this->levels->get($product));
        $this->assertSame([10, -3], \array_map('intval', \array_column($this->movementsFor($product), 'change_qty')));
    }

    public function test_setting_the_same_figure_again_records_nothing_new(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 4);

        $this->adjustment()->set(productId: $product, quantity: 4);

        $this->assertCount(expectedCount: 1, haystack: $this->movementsFor($product));
    }

    public function test_a_negative_figure_is_refused_and_nothing_is_written(): void
    {
        $product = $this->product();

        try {
            $this->adjustment()->set(productId: $product, quantity: -1);
            $this->fail('A negative quantity should be refused');
        } catch (\InvalidArgumentException) {
            $this->assertNull($this->levels->get($product));
            $this->assertSame([], $this->movementsFor($product));
        }
    }

    public function test_a_product_that_does_not_exist_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->adjustment()->set(productId: 999999, quantity: 3);
    }

    public function test_setting_zero_moves_the_product_to_out_of_stock(): void
    {
        $product = $this->product();

        $this->adjustment()->set(productId: $product, quantity: 0);

        $this->assertSame($this->outOfStock, $this->statusOf($product));
    }

    public function test_restocking_moves_an_out_of_stock_product_back_to_in_stock(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 0);

        $this->adjustment()->set(productId: $product, quantity: 5);

        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    public function test_the_status_is_left_alone_until_both_statuses_are_chosen(): void
    {
        $product = $this->product();

        $this->adjustment(withStatuses: false)->set(productId: $product, quantity: 0);

        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    public function test_a_chosen_status_that_no_longer_exists_leaves_the_product_status_alone(): void
    {
        $product = $this->product();
        $missingStatus = 250;
        $adjustment = new StockAdjustment(db: $this->db, calculator: new StockCalculator(), outOfStockStatusId: $missingStatus, inStockStatusId: $this->inStock);

        $adjustment->set(productId: $product, quantity: 0);

        $this->assertSame($this->inStock, $this->statusOf($product));
        $this->assertSame(0, $this->levels->get($product));
    }

    public function test_an_in_stock_status_that_no_longer_exists_also_stops_the_status_changing(): void
    {
        $product = $this->product();
        $missingStatus = 250;
        $adjustment = new StockAdjustment(db: $this->db, calculator: new StockCalculator(), outOfStockStatusId: $this->outOfStock, inStockStatusId: $missingStatus);

        $adjustment->set(productId: $product, quantity: 0);

        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    // ── reorder level ───────────────────────────────────────────────────────

    private function reorderLevelOf(int $productId): ?int
    {
        foreach ($this->levels->listProducts() as $row) {
            $isWanted = (int) $row['id'] === $productId;
            if ($isWanted) return $row['reorder_level'] === null ? null : (int) $row['reorder_level'];
        }

        return null;
    }

    public function test_a_reorder_level_can_be_set_for_a_tracked_product(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 10);

        $this->adjustment()->setReorderLevel(productId: $product, level: 4);

        $this->assertSame(4, $this->reorderLevelOf($product));
    }

    public function test_a_reorder_level_can_be_cleared(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 10);
        $this->adjustment()->setReorderLevel(productId: $product, level: 4);

        $this->adjustment()->setReorderLevel(productId: $product, level: null);

        $this->assertNull($this->reorderLevelOf($product));
    }

    public function test_a_reorder_level_is_refused_for_an_untracked_product(): void
    {
        $product = $this->product();

        $this->expectException(\InvalidArgumentException::class);

        $this->adjustment()->setReorderLevel(productId: $product, level: 4);
    }

    public function test_a_negative_reorder_level_is_refused(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 10);

        $this->expectException(\InvalidArgumentException::class);

        $this->adjustment()->setReorderLevel(productId: $product, level: -1);
    }

    public function test_changing_the_quantity_keeps_the_reorder_level(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 10);
        $this->adjustment()->setReorderLevel(productId: $product, level: 4);

        $this->adjustment()->set(productId: $product, quantity: 2);

        $this->assertSame(4, $this->reorderLevelOf($product));
    }

    public function test_the_product_list_has_no_reorder_level_for_an_untracked_product(): void
    {
        $product = $this->product();

        $this->assertNull($this->reorderLevelOf($product));
    }

    public function test_stopping_tracking_removes_the_figure_and_records_what_was_left(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 6);

        $this->adjustment()->stopTracking($product);

        $this->assertNull($this->levels->get($product));
        $changes = \array_map('intval', \array_column($this->movementsFor($product), 'change_qty'));
        $this->assertSame([6, -6], $changes);
    }

    public function test_stopping_tracking_an_untracked_product_does_nothing(): void
    {
        $product = $this->product();

        $this->adjustment()->stopTracking($product);

        $this->assertSame([], $this->movementsFor($product));
    }

    public function test_the_product_list_shows_tracked_and_untracked_products(): void
    {
        $tracked = $this->product();
        $untracked = $this->product();
        $this->adjustment()->set(productId: $tracked, quantity: 9);

        $rows = [];
        foreach ($this->levels->listProducts() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $this->assertSame(9, (int) $rows[$tracked]['quantity']);
        $this->assertNull($rows[$untracked]['quantity']);
    }

    public function test_a_deleted_product_is_not_in_the_list(): void
    {
        $product = $this->product();
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $product])->execute();

        $ids = \array_map('intval', \array_column($this->levels->listProducts(), 'id'));

        $this->assertNotContains($product, $ids);
    }
}
