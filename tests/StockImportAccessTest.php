<?php

declare(strict_types=1);

namespace Tests\Services\StockControl;

use Osmium\Core\Library\Header;
use Osmium\Core\Library\Url;
use Osmium\Core\Osmium;
use Osmium\Modules\Admin\Services\AdminAuth;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Services\StockControl\Controllers\StockController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../app/core/Osmium.php';
require_once __DIR__ . '/../../../../app/modules/admin/core/AdminController.php';
require_once __DIR__ . '/../controllers/StockController.php';

/**
 * Importing a stock sheet overwrites figures in bulk, so it follows the same
 * rule as the core product price import: administrators only. An editor who
 * opens or posts to the page is sent back to the stock list.
 */
class StockImportAccessTest extends TestCase
{
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_FILES = [];
    }

    /** @return array{redirect: ?string, data: array} */
    private function openImportPage(bool $isAdmin): array
    {
        $osmium = new class extends Osmium {
            public function __construct()
            {
                $this->url = new class extends Url {
                    public function __construct() {}

                    public function to(string $path): string
                    {
                        return $path;
                    }
                };
                $this->header = new class extends Header {
                    public function __construct() {}

                    public function redirect(string $targetURL, int $statusCode = 302): void
                    {
                        throw new \RuntimeException($targetURL);
                    }
                };
            }
        };

        $auth = new class ($isAdmin) extends AdminAuth {
            public function __construct(private bool $admin) {}

            public function isAdmin(): bool
            {
                return $this->admin;
            }
        };
        $admin = new class ($auth) extends OsmiumAdmin {
            public function __construct(AdminAuth $auth)
            {
                $this->auth = $auth;
            }
        };

        $data = ['admin' => ['basePath' => '/admin/']];
        $redirect = null;
        try {
            (new StockController($osmium, $admin, $data))->import();
        } catch (\RuntimeException $e) {
            $redirect = $e->getMessage();
        }

        return ['redirect' => $redirect, 'data' => $data];
    }

    public function test_an_editor_is_sent_back_to_the_stock_list(): void
    {
        $result = $this->openImportPage(isAdmin: false);

        $this->assertSame('/admin/stock/', $result['redirect']);
        $this->assertArrayNotHasKey('importResults', $result['data']['admin']);
    }

    public function test_an_editor_posting_a_file_is_refused_before_anything_is_read(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_FILES['csv_file'] = ['name' => 'stock.csv', 'tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_OK, 'size' => 10];

        $result = $this->openImportPage(isAdmin: false);

        $this->assertSame('/admin/stock/', $result['redirect']);
        $this->assertArrayNotHasKey('importResults', $result['data']['admin']);
    }

    public function test_an_admin_gets_the_import_page(): void
    {
        $result = $this->openImportPage(isAdmin: true);

        $this->assertNull($result['redirect']);
        $this->assertNull($result['data']['admin']['importResults']);
        $this->assertSame('app/modules/admin/views/stock/import.phtml', $result['data']['adminContent']);
    }
}
