<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * How many of each product are on the shelf. A product with no row here is not
 * tracked: stock control leaves it alone.
 */
class StockLevels extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'stock_levels');
    }

    /** The quantity on the shelf, or null when the product is not tracked */
    public function get(int $productId): ?int
    {
        return $this->read('stock-level-get.sql', $productId);
    }

    /** Like get(), but locks the row until the transaction ends */
    public function getForUpdate(int $productId): ?int
    {
        return $this->read('stock-level-get-for-update.sql', $productId);
    }

    /** Start tracking a product, or change its figure */
    public function set(int $productId, int $quantity): void
    {
        $this->database->prepare(
            sql: $this->loadSqlFile('stock-level-set.sql'),
            bindings: [':product_id' => $productId, ':quantity' => $quantity],
        )->execute();
    }

    /** Change when a tracked product needs reordering (null clears it). Does nothing for an untracked product. */
    public function setReorderLevel(int $productId, ?int $level): void
    {
        $this->database->prepare(
            sql: $this->loadSqlFile('stock-level-set-reorder.sql'),
            bindings: [':product_id' => $productId, ':reorder_level' => $level],
        )->execute();
    }

    /** Stop tracking a product: stock control leaves it alone again */
    public function stopTracking(int $productId): void
    {
        $this->database->prepare(
            sql: $this->loadSqlFile('stock-level-delete.sql'),
            bindings: [':product_id' => $productId],
        )->execute();
    }

    /**
     * Every live product with its figure (quantity is null when it is not tracked), by brand and name
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(): array
    {
        return $this->database->prepare(sql: $this->loadSqlFile('stock-level-list-products.sql'))->resultset();
    }

    /** The product's current status id (core's products.status_id), or null if there is no such product */
    public function productStatus(int $productId): ?int
    {
        $row = $this->database->prepare(
            sql: $this->loadSqlFile('product-status-get.sql'),
            bindings: [':product_id' => $productId],
        )->single();

        return $row === false ? null : (int)$row['status_id'];
    }

    /**
     * Every product status, buyable ones first
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStatuses(): array
    {
        return $this->database->prepare(sql: $this->loadSqlFile('product-status-list.sql'))->resultset();
    }

    /** Whether core still has a product status with this id */
    public function statusExists(int $statusId): bool
    {
        $row = $this->database->prepare(
            sql: $this->loadSqlFile('product-status-exists.sql'),
            bindings: [':status_id' => $statusId],
        )->single();

        return $row !== false;
    }

    public function setProductStatus(int $productId, int $statusId): void
    {
        $this->database->prepare(
            sql: $this->loadSqlFile('product-status-set.sql'),
            bindings: [':product_id' => $productId, ':status_id' => $statusId],
        )->execute();
    }

    private function read(string $sqlFile, int $productId): ?int
    {
        $row = $this->database->prepare(
            sql: $this->loadSqlFile($sqlFile),
            bindings: [':product_id' => $productId],
        )->single();

        return $row === false ? null : (int)$row['quantity'];
    }
}
