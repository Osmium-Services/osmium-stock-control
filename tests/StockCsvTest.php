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

    private function product(int $id, ?int $quantity, string $brand = 'Acme', string $model = 'Panel', string $reference = '', string $status = 'In stock'): array
    {
        return ['id' => $id, 'brand_name' => $brand, 'model_name' => $model, 'reference' => $reference, 'status_label' => $status, 'quantity' => $quantity];
    }

    // ── export ──────────────────────────────────────────────────────────────

    public function test_the_export_starts_with_a_bom_and_the_header(): void
    {
        $csv = StockCsv::export([]);

        $this->assertStringStartsWith(self::BOM . "ID,Brand,Model,Reference,Status,\"In stock\"\n", $csv);
    }

    public function test_the_export_writes_the_figure_and_leaves_untracked_blank(): void
    {
        $csv = StockCsv::export([$this->product(5, 12), $this->product(6, null)]);

        $lines = \explode("\n", \trim(\substr($csv, 3)));
        $this->assertSame('5,Acme,Panel,,"In stock",12', $lines[1]);
        $this->assertSame('6,Acme,Panel,,"In stock",', $lines[2]);
    }

    public function test_a_zero_figure_is_exported_as_zero_not_blank(): void
    {
        $csv = StockCsv::export([$this->product(5, 0)]);

        $this->assertStringContainsString(',0' . "\n", $csv);
    }

    // ── import ──────────────────────────────────────────────────────────────

    public function test_a_file_from_the_export_is_read_back(): void
    {
        $csv = StockCsv::export([$this->product(5, 12), $this->product(6, null)]);

        $parsed = StockCsv::parse($csv);

        $this->assertNull($parsed['error']);
        $this->assertSame([['line' => 2, 'id' => 5, 'quantity' => 12], ['line' => 3, 'id' => 6, 'quantity' => null]], $parsed['rows']);
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

        $this->assertSame(['line' => 2, 'id' => 7, 'quantity' => 4], $parsed['rows'][0]);
    }

    public function test_a_file_with_a_bom_is_read(): void
    {
        $parsed = StockCsv::parse(self::BOM . "ID,In stock\n7,4\n");

        $this->assertNull($parsed['error']);
        $this->assertSame(7, $parsed['rows'][0]['id']);
    }
}
