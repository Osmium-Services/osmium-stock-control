<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockDashboardWidget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockCalculator.php';
require_once __DIR__ . '/../models/StockDashboardWidget.php';

/**
 * The dashboard card for products that need reordering: shown only once reorder levels are in
 * use, most urgent first, with a link to the filtered stock list.
 */
final class StockDashboardWidgetTest extends TestCase
{
    private function row(int $id, string $name, int $quantity, int $level): array
    {
        return ['id' => $id, 'name' => $name, 'quantity' => $quantity, 'reorder_level' => $level];
    }

    private function build(array $rows): ?array
    {
        return StockDashboardWidget::build(rows: $rows, calculator: new StockCalculator());
    }

    public function test_there_is_no_widget_until_a_reorder_level_has_been_set_somewhere(): void
    {
        $this->assertNull($this->build([]));
    }

    public function test_when_nothing_needs_reordering_the_widget_says_so(): void
    {
        $widget = $this->build([$this->row(1, 'Panel', 20, 5)]);

        $this->assertSame(0, $widget['count']);
        $this->assertSame([], $widget['lines']);
        $this->assertNotEmpty($widget['emptyText']);
    }

    public function test_only_products_at_or_below_their_level_are_counted(): void
    {
        $widget = $this->build([
            $this->row(1, 'At level', 5, 5),
            $this->row(2, 'Below', 1, 5),
            $this->row(3, 'Above', 6, 5),
        ]);

        $this->assertSame(2, $widget['count']);
        $this->assertCount(expectedCount: 2, haystack: $widget['lines']);
    }

    public function test_the_most_urgent_product_is_first(): void
    {
        $widget = $this->build([
            $this->row(1, 'Slightly low', 4, 5),
            $this->row(2, 'Right out', 0, 5),
            $this->row(3, 'Quite low', 1, 5),
        ]);

        $this->assertSame(['Right out', 'Quite low', 'Slightly low'], \array_column($widget['lines'], 'text'));
    }

    public function test_equally_urgent_products_are_in_name_order(): void
    {
        $widget = $this->build([$this->row(1, 'Zebra', 2, 5), $this->row(2, 'Apple', 2, 5)]);

        $this->assertSame(['Apple', 'Zebra'], \array_column($widget['lines'], 'text'));
    }

    public function test_the_card_lists_at_most_five_but_counts_them_all(): void
    {
        $rows = [];
        foreach (\range(1, 8) as $id) {
            $rows[] = $this->row($id, "Product {$id}", 0, 5);
        }

        $widget = $this->build($rows);

        $this->assertSame(8, $widget['count']);
        $this->assertCount(expectedCount: 5, haystack: $widget['lines']);
    }

    public function test_a_line_says_how_many_are_left_and_the_level(): void
    {
        $widget = $this->build([$this->row(1, 'Panel', 2, 5), $this->row(2, 'Gone', 0, 3)]);

        $subs = \array_column($widget['lines'], 'sub', 'text');
        $this->assertSame('2 left, reorder at 5', $subs['Panel']);
        $this->assertSame('none left, reorder at 3', $subs['Gone']);
    }

    public function test_a_line_links_to_that_products_set_stock_page(): void
    {
        $widget = $this->build([$this->row(42, 'Panel', 1, 5)]);

        $this->assertSame('stock/edit/?id=42', $widget['lines'][0]['route']);
    }

    public function test_the_header_link_goes_to_the_filtered_list(): void
    {
        $widget = $this->build([$this->row(1, 'Panel', 1, 5)]);

        $this->assertSame(['label' => 'View all', 'route' => 'stock/?ordering=reorder'], $widget['link']);
    }

    public function test_the_widget_has_the_fields_core_needs(): void
    {
        $widget = $this->build([$this->row(1, 'Panel', 1, 5)]);

        $this->assertSame('stock-reorder', $widget['id']);
        $this->assertSame('Stock to reorder', $widget['title']);
        $this->assertSame(['admin', 'editor'], $widget['roles']);
    }
}
