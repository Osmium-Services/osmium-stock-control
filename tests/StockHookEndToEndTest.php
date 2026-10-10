<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Core\Library\StoreFinance;
use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Models\CheckoutOrder;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;
use Osmium\Services\StockControl\Models\StockLevels;
use Osmium\Services\StockControl\Models\StockMovements;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/StockCalculator.php';
require_once __DIR__ . '/../models/StockLevels.php';
require_once __DIR__ . '/../models/StockMovements.php';
require_once __DIR__ . '/../models/StockDeduction.php';
require_once __DIR__ . '/../models/StockControlConfig.php';
require_once __DIR__ . '/../models/StockHooks.php';

/**
 * Pays a real order through core's markPaid and checks the shelf: proves the
 * order.paid hook in service.json reaches the stock code. Needs the service
 * installed in the dev database.
 */
class StockHookEndToEndTest extends DatabaseTestCase
{
    private CheckoutBasketService $baskets;
    private ShopOrderService $orders;
    private StockLevels $levels;
    private StockMovements $movements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));
        $this->orders = new ShopOrderService(
            orders: new CheckoutOrder($this->db, 'shop_orders'),
            basket: $this->baskets,
            totals: new ShopTotalsService(taxRatePercent: 20.0, deliveryCharge: 4.99),
            finance: new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        );
        $this->levels = new StockLevels($this->db);
        $this->movements = new StockMovements($this->db);
    }

    private function kitWithBatteryOption(int $batteryId): array
    {
        $kitId = $this->createTestProduct();
        $prefix = $this->db->tablePrefix();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptiontypes (product_id, type_label, type_slug, default_option_descr, sort)
                VALUES (:product, 'Battery', :slug, 'None', 0)",
            bindings: [':product' => $kitId, ':slug' => 'battery-' . \uniqid()],
        )->execute();
        $typeId = (int) $this->db->lastInsertId();
        $this->db->prepare(
            sql: "INSERT INTO {$prefix}productoptionvalues (option_type_id, option_premium, option_descr, option_packed_weight, linked_product_id)
                VALUES (:type, 50.00, 'Battery', 0, :battery)",
            bindings: [':type' => $typeId, ':battery' => $batteryId],
        )->execute();

        return [$kitId, (int) $this->db->lastInsertId()];
    }

    private function pendingOrder(int $productId, int $quantity, array $optionValueIds = []): int
    {
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $productId, quantity: $quantity, optionValueIds: $optionValueIds);

        return $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: [
                'customer_name' => 'Test Customer',
                'customer_email' => 'test@example.com',
                'delivery_address1' => '1 Test Street',
                'delivery_city' => 'Bristol',
                'delivery_postcode' => 'BS1 1AA',
            ],
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        )['id'];
    }

    public function test_paying_a_kit_order_takes_stock_from_the_kit_and_its_chosen_part(): void
    {
        $battery = $this->createTestProduct();
        [$kit, $batteryOption] = $this->kitWithBatteryOption($battery);
        $this->levels->set($kit, 10);
        $this->levels->set($battery, 5);
        $orderId = $this->pendingOrder($kit, 2, [$batteryOption]);

        $this->orders->markPaid(orderId: $orderId, captureRef: 'CAP-1', payerEmail: 'p@example.com');

        $this->assertSame(8, $this->levels->get($kit));
        $this->assertSame(3, $this->levels->get($battery));
        $this->assertCount(expectedCount: 2, haystack: $this->movements->forOrder($orderId));
    }

    public function test_marking_the_same_order_paid_again_does_not_take_stock_twice(): void
    {
        $product = $this->createTestProduct();
        $this->levels->set($product, 10);
        $orderId = $this->pendingOrder($product, 3);

        $this->orders->markPaid(orderId: $orderId, captureRef: 'CAP-1', payerEmail: 'p@example.com');
        $this->orders->markPaid(orderId: $orderId, captureRef: 'CAP-1', payerEmail: 'p@example.com');

        $this->assertSame(7, $this->levels->get($product));
    }

    public function test_an_order_for_products_with_no_stock_figure_is_paid_normally_and_touches_nothing(): void
    {
        $product = $this->createTestProduct();
        $orderId = $this->pendingOrder($product, 1);

        $invoiceNumber = $this->orders->markPaid(orderId: $orderId, captureRef: 'CAP-1', payerEmail: 'p@example.com');

        $this->assertIsInt($invoiceNumber);
        $this->assertNull($this->levels->get($product));
        $this->assertSame([], $this->movements->forOrder($orderId));
    }

    public function test_an_order_that_is_never_paid_takes_nothing(): void
    {
        $product = $this->createTestProduct();
        $this->levels->set($product, 10);

        $this->pendingOrder($product, 3);

        $this->assertSame(10, $this->levels->get($product));
    }

    // ── vetoes ──────────────────────────────────────────────────────────────

    public function test_adding_more_than_the_shelf_holds_is_refused_with_the_number_left(): void
    {
        $product = $this->createTestProduct();
        $this->levels->set($product, 2);
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());

        $this->expectException(ShopOrderException::class);
        $this->expectExceptionMessage('Only 2 in stock');

        $this->baskets->addLine(basketId: $basketId, productId: $product, quantity: 3);
    }

    public function test_adding_up_to_the_shelf_is_allowed(): void
    {
        $product = $this->createTestProduct();
        $this->levels->set($product, 2);
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());

        $this->baskets->addLine(basketId: $basketId, productId: $product, quantity: 2);

        $this->assertCount(expectedCount: 1, haystack: $this->baskets->getLines($basketId));
    }

    public function test_a_kit_is_refused_when_its_chosen_part_is_short(): void
    {
        $battery = $this->createTestProduct();
        [$kit, $batteryOption] = $this->kitWithBatteryOption($battery);
        $this->levels->set($battery, 1);
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());

        $this->expectException(ShopOrderException::class);
        $this->expectExceptionMessage('Only 1 in stock');

        $this->baskets->addLine(basketId: $basketId, productId: $kit, quantity: 2, optionValueIds: [$batteryOption]);
    }

    public function test_a_product_is_counted_across_lines_when_it_is_also_a_kit_part(): void
    {
        $battery = $this->createTestProduct();
        [$kit, $batteryOption] = $this->kitWithBatteryOption($battery);
        $this->levels->set($battery, 2);
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $kit, quantity: 1, optionValueIds: [$batteryOption]);

        $this->expectException(ShopOrderException::class);

        $this->baskets->addLine(basketId: $basketId, productId: $battery, quantity: 2);
    }

    public function test_untracked_products_are_never_refused(): void
    {
        $product = $this->createTestProduct();
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());

        $this->baskets->addLine(basketId: $basketId, productId: $product, quantity: 500);

        $this->assertCount(expectedCount: 1, haystack: $this->baskets->getLines($basketId));
    }

    public function test_checkout_is_refused_when_stock_fell_after_the_basket_was_filled(): void
    {
        $product = $this->createTestProduct();
        $this->levels->set($product, 5);
        $basketId = $this->baskets->getOrCreateBasket('stock-e2e-' . \uniqid());
        $this->baskets->addLine(basketId: $basketId, productId: $product, quantity: 4);
        $this->levels->set($product, 1);

        $this->expectException(ShopOrderException::class);
        $this->expectExceptionMessage('Only 1 in stock');

        $this->orders->createPendingOrder(
            basketId: $basketId,
            customer: ['customer_name' => 'T', 'customer_email' => 't@example.com', 'delivery_address1' => '1 St', 'delivery_city' => 'Bristol', 'delivery_postcode' => 'BS1 1AA'],
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );
    }
}
