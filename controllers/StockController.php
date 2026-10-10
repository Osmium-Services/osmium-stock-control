<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\StockControl\Models\StockAdjustment;
use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockControlConfig;
use Osmium\Services\StockControl\Models\StockCsv;
use Osmium\Services\StockControl\Models\StockCsvImport;
use Osmium\Services\StockControl\Models\StockOnOrder;
use Osmium\Services\StockControl\Models\StockLevels;

/**
 * Stock admin - the product list with each figure, a small page to set one, and the AJAX actions behind it.
 *
 * Routes:
 *   - index()  → /admin/stock/
 *   - edit()   → /admin/stock/edit/?id=N   (N is the product id)
 *   - action() → /admin/stock/action/      (save, stop_tracking)
 *   - export() → /admin/stock/export/      (CSV download)
 *   - import() → /admin/stock/import/      (upload a CSV of new figures)
 */
class StockController extends AdminController
{
    private const RECORD_TYPE = 'stock_level';
    private const MAX_IMPORT_BYTES = 2097152;

    public function index(): void
    {
        require_once 'app/modules/admin/core/ListView.php';

        $levels = new StockLevels($this->osmium->dataSource);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $selectedOrdering = (string) $this->osmium->getStashedParam(key: 'ordering', default: '');
        $this->osmium->clearStashedQuerystring(); // Or the filter would stay on after choosing All
        $products = \array_map($this->formatProduct(...), $levels->listProducts());
        $onlyReorder = $selectedOrdering === 'reorder';
        if ($onlyReorder) $products = \array_values(\array_filter($products, static fn(array $product): bool => $product['stock_state'] === 'reorder'));

        $this->data['admin']['stockProducts'] = $products;
        $this->data['admin']['selectedOrdering'] = $onlyReorder ? 'reorder' : '';
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/stock-products.php';

        $this->setView('stock/index.phtml');
    }

    public function edit(): void
    {
        $id = (int) $this->osmium->getStashedParam(key: 'id', default: 0); // Core moves query params out of $_GET
        $this->osmium->clearStashedQuerystring();
        $product = $this->findProduct($id);
        if (!$product) $this->redirect('stock/');

        $this->data['admin']['stockProduct'] = $this->formatProduct($product);

        $this->setView('stock/edit.phtml');
    }

    public function export(): void
    {
        $products = (new StockLevels($this->osmium->dataSource))->listProducts();

        \header('Content-Type: text/csv; charset=utf-8');
        \header('Content-Disposition: attachment; filename="stock-export-' . \date('Y-m-d') . '.csv"');
        echo StockCsv::export($products);
        exit;
    }

