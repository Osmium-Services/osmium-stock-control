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

    // ── writing the file ────────────────────────────────────────────────────

    public function test_a_new_file_gets_a_php_guard_and_the_chosen_statuses(): void
    {
        $content = StockControlConfig::toContent(existing: null, inStockStatusId: 1, outOfStockStatusId: 9);

        $this->assertStringStartsWith('<?php exit(); ?>', $content);
        $config = StockControlConfig::fromContent($content);
        $this->assertSame(1, $config->inStockStatusId);
        $this->assertSame(9, $config->outOfStockStatusId);
    }

    public function test_an_existing_header_and_other_settings_are_kept(): void
    {
        $existing = self::HEADER . \json_encode(['other' => ['keep' => true], 'stockControl' => ['inStockStatusId' => 1]]);

        $content = StockControlConfig::toContent(existing: $existing, inStockStatusId: 2, outOfStockStatusId: 3);

        $this->assertStringStartsWith(self::HEADER, $content);
        $json = \json_decode(\substr($content, \strpos($content, '{')), true);
        $this->assertSame(['keep' => true], $json['other']);
        $this->assertSame(2, $json['stockControl']['inStockStatusId']);
    }

    public function test_clearing_a_status_saves_it_as_unset(): void
    {
        $content = StockControlConfig::toContent(existing: null, inStockStatusId: null, outOfStockStatusId: null);

        $config = StockControlConfig::fromContent($content);
        $this->assertNull($config->inStockStatusId);
        $this->assertNull($config->outOfStockStatusId);
    }

    // ── checking the choice ─────────────────────────────────────────────────

    private function statusRow(bool $available): array
    {
        return ['product_available' => $available ? 1 : 0];
    }

    public function test_choosing_neither_is_fine(): void
    {
        $this->assertNull(StockControlConfig::problem(inStock: null, outOfStock: null));
    }

    public function test_choosing_only_one_is_refused(): void
    {
        $this->assertNotNull(StockControlConfig::problem(inStock: $this->statusRow(true), outOfStock: null));
    }

    public function test_a_valid_pair_is_accepted(): void
    {
        $this->assertNull(StockControlConfig::problem(inStock: $this->statusRow(true), outOfStock: $this->statusRow(false)));
    }

    public function test_an_in_stock_status_that_is_not_available_is_refused(): void
    {
        $this->assertNotNull(StockControlConfig::problem(inStock: $this->statusRow(false), outOfStock: $this->statusRow(false)));
    }

    public function test_an_out_of_stock_status_that_is_available_is_refused(): void
    {
        $this->assertNotNull(StockControlConfig::problem(inStock: $this->statusRow(true), outOfStock: $this->statusRow(true)));
    }
}
