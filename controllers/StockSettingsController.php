<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\StockControl\Models\StockControlConfig;
use Osmium\Services\StockControl\Models\StockLevels;

/**
 * Stock control settings - which product statuses mean in stock and out of stock.
 * A full-page form POST/redirect flow, like the other services' settings pages.
 *
 * Routes:
 *   - index() → /admin/settings/stock-control/  (GET shows the form, POST saves it)
 */
class StockSettingsController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/osmium-stock-control.json.php';

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $this->data['admin']['stockConfig'] = (array) StockControlConfig::get();
        $this->data['admin']['stockStatuses'] = (new StockLevels($this->osmium->dataSource))->listStatuses();
        $this->data['admin']['settingsSaved'] = $_SESSION['stock_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['stock_settings_error'] ?? false;
        unset($_SESSION['stock_settings_saved'], $_SESSION['stock_settings_error']);

        $this->setView('stock/settings.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->fail('Invalid form submission. Please try again.');

        $inStockId = (int) ($_POST['in_stock_status_id'] ?? 0);
        $outOfStockId = (int) ($_POST['out_of_stock_status_id'] ?? 0);

        $statuses = $this->statusesById();
        $problem = StockControlConfig::problem(
            inStock: $statuses[$inStockId] ?? null,
            outOfStock: $statuses[$outOfStockId] ?? null,
        );
        if ($problem !== null) $this->fail($problem);

        $this->saveConfig(
            inStockStatusId: $inStockId > 0 ? $inStockId : null,
            outOfStockStatusId: $outOfStockId > 0 ? $outOfStockId : null,
        );

        $this->admin->model->changelog->log(description: 'Updated Stock Control settings', recordType: 'settings');
        StockControlConfig::clearCache();

        $_SESSION['stock_settings_saved'] = true;
        $this->redirect('settings/stock-control/');
    }

    /** @return array<int, array<string, mixed>> */
    private function statusesById(): array
    {
        $byId = [];
        foreach ((new StockLevels($this->osmium->dataSource))->listStatuses() as $status) {
            $byId[(int) $status['id']] = $status;
        }

        return $byId;
    }

    private function saveConfig(?int $inStockStatusId, ?int $outOfStockStatusId): void
    {
        $configExists = \file_exists(self::CONFIG_FILE_PATH);
        $existing = $configExists ? (string) \file_get_contents(self::CONFIG_FILE_PATH) : null;
        if (!$configExists) $this->ensureConfigDirectoryExists();

        $content = StockControlConfig::toContent(
            existing: $existing,
            inStockStatusId: $inStockStatusId,
            outOfStockStatusId: $outOfStockStatusId,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, $content);
    }

    private function ensureConfigDirectoryExists(): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $alreadyExists = \is_dir($dir);
        if (!$alreadyExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);
    }

    private function fail(string $message): never
    {
        $_SESSION['stock_settings_error'] = $message;
        $this->redirect('settings/stock-control/');
    }
}
