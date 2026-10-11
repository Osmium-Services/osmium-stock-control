<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockLevels;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/StockLevels.php';

/**
 * The SQL behind stock_summary, run against real rows: what counts as tracked and out of stock, and which
 * products the reorder and on-order lists include.
 */
class StockSummarySqlTest extends DatabaseTestCase
{
    private StockLevels $levels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->levels = new StockLevels($this->db);

        $prefix = $this->db->tablePrefix();
        $tableExists = $this->db->prepare("SHOW TABLES LIKE '{$prefix}stock_levels'")->single() !== false;
        if (!$tableExists) $this->markTestSkipped('The Stock Control table is not installed');
    }

    private function tracked(int $quantity, ?int $reorderLevel = null, ?int $onOrder = null, ?string $expected = null): int
    {
        $product = $this->createTestProduct();
        $this->levels->set($product, $quantity);
        if ($reorderLevel !== null) $this->levels->setReorderLevel($product, $reorderLevel);
        if ($onOrder !== null) $this->levels->setOnOrder($product, $onOrder, $expected);

        return $product;
    }

    private function deleteProduct(int $productId): void
    {
        $prefix = $this->db->tablePrefix();
        $this->db->prepare("UPDATE {$prefix}products SET deleted_at = NOW() WHERE id = :id", [':id' => $productId])->execute();
    }

    /** @return array<int, int> ids of the products in a list result */
    private function ids(array $rows): array
    {
        return \array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    public function test_tracked_counts_live_products_with_a_figure_and_out_of_stock_counts_zero_or_less(): void
    {
        $before = $this->levels->totals();

        $this->tracked(5);
        $this->tracked(0);
        $this->tracked(-3);
        $this->deleteProduct($this->tracked(0));

        $after = $this->levels->totals();

        $this->assertSame(3, (int) $after['tracked'] - (int) $before['tracked']);
        $this->assertSame(2, (int) $after['out_of_stock'] - (int) $before['out_of_stock']);
    }

    public function test_a_product_with_no_figure_is_not_tracked(): void
    {
        $before = $this->levels->totals();

        $this->createTestProduct();

        $this->assertSame((int) $before['tracked'], (int) $this->levels->totals()['tracked']);
    }

    public function test_the_reorder_list_has_only_live_products_with_a_reorder_level(): void
    {
        $withLevel = $this->tracked(2, 5);
        $zeroLevel = $this->tracked(2, 0);
        $noLevel = $this->tracked(2);
        $deleted = $this->tracked(2, 5);
        $this->deleteProduct($deleted);

        $ids = $this->ids($this->levels->productsWithReorderLevel());

        $this->assertContains($withLevel, $ids);
        $this->assertContains($zeroLevel, $ids);
        $this->assertNotContains($noLevel, $ids);
        $this->assertNotContains($deleted, $ids);
    }

    public function test_the_reorder_rows_carry_what_the_summary_needs(): void
    {
        $product = $this->tracked(2, 5, 10);

        $row = \array_values(\array_filter($this->levels->productsWithReorderLevel(), fn(array $r): bool => (int) $r['id'] === $product))[0];

        $this->assertSame(2, (int) $row['quantity']);
        $this->assertSame(5, (int) $row['reorder_level']);
        $this->assertSame(10, (int) $row['on_order_qty']);
        $this->assertNotSame('', (string) $row['name']);
    }

    public function test_the_on_order_list_has_only_live_products_with_units_on_order(): void
    {
        $late = $this->tracked(1, null, 20, '2026-10-01');
        $undated = $this->tracked(1, null, 5);
        $none = $this->tracked(1);
        $zero = $this->tracked(1, null, 0);
        $deleted = $this->tracked(1, null, 7, '2026-10-01');
        $this->deleteProduct($deleted);

        $rows = $this->levels->productsOnOrder();
        $ids = $this->ids($rows);

        $this->assertContains($late, $ids);
        $this->assertContains($undated, $ids);
        $this->assertNotContains($none, $ids);
        $this->assertNotContains($zero, $ids);
        $this->assertNotContains($deleted, $ids);

        $lateRow = \array_values(\array_filter($rows, fn(array $r): bool => (int) $r['id'] === $late))[0];
        $this->assertSame('2026-10-01', (string) $lateRow['expected_at']);
        $this->assertSame(20, (int) $lateRow['on_order_qty']);
    }
}
