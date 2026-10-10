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

    public function test_a_changed_figure_still_records_one_entry_with_the_note(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 4);

        $this->adjustment()->set(productId: $product, quantity: 6, note: 'Delivery');

        $movements = $this->movementsFor($product);
        $this->assertCount(expectedCount: 2, haystack: $movements);
        $this->assertSame(['change_qty' => 2, 'reason' => 'manual', 'note' => 'Delivery'], $movements[1]);
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

    public function test_only_products_with_a_reorder_level_are_listed_for_the_dashboard(): void
    {
        $withLevel = $this->product();
        $withoutLevel = $this->product();
        $this->adjustment()->set(productId: $withLevel, quantity: 3);
        $this->adjustment()->setReorderLevel(productId: $withLevel, level: 5);
        $this->adjustment()->set(productId: $withoutLevel, quantity: 3);

        $ids = \array_map('intval', \array_column($this->levels->productsWithReorderLevel(), 'id'));

        $this->assertContains($withLevel, $ids);
        $this->assertNotContains($withoutLevel, $ids);
    }

    public function test_the_dashboard_list_carries_what_is_on_order(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setReorderLevel(productId: $product, level: 5);
        $this->adjustment()->setOnOrder(productId: $product, quantity: 9, expectedAt: null);

        $rows = [];
        foreach ($this->levels->productsWithReorderLevel() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $this->assertSame(9, (int) $rows[$product]['on_order_qty']);
    }

    public function test_a_deleted_product_is_not_listed_for_the_dashboard(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 3);
        $this->adjustment()->setReorderLevel(productId: $product, level: 5);
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $product])->execute();

        $ids = \array_map('intval', \array_column($this->levels->productsWithReorderLevel(), 'id'));

        $this->assertNotContains($product, $ids);
    }

    // ── on order ────────────────────────────────────────────────────────────

    private function onOrderOf(int $productId): array
    {
        foreach ($this->levels->listProducts() as $row) {
            $isWanted = (int) $row['id'] === $productId;
            if ($isWanted) return [$row['on_order_qty'] === null ? null : (int) $row['on_order_qty'], $row['expected_at']];
        }

        return [null, null];
    }

    private function trackedProduct(): int
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 3);

        return $product;
    }

    public function test_units_on_order_and_a_date_can_be_set_for_a_tracked_product(): void
    {
        $product = $this->trackedProduct();

        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: '2026-11-12');

        $this->assertSame([12, '2026-11-12'], $this->onOrderOf($product));
    }

    public function test_units_can_be_on_order_with_no_date_yet(): void
    {
        $product = $this->trackedProduct();

        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: null);

        $this->assertSame([12, null], $this->onOrderOf($product));
    }

    public function test_clearing_the_units_clears_the_date_too(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: '2026-11-12');

        $this->adjustment()->setOnOrder(productId: $product, quantity: null, expectedAt: '2026-11-12');

        $this->assertSame([null, null], $this->onOrderOf($product));
    }

    public function test_zero_units_on_order_clears_it(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: '2026-11-12');

        $this->adjustment()->setOnOrder(productId: $product, quantity: 0, expectedAt: null);

        $this->assertSame([null, null], $this->onOrderOf($product));
    }

    public function test_on_order_is_refused_for_an_untracked_product(): void
    {
        $product = $this->product();

        $this->expectException(\InvalidArgumentException::class);

        $this->adjustment()->setOnOrder(productId: $product, quantity: 5, expectedAt: null);
    }

    public function test_an_impossible_date_is_refused_and_nothing_changes(): void
    {
        $product = $this->trackedProduct();

        try {
            $this->adjustment()->setOnOrder(productId: $product, quantity: 5, expectedAt: '2026-02-30');
            $this->fail('An impossible date should be refused');
        } catch (\InvalidArgumentException) {
            $this->assertSame([null, null], $this->onOrderOf($product));
        }
    }

    public function test_changing_the_stock_figure_keeps_what_is_on_order(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: '2026-11-12');

        $this->adjustment()->set(productId: $product, quantity: 1);

        $this->assertSame([12, '2026-11-12'], $this->onOrderOf($product));
    }

    public function test_changing_the_reorder_level_keeps_what_is_on_order(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: '2026-11-12');

        $this->adjustment()->setReorderLevel(productId: $product, level: 5);

        $this->assertSame([12, '2026-11-12'], $this->onOrderOf($product));
    }

    // ── receiving a delivery ────────────────────────────────────────────────

    private function shelfAndOnOrder(int $productId): array
    {
        return [$this->levels->get($productId), $this->onOrderOf($productId)[0]];
    }

    public function test_receiving_adds_what_was_on_order_to_the_shelf_and_clears_it(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: '2026-11-12');

        $units = $this->adjustment()->receiveOnOrder($product);

        $this->assertSame(12, $units);
        $this->assertSame([15, null], $this->shelfAndOnOrder($product));
        $this->assertSame([null, null], $this->onOrderOf($product));
    }

    public function test_receiving_is_recorded_as_a_delivery_in_the_history(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: null);

        $this->adjustment()->receiveOnOrder($product);

        $movements = $this->movementsFor($product);
        $this->assertSame(['change_qty' => 12, 'reason' => 'manual', 'note' => 'Delivery received'], \end($movements));
    }

    public function test_receiving_with_nothing_on_order_is_refused_and_changes_nothing(): void
    {
        $product = $this->trackedProduct();

        try {
            $this->adjustment()->receiveOnOrder($product);
            $this->fail('Nothing on order should be refused');
        } catch (\RuntimeException) {
            $this->assertSame(3, $this->levels->get($product));
        }
    }

    public function test_receiving_twice_adds_the_delivery_only_once(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: null);
        $this->adjustment()->receiveOnOrder($product);

        try {
            $this->adjustment()->receiveOnOrder($product);
            $this->fail('The second receive should be refused');
        } catch (\RuntimeException) {
            $this->assertSame(15, $this->levels->get($product));
        }
    }

    public function test_receiving_for_an_untracked_product_is_refused(): void
    {
        $product = $this->product();

        $this->expectException(\RuntimeException::class);

        $this->adjustment()->receiveOnOrder($product);
    }

    public function test_receiving_moves_an_out_of_stock_product_back_to_in_stock(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 0);
        $this->adjustment()->setOnOrder(productId: $product, quantity: 5, expectedAt: null);
        $this->assertSame($this->outOfStock, $this->statusOf($product));

        $this->adjustment()->receiveOnOrder($product);

        $this->assertSame($this->inStock, $this->statusOf($product));
    }

    public function test_receiving_keeps_the_reorder_level(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setReorderLevel(productId: $product, level: 4);
        $this->adjustment()->setOnOrder(productId: $product, quantity: 12, expectedAt: null);

        $this->adjustment()->receiveOnOrder($product);

        $this->assertSame(4, $this->reorderLevelOf($product));
    }

    public function test_only_products_with_something_on_order_are_listed_for_the_dashboard(): void
    {
        $onOrder = $this->trackedProduct();
        $nothing = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $onOrder, quantity: 5, expectedAt: '2026-09-01');

        $ids = \array_map('intval', \array_column($this->levels->productsOnOrder(), 'id'));

        $this->assertContains($onOrder, $ids);
        $this->assertNotContains($nothing, $ids);
    }

    public function test_a_deleted_product_is_not_listed_as_on_order(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setOnOrder(productId: $product, quantity: 5, expectedAt: '2026-09-01');
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(sql: "UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", bindings: [':id' => $product])->execute();

        $ids = \array_map('intval', \array_column($this->levels->productsOnOrder(), 'id'));

        $this->assertNotContains($product, $ids);
    }

    // ── a product's history ─────────────────────────────────────────────────

    public function test_a_products_history_is_newest_first_and_only_its_own(): void
    {
        $product = $this->product();
        $other = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 5, note: 'First');
        $this->adjustment()->set(productId: $product, quantity: 8, note: 'Second');
        $this->adjustment()->set(productId: $other, quantity: 1, note: 'Not mine');

        $notes = \array_column((new \Osmium\Services\StockControl\Models\StockMovements($this->db))->forProduct($product), 'note');

        $this->assertSame(['Second', 'First'], $notes);
    }

    public function test_a_products_history_is_capped_at_two_hundred_entries(): void
    {
        $product = $this->product();
        $this->adjustment()->set(productId: $product, quantity: 1);
        foreach (\range(1, 205) as $number) {
            $this->adjustment()->set(productId: $product, quantity: 1 + ($number % 2), note: "Note {$number}");
        }

        $rows = (new \Osmium\Services\StockControl\Models\StockMovements($this->db))->forProduct($product);

        $this->assertCount(expectedCount: 200, haystack: $rows);
        $this->assertSame('Note 205', $rows[0]['note']);
    }

    // ── the lasting note ────────────────────────────────────────────────────

    private function noteOf(int $productId): ?string
    {
        foreach ($this->levels->listProducts() as $row) {
            $isWanted = (int) $row['id'] === $productId;
            if ($isWanted) return $row['note'];
        }

        return null;
    }

    public function test_a_note_can_be_kept_with_a_tracked_product(): void
    {
        $product = $this->trackedProduct();

        $this->adjustment()->setNote(productId: $product, note: 'Supplier: Acme, ring Dave');

        $this->assertSame('Supplier: Acme, ring Dave', $this->noteOf($product));
    }

    public function test_changing_the_note_is_recorded_in_the_history(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setNote(productId: $product, note: 'Ring Dave');
        $this->adjustment()->setNote(productId: $product, note: 'Ring Sue');
        $this->adjustment()->setNote(productId: $product, note: '');

        $notes = \array_column($this->movementsFor($product), 'note');
        $changes = \array_column($this->movementsFor($product), 'change_qty');

        $this->assertSame(['Ring Dave', 'Ring Sue', 'Note cleared'], \array_slice($notes, -3));
        $this->assertSame([0, 0, 0], \array_slice($changes, -3));
    }

    public function test_saving_the_same_note_again_adds_no_history(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setNote(productId: $product, note: 'Ring Dave');
        $before = \count($this->movementsFor($product));
        $this->adjustment()->setNote(productId: $product, note: ' Ring Dave ');

        $this->assertCount($before, $this->movementsFor($product));
    }

    public function test_a_note_is_trimmed_and_a_blank_one_clears_it(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setNote(productId: $product, note: '  Ring Dave  ');
        $this->assertSame('Ring Dave', $this->noteOf($product));

        $this->adjustment()->setNote(productId: $product, note: '   ');

        $this->assertNull($this->noteOf($product));
    }

    public function test_a_note_can_be_cleared_with_null(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setNote(productId: $product, note: 'Something');

        $this->adjustment()->setNote(productId: $product, note: null);

        $this->assertNull($this->noteOf($product));
    }

    public function test_a_note_is_refused_for_an_untracked_product(): void
    {
        $product = $this->product();

        $this->expectException(\InvalidArgumentException::class);

        $this->adjustment()->setNote(productId: $product, note: 'Hello');
    }

    public function test_a_note_over_two_hundred_and_fifty_five_characters_is_refused(): void
    {
        $product = $this->trackedProduct();

        try {
            $this->adjustment()->setNote(productId: $product, note: \str_repeat('x', 256));
            $this->fail('A note this long should be refused');
        } catch (\InvalidArgumentException) {
            $this->assertNull($this->noteOf($product));
        }
    }

    public function test_a_note_of_exactly_two_hundred_and_fifty_five_characters_is_kept(): void
    {
        $product = $this->trackedProduct();

        $this->adjustment()->setNote(productId: $product, note: \str_repeat('x', 255));

        $this->assertSame(255, \strlen((string) $this->noteOf($product)));
    }

    public function test_the_note_survives_changes_to_the_figure_the_level_and_the_order(): void
    {
        $product = $this->trackedProduct();
        $this->adjustment()->setNote(productId: $product, note: 'Keep me');

        $this->adjustment()->set(productId: $product, quantity: 9);
        $this->adjustment()->setReorderLevel(productId: $product, level: 4);
        $this->adjustment()->setOnOrder(productId: $product, quantity: 5, expectedAt: null);

        $this->assertSame('Keep me', $this->noteOf($product));
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
