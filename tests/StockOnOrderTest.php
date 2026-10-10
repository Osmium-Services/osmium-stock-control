<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockOnOrder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockOnOrder.php';

/**
 * How stock that is on order is shown in the admin: the units, the date, and whether the date has passed.
 */
final class StockOnOrderTest extends TestCase
{
    private const TODAY = '2026-10-10';

    public function test_nothing_on_order_shows_nothing(): void
    {
        $this->assertSame(['label' => '', 'state' => 'none'], StockOnOrder::describe(quantity: null, expectedAt: null, today: self::TODAY));
    }

    public function test_zero_on_order_is_the_same_as_nothing(): void
    {
        $this->assertSame('none', StockOnOrder::describe(quantity: 0, expectedAt: null, today: self::TODAY)['state']);
    }

    public function test_units_with_a_future_date_are_due(): void
    {
        $described = StockOnOrder::describe(quantity: 10, expectedAt: '2026-11-12', today: self::TODAY);

        $this->assertSame(['label' => '10 due 12 Nov 2026', 'state' => 'due'], $described);
    }

    public function test_a_date_of_today_is_still_due_not_overdue(): void
    {
        $this->assertSame('due', StockOnOrder::describe(quantity: 4, expectedAt: self::TODAY, today: self::TODAY)['state']);
    }

    public function test_a_date_in_the_past_is_overdue(): void
    {
        $described = StockOnOrder::describe(quantity: 4, expectedAt: '2026-10-09', today: self::TODAY);

        $this->assertSame(['label' => '4 due 9 Oct 2026', 'state' => 'overdue'], $described);
    }

    public function test_units_with_no_date_say_so(): void
    {
        $this->assertSame(['label' => '6 on order', 'state' => 'undated'], StockOnOrder::describe(quantity: 6, expectedAt: null, today: self::TODAY));
    }

    public function test_a_date_with_no_units_is_ignored(): void
    {
        $this->assertSame('none', StockOnOrder::describe(quantity: null, expectedAt: '2026-11-12', today: self::TODAY)['state']);
    }

    public function test_a_real_calendar_date_is_valid(): void
    {
        $this->assertTrue(StockOnOrder::isValidDate('2026-11-12'));
        $this->assertTrue(StockOnOrder::isValidDate('2028-02-29'));
    }

    public function test_impossible_or_oddly_formatted_dates_are_invalid(): void
    {
        foreach (['2026-02-30', '2026-13-01', '12/11/2026', '2026-1-5', 'soon', '', '2026-11-12 10:00'] as $bad) {
            $this->assertFalse(StockOnOrder::isValidDate($bad), $bad);
        }
    }
}
