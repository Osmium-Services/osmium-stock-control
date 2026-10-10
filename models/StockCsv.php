<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * The stock spreadsheet. The export lists every product with its figure (blank when not tracked);
 * an uploaded file is read the same way, matching products by ID. Free of the database.
 */
class StockCsv
{
    private const BOM = "\xEF\xBB\xBF";
    private const HEADER = ['ID', 'Brand', 'Model', 'Reference', 'Status', 'In stock', 'Reorder at', 'On order', 'Expected date'];

    /**
     * @param array<int, array<string, mixed>> $products As StockLevels::listProducts() returns them
     */
    public static function export(array $products): string
    {
        $stream = \fopen('php://temp', 'r+');
        \fputcsv(stream: $stream, fields: self::HEADER, escape: '\\');

        foreach ($products as $product) {
            $quantity = $product['quantity'] === null ? '' : (int) $product['quantity'];
            $reorder = ($product['reorder_level'] ?? null) === null ? '' : (int) $product['reorder_level'];
            $onOrder = ($product['on_order_qty'] ?? null) === null ? '' : (int) $product['on_order_qty'];
            $expected = (string) ($product['expected_at'] ?? '');
            \fputcsv(
                stream: $stream,
                fields: [(int) $product['id'], $product['brand_name'], $product['model_name'], $product['reference'], $product['status_label'], $quantity, $reorder, $onOrder, $expected],
                escape: '\\',
            );
        }

        \rewind($stream);

        return self::BOM . (string) \stream_get_contents($stream);
    }

    /**
     * @return array{error: ?string, rows: array<int, array{line: int, id: int, quantity: ?int, reorder: ?int, onOrder: ?int, expected: ?string}|array{line: int, id: int, error: string}>}
     *         One entry per data row. quantity or reorder null means the cell was blank (or the column is missing): leave that alone.
     */
    public static function parse(string $content): array
    {
        $stream = \fopen('php://temp', 'r+');
        \fwrite($stream, \str_starts_with($content, self::BOM) ? \substr($content, 3) : $content);
        \rewind($stream);

        $line = 0;
        $columns = null;
        while ($columns === null) {
            $header = \fgetcsv(stream: $stream, escape: '\\');
            $line++;
            $endOfFile = $header === false;
            if ($endOfFile) return ['error' => 'No header row found: the file needs ID and In stock columns', 'rows' => []];

            $columns = self::columnsOf($header);
        }

        $missing = $columns['id'] === false || $columns['quantity'] === false;
        if ($missing) return ['error' => 'The file needs an ID column and an In stock column', 'rows' => []];

        $rows = [];
        while (($cells = \fgetcsv(stream: $stream, escape: '\\')) !== false) {
            $line++;
            $isBlankLine = $cells === [null];
            if ($isBlankLine) continue;

            $rows[] = self::readRow(cells: $cells, columns: $columns, line: $line);
        }

        return ['error' => null, 'rows' => $rows];
    }

    /** @return array{id: int|false, quantity: int|false, reorder: int|false, onOrder: int|false, expected: int|false}|null null when the line is not a header at all */
    private static function columnsOf(array $header): ?array
    {
        $names = \array_map(static fn(?string $name): string => \trim((string) $name), $header);
        $id = \array_search('ID', $names, true);
        $quantity = \array_search('In stock', $names, true);

        $looksLikeHeader = $id !== false || $quantity !== false;
        if (!$looksLikeHeader) return null;

        $reorder = \array_search('Reorder at', $names, true);

        $onOrder = \array_search('On order', $names, true);
        $expected = \array_search('Expected date', $names, true);

        return ['id' => $id, 'quantity' => $quantity, 'reorder' => $reorder, 'onOrder' => $onOrder, 'expected' => $expected];
    }

    private static function readRow(array $cells, array $columns, int $line): array
    {
        $rawId = \trim((string) ($cells[$columns['id']] ?? ''));
        $rawQuantity = \trim((string) ($cells[$columns['quantity']] ?? ''));
        $rawReorder = $columns['reorder'] === false ? '' : \trim((string) ($cells[$columns['reorder']] ?? ''));
        $rawOnOrder = $columns['onOrder'] === false ? '' : \trim((string) ($cells[$columns['onOrder']] ?? ''));
        $rawExpected = $columns['expected'] === false ? '' : \trim((string) ($cells[$columns['expected']] ?? ''));

        $idValid = \preg_match('/^[1-9]\d{0,9}$/', $rawId) === 1;
        if (!$idValid) return ['line' => $line, 'id' => 0, 'error' => "'{$rawId}' is not a valid product ID"];

        $id = (int) $rawId;
        $quantityBad = $rawQuantity !== '' && \preg_match('/^\d{1,9}$/', $rawQuantity) !== 1;
        if ($quantityBad) return ['line' => $line, 'id' => $id, 'error' => "'{$rawQuantity}' is not a whole number, zero or more"];

        $reorderBad = $rawReorder !== '' && \preg_match('/^\d{1,9}$/', $rawReorder) !== 1;
        if ($reorderBad) return ['line' => $line, 'id' => $id, 'error' => "'{$rawReorder}' is not a whole number, zero or more, for Reorder at"];

        $onOrderBad = $rawOnOrder !== '' && \preg_match('/^\d{1,9}$/', $rawOnOrder) !== 1;
        if ($onOrderBad) return ['line' => $line, 'id' => $id, 'error' => "'{$rawOnOrder}' is not a whole number, zero or more, for On order"];

        $expected = $rawExpected === '' ? null : StockOnOrder::normaliseDate($rawExpected);
        $expectedBad = $rawExpected !== '' && $expected === null;
        if ($expectedBad) return ['line' => $line, 'id' => $id, 'error' => "'{$rawExpected}' is not a date: use YYYY-MM-DD or DD/MM/YYYY, for Expected date"];

        return [
            'line' => $line,
            'id' => $id,
            'quantity' => $rawQuantity === '' ? null : (int) $rawQuantity,
            'reorder' => $rawReorder === '' ? null : (int) $rawReorder,
            'onOrder' => $rawOnOrder === '' ? null : (int) $rawOnOrder,
            'expected' => $expected,
        ];
    }
}
