<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

/**
 * Applies an uploaded stock spreadsheet. Each figure goes through StockAdjustment, the same
 * code as the Set stock page, so every change is audited and the product's status follows.
 * A bad row is reported and the rest still apply.
 */
class StockCsvImport
{
    private const NOTE = 'CSV import';

    public function __construct(
        private StockLevels $levels,
        private StockAdjustment $adjustment,
    ) {}

    /**
     * @return array{error: ?string, updated: int, unchanged: int, skipped: int, errors: array<int, string>}
     */
    public function apply(string $content): array
    {
        $result = ['error' => null, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => []];

        $parsed = StockCsv::parse($content);
        $unreadable = $parsed['error'] !== null;
        if ($unreadable) return ['error' => $parsed['error']] + $result;

        foreach ($parsed['rows'] as $row) {
            $outcome = $this->applyRow($row);
            $isError = \is_string($outcome) && \str_starts_with($outcome, 'Row ');
            if ($isError) $result['errors'][] = $outcome;
            if (!$isError) $result[$outcome]++;
        }

        return $result;
    }

    /** @return string 'updated', 'unchanged', 'skipped', or an error message starting "Row N: " */
    private function applyRow(array $row): string
    {
        $line = (int) $row['line'];

        $hasError = isset($row['error']);
        if ($hasError) return "Row {$line}: {$row['error']}";

        $nothingToDo = $row['quantity'] === null && $row['reorder'] === null && $row['onOrder'] === null && $row['expected'] === null;
        if ($nothingToDo) return 'skipped';

        $current = $this->levels->get($row['id']);
        $levelOnlyForUntracked = $row['quantity'] === null && $current === null;
        if ($levelOnlyForUntracked) return "Row {$line}: Product ID {$row['id']} is not tracked yet: give it an In stock figure as well";

        $dateWithoutUnits = $this->dateWithoutUnits($row);
        if ($dateWithoutUnits) return "Row {$line}: Product ID {$row['id']} has nothing on order, so it cannot have an expected date";

        try {
            $changed = $this->applyChanges($row, $current);
        } catch (\InvalidArgumentException $e) {
            return "Row {$line}: Product ID {$row['id']} not found";
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /** @return bool Whether anything was changed */
    private function applyChanges(array $row, ?int $current): bool
    {
        $changed = false;

        $quantityChanges = $row['quantity'] !== null && $current !== $row['quantity'];
        if ($quantityChanges) {
            $this->adjustment->set(productId: $row['id'], quantity: $row['quantity'], note: self::NOTE);
            $changed = true;
        }

        $levelChanges = $row['reorder'] !== null && $this->levels->reorderLevel($row['id']) !== $row['reorder'];
        if ($levelChanges) {
            $this->adjustment->setReorderLevel(productId: $row['id'], level: $row['reorder']);
            $changed = true;
        }

        $onOrderChanges = $this->applyOnOrder($row);

        return $changed || $onOrderChanges;
    }

    /** Whether an expected date was given for a product that will have no units on order */
    private function dateWithoutUnits(array $row): bool
    {
        $noDate = $row['expected'] === null;
        if ($noDate) return false;

        $units = $row['onOrder'] ?? $this->levels->onOrderQuantity($row['id']);

        return $units === null || $units === 0;
    }

    /** @return bool Whether the on-order figures changed */
    private function applyOnOrder(array $row): bool
    {
        $nothingGiven = $row['onOrder'] === null && $row['expected'] === null;
        if ($nothingGiven) return false;

        $current = $this->levels->onOrder($row['id']);
        $units = $row['onOrder'] ?? $current['quantity'];
        $date = $row['expected'] ?? $current['expectedAt'];

        $clears = $units === 0;
        if ($clears) {
            $hadSomething = $current['quantity'] !== null;
            if ($hadSomething) $this->adjustment->setOnOrder(productId: $row['id'], quantity: null, expectedAt: null);

            return $hadSomething;
        }

        $same = $units === $current['quantity'] && $date === $current['expectedAt'];
        if ($same) return false;

        $this->adjustment->setOnOrder(productId: $row['id'], quantity: $units, expectedAt: $date);

        return true;
    }
}
