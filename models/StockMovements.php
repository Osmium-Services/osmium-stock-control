<?php

declare(strict_types=1);

namespace Osmium\Services\StockControl\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The audit trail of every change to the shelf. A sale is recorded once per
 * order line and product, which is what makes paying the same order twice safe.
 */
class StockMovements extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'stock_movements');
    }

    public function exists(int $orderItemId, int $productId, string $reason): bool
    {
        $row = $this->database->prepare(
            sql: $this->loadSqlFile('stock-movement-exists.sql'),
            bindings: [':order_item_id' => $orderItemId, ':product_id' => $productId, ':reason' => $reason],
        )->single();

        return $row !== false;
    }

    public function record(int $productId, ?int $orderId, ?int $orderItemId, int $changeQty, int $shortQty, string $reason, ?string $note = null): void
    {
        $this->database->prepare(
            sql: $this->loadSqlFile('stock-movement-insert.sql'),
            bindings: [
                ':product_id' => $productId,
                ':order_id' => $orderId,
                ':order_item_id' => $orderItemId,
                ':change_qty' => $changeQty,
                ':short_qty' => $shortQty,
                ':reason' => $reason,
                ':note' => $note,
            ],
        )->execute();
    }

    /**
     * A product's history, newest first, capped at the latest 200 entries
     *
     * @return array<int, array<string, mixed>>
     */
    public function forProduct(int $productId): array
    {
        return $this->database->prepare(
            sql: $this->loadSqlFile('stock-movement-list-by-product.sql'),
            bindings: [':product_id' => $productId],
        )->resultset();
    }

    /** The order's status in core (paid, refunded, ...), or null when there is no such order */
    public function orderStatus(int $orderId): ?string
    {
        $row = $this->database->prepare(
            sql: $this->loadSqlFile('order-status-get.sql'),
            bindings: [':order_id' => $orderId],
        )->single();

        return $row === false ? null : (string) $row['status'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->database->prepare(
            sql: $this->loadSqlFile('stock-movement-list-by-order.sql'),
            bindings: [':order_id' => $orderId],
        )->resultset();
    }
}
