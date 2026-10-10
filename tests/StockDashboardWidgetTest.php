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

    // ── overdue deliveries ──────────────────────────────────────────────────

    private const TODAY = '2026-10-10';

    private function onOrder(int $id, string $name, ?int $quantity, ?string $date): array
    {
        return ['id' => $id, 'name' => $name, 'on_order_qty' => $quantity, 'expected_at' => $date];
    }

    private function overdue(array $rows): ?array
    {
        return StockDashboardWidget::overdue(rows: $rows, today: self::TODAY);
    }

    public function test_there_is_no_overdue_card_when_nothing_is_on_order(): void
    {
        $this->assertNull($this->overdue([]));
    }

    public function test_there_is_no_overdue_card_when_nothing_is_late(): void
    {
        $this->assertNull($this->overdue([
            $this->onOrder(1, 'Due later', 5, '2026-11-01'),
            $this->onOrder(2, 'Due today', 5, self::TODAY),
            $this->onOrder(3, 'No date', 5, null),
        ]));
    }

    public function test_a_late_delivery_makes_the_card_appear_with_a_count(): void
    {
        $widget = $this->overdue([$this->onOrder(1, 'Late', 5, '2026-10-01'), $this->onOrder(2, 'Fine', 5, '2026-11-01')]);

        $this->assertSame(1, $widget['count']);
        $this->assertSame(['Late'], \array_column($widget['lines'], 'text'));
    }

    public function test_the_longest_overdue_is_first(): void
    {
        $widget = $this->overdue([
            $this->onOrder(1, 'Few days', 5, '2026-10-07'),
            $this->onOrder(2, 'Weeks', 5, '2026-09-01'),
            $this->onOrder(3, 'Some', 5, '2026-09-25'),
        ]);

        $this->assertSame(['Weeks', 'Some', 'Few days'], \array_column($widget['lines'], 'text'));
    }

    public function test_a_line_says_the_units_the_date_and_how_late(): void
    {
        $widget = $this->overdue([$this->onOrder(1, 'Panel', 5, '2026-09-30'), $this->onOrder(2, 'Yesterday', 2, '2026-10-09')]);

        $subs = \array_column($widget['lines'], 'sub', 'text');
        $this->assertSame('5 due 30 Sep 2026, 10 days late', $subs['Panel']);
        $this->assertSame('2 due 9 Oct 2026, 1 day late', $subs['Yesterday']);
    }

    public function test_the_overdue_card_lists_at_most_five_but_counts_them_all(): void
    {
        $rows = [];
        foreach (\range(1, 7) as $id) {
            $rows[] = $this->onOrder($id, "Product {$id}", 1, '2026-09-01');
        }

        $widget = $this->overdue($rows);

        $this->assertSame(7, $widget['count']);
        $this->assertCount(expectedCount: 5, haystack: $widget['lines']);
    }

    public function test_an_overdue_line_links_to_set_stock_and_the_header_to_the_overdue_filter(): void
    {
        $widget = $this->overdue([$this->onOrder(42, 'Panel', 5, '2026-09-30')]);

        $this->assertSame('stock/edit/?id=42', $widget['lines'][0]['route']);
        $this->assertSame(['label' => 'View all', 'route' => 'stock/?ordering=overdue'], $widget['link']);
    }

    public function test_the_overdue_card_has_the_fields_core_needs(): void
    {
        $widget = $this->overdue([$this->onOrder(1, 'Panel', 5, '2026-09-30')]);

        $this->assertSame('stock-overdue', $widget['id']);
        $this->assertSame('Deliveries overdue', $widget['title']);
        $this->assertSame(['admin', 'editor'], $widget['roles']);
    }

    public function test_a_row_with_a_date_but_no_units_is_ignored(): void
    {
        $this->assertNull($this->overdue([$this->onOrder(1, 'Ghost', null, '2026-09-01'), $this->onOrder(2, 'Zero', 0, '2026-09-01')]));
    }
}
