<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\StockControl\Models\StockAdjustment;
use Osmium\Services\StockControl\Models\StockCalculator;
use Osmium\Services\StockControl\Models\StockControlConfig;
use Osmium\Services\StockControl\Models\StockLevels;

/**
 * Stock admin - the product list with each figure, a small page to set one, and the AJAX actions behind it.
 *
 * Routes:
 *   - index()  → /admin/stock/
 *   - edit()   → /admin/stock/edit/?id=N   (N is the product id)
 *   - action() → /admin/stock/action/      (save, stop_tracking)
 */
class StockController extends AdminController
{
    private const RECORD_TYPE = 'stock_level';

    public function index(): void
    {
        require_once 'app/modules/admin/core/ListView.php';

        $levels = new StockLevels($this->osmium->dataSource);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['stockProducts'] = \array_map($this->formatProduct(...), $levels->listProducts());
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

    private function save(int $productId, array $input): array
    {
        $rawQuantity = \trim((string) ($input['quantity'] ?? ''));
        $isWholeNumber = \preg_match('/^\d{1,9}$/', $rawQuantity) === 1;
        if (!$isWholeNumber) throw new \InvalidArgumentException('Enter a whole number, zero or more');

        $note = \trim((string) ($input['note'] ?? ''));
        $noteTooLong = \mb_strlen($note) > 255;
        if ($noteTooLong) throw new \InvalidArgumentException('A note can be up to 255 characters');

        $this->adjustment()->set(productId: $productId, quantity: (int) $rawQuantity, note: $note === '' ? null : $note);
        $this->logChange(action: 'Set stock to ' . (int) $rawQuantity . ' for', productId: $productId);

        return ['success' => true];
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
        $name = \trim(($product['brand_name'] ?? '') . ' ' . $product['model_name']);

        return $product + [
            'name' => $name,
            'edit_url' => $this->getBasePath() . 'stock/edit/?id=' . (int) $product['id'],
            'stock_label' => $isTracked ? (string) (int) $product['quantity'] : 'Not tracked',
            'stock_sort' => $isTracked ? (int) $product['quantity'] : -1,
            'is_tracked' => $isTracked,
        ];
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
