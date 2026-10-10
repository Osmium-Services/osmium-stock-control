<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockStateBadge;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockStateBadge.php';

/**
 * The Ordering badge on the stock list: reorder is red, ordered is amber, ok is green.
 */
final class StockStateBadgeTest extends TestCase
{
    public function test_reorder_is_red(): void
    {
        $this->assertSame(['label' => 'Reorder', 'class' => 'bg-label-danger'], StockStateBadge::for('reorder'));
    }

    public function test_ordered_is_amber(): void
    {
        $this->assertSame(['label' => 'Ordered', 'class' => 'bg-label-warning'], StockStateBadge::for('ordered'));
    }

    public function test_ok_is_green(): void
    {
        $this->assertSame(['label' => 'OK', 'class' => 'bg-label-success'], StockStateBadge::for('ok'));
    }

    public function test_an_untracked_product_has_no_label(): void
    {
        $this->assertSame('', StockStateBadge::for('untracked')['label']);
    }
}