    public function import(): void
    {
        $this->data['admin']['importResults'] = $this->isPost() ? $this->runImport() : null;
        $this->data['admin']['maxImportSize'] = (self::MAX_IMPORT_BYTES / 1048576) . ' MB';

        $this->setView('stock/import.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $action = $input['action'] ?? '';
        $id = (int) ($input['id'] ?? 0);

        try {
            $result = match ($action) {
                'save' => $this->save(productId: $id, input: $input),
                'stop_tracking' => $this->stopTracking($id),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    private function runImport(): array
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) return ['error' => 'Invalid form submission. Please try again.'];

        $file = $_FILES['csv_file'] ?? null;
        $uploadFailed = $file === null || $file['error'] !== UPLOAD_ERR_OK;
        if ($uploadFailed) return ['error' => 'File upload failed'];

        $isCsv = \str_ends_with(\strtolower((string) $file['name']), '.csv');
        if (!$isCsv) return ['error' => 'Please upload a CSV file'];

        $tooBig = $file['size'] > self::MAX_IMPORT_BYTES;
        if ($tooBig) return ['error' => 'That file is too big for a stock sheet'];

        $import = new StockCsvImport(levels: new StockLevels($this->osmium->dataSource), adjustment: $this->adjustment());
        $result = $import->apply((string) \file_get_contents($file['tmp_name']));

        $hasChanges = $result['updated'] > 0;
        if ($hasChanges) $this->admin->model->changelog->log(description: 'Imported stock figures from CSV (' . $result['updated'] . ' changed)', recordType: self::RECORD_TYPE);

        return $result;
    }

    private function save(int $productId, array $input): array
    {
        $rawQuantity = \trim((string) ($input['quantity'] ?? ''));
        $isWholeNumber = \preg_match('/^\d{1,9}$/', $rawQuantity) === 1;
        if (!$isWholeNumber) throw new \InvalidArgumentException('Enter a whole number, zero or more');

        $note = \trim((string) ($input['note'] ?? ''));
        $noteTooLong = \mb_strlen($note) > 255;
        if ($noteTooLong) throw new \InvalidArgumentException('A note can be up to 255 characters');

        $reorderLevel = $this->readReorderLevel($input['reorder_level'] ?? '');
        [$onOrder, $expectedAt] = $this->readOnOrder(quantity: $input['on_order'] ?? '', date: $input['expected_at'] ?? '');

        $this->adjustment()->set(productId: $productId, quantity: (int) $rawQuantity, note: $note === '' ? null : $note);
        $this->adjustment()->setReorderLevel(productId: $productId, level: $reorderLevel);
        $this->adjustment()->setOnOrder(productId: $productId, quantity: $onOrder, expectedAt: $expectedAt);
        $this->logChange(action: 'Set stock to ' . (int) $rawQuantity . ' for', productId: $productId);

        return ['success' => true];
    }

    /** A whole number, zero or more, or null when the box was left blank */
    private function readReorderLevel(mixed $raw): ?int
    {
        $raw = \trim((string) $raw);
        $isBlank = $raw === '';
        if ($isBlank) return null;

        $isWholeNumber = \preg_match('/^\d{1,9}$/', $raw) === 1;
        if (!$isWholeNumber) throw new \InvalidArgumentException('Enter the reorder level as a whole number, or leave it blank');

        return (int) $raw;
    }

    /**
     * Units on order and the date they are expected, each null when its box was left blank
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function readOnOrder(mixed $quantity, mixed $date): array
    {
        $quantity = \trim((string) $quantity);
        $date = \trim((string) $date);

        $noUnits = $quantity === '';
        $dateWithoutUnits = $noUnits && $date !== '';
        if ($dateWithoutUnits) throw new \InvalidArgumentException('Enter how many are on order, or clear the expected date');

        $unitsBad = !$noUnits && \preg_match('/^\d{1,9}$/', $quantity) !== 1;
        if ($unitsBad) throw new \InvalidArgumentException('Enter the units on order as a whole number, or leave it blank');

        $dateBad = $date !== '' && !StockOnOrder::isValidDate($date);
        if ($dateBad) throw new \InvalidArgumentException('The expected date is not a valid date');

        return [$noUnits ? null : (int) $quantity, $date === '' ? null : $date];
    }

    private function stopTracking(int $productId): array
    {
        $this->adjustment()->stopTracking($productId);
        $this->logChange(action: 'Stopped tracking stock for', productId: $productId);

        return ['success' => true];
    }

    private function adjustment(): StockAdjustment
    {
        $config = StockControlConfig::get();

        return new StockAdjustment(
            db: $this->osmium->dataSource,
            calculator: new StockCalculator(),
            outOfStockStatusId: $config->outOfStockStatusId,
            inStockStatusId: $config->inStockStatusId,
        );
    }

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    private function findProduct(int $id): ?array
    {
        foreach ((new StockLevels($this->osmium->dataSource))->listProducts() as $product) {
            $isWanted = (int) $product['id'] === $id;
            if ($isWanted) return $product;
        }

        return null;
    }

    private function formatProduct(array $product): array
    {
        $isTracked = $product['quantity'] !== null;
        $quantity = $isTracked ? (int) $product['quantity'] : null;
        $reorderLevel = $product['reorder_level'] === null ? null : (int) $product['reorder_level'];
        $state = (new StockCalculator())->stockState(quantity: $quantity, reorderLevel: $reorderLevel);
        $onOrderQuantity = $product['on_order_qty'] === null ? null : (int) $product['on_order_qty'];
        $onOrder = StockOnOrder::describe(quantity: $onOrderQuantity, expectedAt: $product['expected_at'], today: \date('Y-m-d'));
        $name = \trim(($product['brand_name'] ?? '') . ' ' . $product['model_name']);

        return $product + [
            'name' => $name,
            'edit_url' => $this->getBasePath() . 'stock/edit/?id=' . (int) $product['id'],
            'stock_label' => $isTracked ? (string) (int) $product['quantity'] : 'Not tracked',
            'stock_sort' => $isTracked ? (int) $product['quantity'] : -1,
            'is_tracked' => $isTracked,
            'reorder_label' => $reorderLevel === null ? '' : (string) $reorderLevel,
            'on_order_html' => $this->onOrderHtml($onOrder),
            'on_order_sort' => (string) ($product['expected_at'] ?? ''),
            'on_order_value' => $onOrderQuantity === null ? '' : (string) $onOrderQuantity,
            'expected_value' => (string) ($product['expected_at'] ?? ''),
            'stock_state' => $state,
            'state_label' => ['reorder' => 'Reorder', 'ok' => 'OK', 'untracked' => ''][$state],
            'state_class' => $state === 'reorder' ? 'bg-label-danger' : 'bg-label-success',
        ];
    }

    /** The On order cell: plain text, with a red badge once the date has passed */
    private function onOrderHtml(array $onOrder): string
    {
        $label = \htmlspecialchars($onOrder['label']);

        return match ($onOrder['state']) {
            'none' => '',
            'overdue' => '<span class="badge bg-label-danger">' . $label . ' (overdue)</span>',
            'undated' => '<span class="text-muted">' . $label . '</span>',
            default => $label,
        };
    }

    private function logChange(string $action, int $productId): void
    {
        $product = $this->findProduct($productId);
        $name = $product ? \trim(($product['brand_name'] ?? '') . ' ' . $product['model_name']) : '#' . $productId;

        $this->admin->model->changelog->log(
            description: $action . ': ' . $name,
            recordType: self::RECORD_TYPE,
            recordId: $productId,
        );
    }
}
