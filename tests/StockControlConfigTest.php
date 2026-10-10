<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockControlConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockControlConfig.php';

/**
 * The service's own settings: which statuses mean in stock and out of stock.
 */
final class StockControlConfigTest extends TestCase
{
    private const HEADER = "<?php /* settings */ ?>\n";

    public function test_no_settings_means_no_statuses_so_nothing_is_flipped(): void
    {
        $config = StockControlConfig::fromContent(null);

        $this->assertNull($config->outOfStockStatusId);
        $this->assertNull($config->inStockStatusId);
    }

    public function test_saved_status_ids_are_read_after_the_php_header(): void
    {
        $json = \json_encode(['stockControl' => ['outOfStockStatusId' => 9, 'inStockStatusId' => 1]]);

        $config = StockControlConfig::fromContent(self::HEADER . $json);

        $this->assertSame(9, $config->outOfStockStatusId);
        $this->assertSame(1, $config->inStockStatusId);
    }

    public function test_ids_saved_as_strings_are_read_as_integers(): void
    {
        $json = \json_encode(['stockControl' => ['outOfStockStatusId' => '9', 'inStockStatusId' => '1']]);

        $this->assertSame(9, StockControlConfig::fromContent(self::HEADER . $json)->outOfStockStatusId);
    }

    public function test_a_missing_or_blank_or_zero_id_is_not_configured(): void
    {
        $json = \json_encode(['stockControl' => ['outOfStockStatusId' => '', 'inStockStatusId' => 0]]);

        $config = StockControlConfig::fromContent(self::HEADER . $json);

        $this->assertNull($config->outOfStockStatusId);
        $this->assertNull($config->inStockStatusId);
    }

    public function test_one_status_alone_is_kept_as_it_was_saved(): void
    {
        $json = \json_encode(['stockControl' => ['outOfStockStatusId' => 9]]);

        $config = StockControlConfig::fromContent(self::HEADER . $json);

        $this->assertSame(9, $config->outOfStockStatusId);
        $this->assertNull($config->inStockStatusId);
    }

    public function test_unreadable_settings_fall_back_to_nothing_configured(): void
    {
        $this->assertNull(StockControlConfig::fromContent(self::HEADER . '{ not json')->outOfStockStatusId);
        $this->assertNull(StockControlConfig::fromContent('no braces at all')->outOfStockStatusId);
    }
}
