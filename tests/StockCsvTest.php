<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Services\StockControl\Models\StockCsv;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/StockCsv.php';

/**
 * The stock spreadsheet: what the export writes and how an uploaded file is read.
 * A blank "In stock" cell means leave the product alone; a number sets the figure.
 */
final class StockCsvTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private function product(int $id, ?int $quantity, string $brand = 'Acme', string $model = 'Panel', string $reference = '', string $status = 'In stock', ?int $reorder = null, ?int $onOrder = null, ?string $expected = null): array
    {
        return ['id' => $id, 'brand_name' => $brand, 'model_name' => $model, 'reference' => $reference, 'status_label' => $status, 'quantity' => $quantity, 'reorder_level' => $reorder, 'on_order_qty' => $onOrder, 'expected_at' => $expected];
    }

    // ── export ──────────────────────────────────────────────────────────────

    public function test_the_export_starts_with_a_bom_and_the_header(): void
    {
        $csv = StockCsv::export([]);

        $this->assertStringStartsWith(self::BOM . "ID,Brand,Model,Reference,Status,\"In stock\",\"Reorder at\",\"On order\",\"Expected date\"\n", $csv);
    }

    public function test_the_export_writes_the_figure_and_leaves_untracked_blank(): void
    {
        $csv = StockCsv::export([$this->product(5, 12), $this->product(6, null)]);

        $lines = \explode("\n", \trim(\substr($csv, 3)));
        $this->assertSame('5,Acme,Panel,,"In stock",12,,,', $lines[1]);
        $this->assertSame('6,Acme,Panel,,"In stock",,,,', $lines[2]);
    }

    public function test_a_zero_figure_is_exported_as_zero_not_blank(): void
    {
        $csv = StockCsv::export([$this->product(5, 0)]);

        $this->assertStringContainsString(',0,,,' . "\n", $csv);
    }

    // ── import ──────────────────────────────────────────────────────────────

    public function test_a_file_from_the_export_is_read_back(): void
    {
        $csv = StockCsv::export([$this->product(5, 12), $this->product(6, null)]);

        $parsed = StockCsv::parse($csv);

        $this->assertNull($parsed['error']);
        $this->assertSame([['line' => 2, 'id' => 5, 'quantity' => 12, 'reorder' => null, 'onOrder' => null, 'expected' => null], ['line' => 3, 'id' => 6, 'quantity' => null, 'reorder' => null, 'onOrder' => null, 'expected' => null]], $parsed['rows']);
    }

    public function test_a_blank_cell_means_leave_the_product_alone(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n7,\n");

        $this->assertNull($parsed['rows'][0]['quantity']);
    }

    public function test_zero_is_a_real_figure(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n7,0\n");

        $this->assertSame(0, $parsed['rows'][0]['quantity']);
    }

    public function test_a_file_without_an_id_column_is_refused(): void
    {
        $parsed = StockCsv::parse("Name,In stock\nPanel,3\n");

        $this->assertNotNull($parsed['error']);
    }

    public function test_a_file_without_an_in_stock_column_is_refused(): void
    {
        $parsed = StockCsv::parse("ID,Name\n7,Panel\n");

        $this->assertNotNull($parsed['error']);
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->assertNotNull(StockCsv::parse('')['error']);
    }

    public function test_a_title_row_above_the_header_is_skipped(): void
    {
        $parsed = StockCsv::parse("Stock sheet\nID,In stock\n7,4\n");

        $this->assertSame(4, $parsed['rows'][0]['quantity']);
        $this->assertSame(3, $parsed['rows'][0]['line']);
    }

    public function test_columns_can_be_in_any_order(): void
    {
        $parsed = StockCsv::parse("In stock,Model,ID\n9,Panel,7\n");

        $this->assertSame(7, $parsed['rows'][0]['id']);
        $this->assertSame(9, $parsed['rows'][0]['quantity']);
    }

    public function test_a_bad_figure_is_reported_for_its_row_only(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n7,abc\n8,3\n9,-2\n10,2.5\n");

        $this->assertSame(['line' => 2, 'id' => 7, 'error' => "'abc' is not a whole number, zero or more"], $parsed['rows'][0]);
        $this->assertSame(3, $parsed['rows'][1]['quantity']);
        $this->assertArrayHasKey('error', $parsed['rows'][2]);
        $this->assertArrayHasKey('error', $parsed['rows'][3]);
    }

    public function test_a_bad_id_is_reported(): void
    {
        $parsed = StockCsv::parse("ID,In stock\nxyz,3\n0,3\n");

        $this->assertArrayHasKey('error', $parsed['rows'][0]);
        $this->assertArrayHasKey('error', $parsed['rows'][1]);
    }

    public function test_blank_lines_are_ignored(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n\n7,3\n\n");

        $this->assertCount(expectedCount: 1, haystack: $parsed['rows']);
    }

    public function test_spaces_around_values_are_ignored(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n 7 , 4 \n");

        $this->assertSame(['line' => 2, 'id' => 7, 'quantity' => 4, 'reorder' => null, 'onOrder' => null, 'expected' => null], $parsed['rows'][0]);
    }

    public function test_a_file_with_a_bom_is_read(): void
    {
        $parsed = StockCsv::parse(self::BOM . "ID,In stock\n7,4\n");

        $this->assertNull($parsed['error']);
        $this->assertSame(7, $parsed['rows'][0]['id']);
    }

    // ── reorder level column ────────────────────────────────────────────────

    public function test_the_export_writes_the_reorder_level_and_leaves_none_blank(): void
    {
        $csv = StockCsv::export([$this->product(5, 3, reorder: 10), $this->product(6, 3)]);

        $lines = \explode("\n", \trim(\substr($csv, 3)));
        $this->assertSame('5,Acme,Panel,,"In stock",3,10,,', $lines[1]);
        $this->assertSame('6,Acme,Panel,,"In stock",3,,,', $lines[2]);
    }

    public function test_an_export_with_levels_is_read_back(): void
    {
        $parsed = StockCsv::parse(StockCsv::export([$this->product(5, 3, reorder: 10)]));

        $this->assertSame([['line' => 2, 'id' => 5, 'quantity' => 3, 'reorder' => 10, 'onOrder' => null, 'expected' => null]], $parsed['rows']);
    }

    public function test_the_reorder_column_is_optional(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n7,4\n");

        $this->assertNull($parsed['error']);
        $this->assertNull($parsed['rows'][0]['reorder']);
    }

    public function test_a_blank_reorder_cell_means_leave_the_level_alone(): void
    {
        $parsed = StockCsv::parse("ID,In stock,Reorder at\n7,4,\n");

        $this->assertNull($parsed['rows'][0]['reorder']);
    }

    public function test_a_reorder_level_of_zero_is_a_real_level(): void
    {
        $parsed = StockCsv::parse("ID,In stock,Reorder at\n7,4,0\n");

        $this->assertSame(0, $parsed['rows'][0]['reorder']);
    }

    public function test_a_bad_reorder_level_is_reported_for_its_row(): void
    {
        $parsed = StockCsv::parse("ID,In stock,Reorder at\n7,4,lots\n8,4,-1\n9,4,2.5\n");

        foreach ([0, 1, 2] as $index) {
            $this->assertArrayHasKey('error', $parsed['rows'][$index]);
        }
        $this->assertSame("'lots' is not a whole number, zero or more, for Reorder at", $parsed['rows'][0]['error']);
    }

    public function test_a_file_with_only_a_reorder_column_and_no_in_stock_column_is_still_refused(): void
    {
        $this->assertNotNull(StockCsv::parse("ID,Reorder at\n7,4\n")['error']);
    }

    // ── on order columns ────────────────────────────────────────────────────

    public function test_the_export_writes_units_on_order_and_the_date(): void
    {
        $csv = StockCsv::export([$this->product(5, 3, onOrder: 12, expected: '2026-11-12'), $this->product(6, 3, onOrder: 7)]);

        $lines = \explode("\n", \trim(\substr($csv, 3)));
        $this->assertSame('5,Acme,Panel,,"In stock",3,,12,2026-11-12', $lines[1]);
        $this->assertSame('6,Acme,Panel,,"In stock",3,,7,', $lines[2]);
    }

    public function test_an_export_with_on_order_is_read_back(): void
    {
        $parsed = StockCsv::parse(StockCsv::export([$this->product(5, 3, onOrder: 12, expected: '2026-11-12')]));

        $this->assertSame(12, $parsed['rows'][0]['onOrder']);
        $this->assertSame('2026-11-12', $parsed['rows'][0]['expected']);
    }

    public function test_the_on_order_columns_are_optional(): void
    {
        $parsed = StockCsv::parse("ID,In stock\n7,4\n");

        $this->assertNull($parsed['rows'][0]['onOrder']);
        $this->assertNull($parsed['rows'][0]['expected']);
    }

    public function test_blank_on_order_cells_mean_leave_them_alone(): void
    {
        $parsed = StockCsv::parse("ID,In stock,On order,Expected date\n7,4,,\n");

        $this->assertNull($parsed['rows'][0]['onOrder']);
        $this->assertNull($parsed['rows'][0]['expected']);
    }

    public function test_zero_on_order_is_a_real_figure(): void
    {
        $parsed = StockCsv::parse("ID,In stock,On order\n7,4,0\n");

        $this->assertSame(0, $parsed['rows'][0]['onOrder']);
    }

    public function test_a_uk_style_date_from_excel_is_read(): void
    {
        $parsed = StockCsv::parse("ID,In stock,On order,Expected date\n7,4,5,12/11/2026\n");

        $this->assertSame('2026-11-12', $parsed['rows'][0]['expected']);
    }

    public function test_a_bad_on_order_figure_is_reported_for_its_row(): void
    {
        $parsed = StockCsv::parse("ID,In stock,On order\n7,4,many\n");

        $this->assertSame("'many' is not a whole number, zero or more, for On order", $parsed['rows'][0]['error']);
    }

    public function test_a_bad_date_is_reported_for_its_row(): void
    {
        $parsed = StockCsv::parse("ID,In stock,On order,Expected date\n7,4,5,soon\n");

        $this->assertSame("'soon' is not a date: use YYYY-MM-DD or DD/MM/YYYY, for Expected date", $parsed['rows'][0]['error']);
    }
}
