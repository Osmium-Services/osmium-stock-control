<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockDeduction;
use Osmium\Services\StockControl\Models\StockLevels;
use Osmium\Services\StockControl\Models\StockMovements;
use Osmium\Services\StockControl\Models\StockReturn;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/StockCalculator.php';
require_once __DIR__ . '/../models/StockLevels.php';
require_once __DIR__ . '/../models/StockMovements.php';
require_once __DIR__ . '/../models/StockStatusSync.php';
require_once __DIR__ . '/../models/StockDeduction.php';
require_once __DIR__ . '/../models/StockReturn.php';

/**
 * Putting a refunded order's items back on the shelf: only what the sale really
 * took, only once, and only for products that are still tracked.
 */
class StockReturnTest extends DatabaseTestCase
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

    private function product(int $quantity): int
    {
        $productId = $this->createTestProduct(['status_id' => $this->inStock]);
        $this->levels->set($productId, $quantity);

        return $productId;
    }

    private function statusOf(int $productId): int
    {
        $prefix = $this->db->tablePrefix();
        $row = $this->db->prepare(sql: "SELECT status_id FROM {$prefix}products WHERE id = :id", bindings: [':id' => $productId])->single();

        return (int) $row['status_id'];
    }

    private function paidOrder(int $productId, int $quantity, int $orderId = 6001): int
    {
        $order = ['id' => $orderId, 'items' => [['id' => $orderId * 10, 'product_id' => $productId, 'quantity' => $quantity, 'parts' => []]]];
        (new StockDeduction($this->db, new StockCalculator(), $this->outOfStock, $this->inStock))->deductForPaidOrder($order);

        return $orderId;
    }

    private function returns(): StockReturn
    {
        return new StockReturn($this->db, new StockCalculator(), $this->outOfStock, $this->inStock);
    }

    public function test_returning_an_order_puts_back_what_the_sale_took(): void
    {
        $product = $this->product(10);
        $orderId = $this->paidOrder($product, 3);

        $this->returns()->returnOrder($orderId);

        $this->assertSame(10, $this->levels->get($product));
    }

    public function test_a_short_sale_returns_only_what_was_really_taken(): void
    {
        $product = $this->product(2);
        $orderId = $this->paidOrder($product, 5);

        $this->returns()->returnOrder($orderId);

        $this->assertSame(2, $this->levels->get($product));
    }

    public function test_the_return_is_recorded_once_per_line_and_product(): void
    {
        $product = $this->product(10);
        $orderId = $this->paidOrder($product, 3);

        $this->returns()->returnOrder($orderId);

        $reasons = \array_column($this->movements->forOrder($orderId), 'reason');
        $this->assertSame(['sale', 'return'], $reasons);
    }

    public function test_returning_the_same_order_twice_is_refused_and_adds_nothing(): void
    {
        $product = $this->product(10);
        $orderId = $this->paidOrder($product, 3);
        $this->returns()->returnOrder($orderId);

        try {
            $this->returns()->returnOrder($orderId);
            $this->fail('A second return should be refused');
        } catch (\RuntimeException) {
            $this->assertSame(10, $this->levels->get($product));
        }
    }

    public function test_an_order_that_took_no_stock_cannot_be_returned(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('took nothing from stock');

        $this->returns()->returnOrder(987654);
    }

    public function test_a_product_no_longer_tracked_is_left_alone(): void
    {
        $product = $this->product(10);
        $orderId = $this->paidOrder($product, 3);
        $this->levels->stopTracking($product);

        try {
            $this->returns()->returnOrder($orderId);
        } catch (\RuntimeException) {
            // Nothing left to put back is also an acceptable answer
        }

        $this->assertNull($this->levels->get($product));
    }

    public function test_returning_stock_moves_an_out_of_stock_product_back_to_in_stock(): void
    {
        $product = $this->product(3);
        $orderId = $this->paidOrder($product, 3);
        $this->assertSame($this->outOfStock, $this->statusOf($product));

        $this->returns()->returnOrder($orderId);

        $this->assertSame($this->inStock, $this->statusOf($product));
    }
}
