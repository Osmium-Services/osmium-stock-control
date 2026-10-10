<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockHistory.php';

/**
 * One line of a product's stock history, as the History page shows it.
 */
final class StockHistoryTest extends TestCase
{
    private function movement(array $overrides = []): array
    {
        return $overrides + ['id' => 1, 'order_id' => null, 'change_qty' => 5, 'short_qty' => 0, 'reason' => 'manual', 'note' => null, 'created_at' => '2026-10-10 14:05:09'];
    }

    public function test_an_increase_has_a_plus_and_is_green(): void
    {
        $row = StockHistory::row($this->movement(['change_qty' => 5]));

        $this->assertSame('+5', $row['change']);
        $this->assertSame('text-success', $row['changeClass']);
    }

    public function test_a_decrease_has_a_minus_and_is_red(): void
    {
        $row = StockHistory::row($this->movement(['change_qty' => -3]));

        $this->assertSame('-3', $row['change']);
        $this->assertSame('text-danger', $row['changeClass']);
    }

    public function test_a_note_only_entry_shows_no_movement(): void
    {
        $row = StockHistory::row($this->movement(['change_qty' => 0, 'note' => 'Recounted']));

        $this->assertSame('0', $row['change']);
        $this->assertSame('text-muted', $row['changeClass']);
    }

    public function test_the_reasons_have_plain_labels(): void
    {
        $this->assertSame('Sale', StockHistory::row($this->movement(['reason' => 'sale']))['reason']);
        $this->assertSame('Refund returned', StockHistory::row($this->movement(['reason' => 'return']))['reason']);
        $this->assertSame('Adjustment', StockHistory::row($this->movement(['reason' => 'manual']))['reason']);
    }

    public function test_a_shortfall_is_called_out(): void
    {
        $row = StockHistory::row($this->movement(['reason' => 'sale', 'change_qty' => -2, 'short_qty' => 3]));

        $this->assertSame('Short by 3', $row['shortfall']);
    }

    public function test_no_shortfall_says_nothing(): void
    {
        $this->assertSame('', StockHistory::row($this->movement())['shortfall']);
    }

    public function test_the_note_is_kept_and_a_missing_note_is_empty(): void
    {
        $this->assertSame('Delivery received', StockHistory::row($this->movement(['note' => 'Delivery received']))['note']);
        $this->assertSame('', StockHistory::row($this->movement(['note' => null]))['note']);
    }

    public function test_the_date_is_shown_with_the_time(): void
    {
        $this->assertSame('10 Oct 2026, 14:05', StockHistory::row($this->movement())['when']);
    }

    public function test_an_order_id_is_passed_through_only_when_there_is_one(): void
    {
        $this->assertSame(42, StockHistory::row($this->movement(['order_id' => 42]))['orderId']);
        $this->assertNull(StockHistory::row($this->movement())['orderId']);
    }
}
