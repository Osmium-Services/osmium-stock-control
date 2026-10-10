<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockListFilter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockListFilter.php';

/**
 * The Ordering filter on the stock list: which products each option shows.
 */
final class StockListFilterTest extends TestCase
{
    private function product(int $id, string $state, string $onOrderState = 'none'): array
    {
        return ['id' => $id, 'stock_state' => $state, 'on_order_state' => $onOrderState];
    }

    /** @return array<int, array<string, mixed>> */
    private function products(): array
    {
        return [
            $this->product(1, 'ok'),
            $this->product(2, 'reorder'),
            $this->product(3, 'ordered', 'due'),
            $this->product(4, 'ordered', 'overdue'),
            $this->product(5, 'ok', 'overdue'),
            $this->product(6, 'untracked'),
        ];
    }

    private function ids(array $products): array
    {
        return \array_column($products, 'id');
    }

    public function test_the_known_options_are_kept_and_anything_else_means_all(): void
    {
        $this->assertSame('reorder', StockListFilter::normalise('reorder'));
        $this->assertSame('ordered', StockListFilter::normalise('ordered'));
        $this->assertSame('overdue', StockListFilter::normalise('overdue'));
        $this->assertSame('', StockListFilter::normalise('nonsense'));
        $this->assertSame('', StockListFilter::normalise(''));
    }

    public function test_all_shows_every_product(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->ids(StockListFilter::apply($this->products(), '')));
    }

    public function test_needs_reorder_shows_only_products_that_need_ordering(): void
    {
        $this->assertSame([2], $this->ids(StockListFilter::apply($this->products(), 'reorder')));
    }

    public function test_ordered_shows_the_products_already_on_order_and_low(): void
    {
        $this->assertSame([3, 4], $this->ids(StockListFilter::apply($this->products(), 'ordered')));
    }

    public function test_overdue_shows_every_product_with_a_late_delivery(): void
    {
        $this->assertSame([4, 5], $this->ids(StockListFilter::apply($this->products(), 'overdue')));
    }

    public function test_the_result_is_a_plain_list_without_gaps_in_its_keys(): void
    {
        $this->assertSame([0], \array_keys(StockListFilter::apply($this->products(), 'reorder')));
    }

    public function test_the_options_offered_are_all_reorder_ordered_and_overdue_in_that_order(): void
    {
        $this->assertSame(
            [['', 'All'], ['reorder', 'Needs reorder'], ['ordered', 'Ordered'], ['overdue', 'Overdue delivery']],
            StockListFilter::options(),
        );
    }
}
