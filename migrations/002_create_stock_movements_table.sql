CREATE TABLE IF NOT EXISTS {PREFIX}stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    order_id INT DEFAULT NULL,
    order_item_id INT DEFAULT NULL,
    change_qty INT NOT NULL COMMENT 'Negative takes stock off the shelf, positive puts it back',
    short_qty INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Units an order wanted that the shelf could not cover',
    reason ENUM('sale', 'return', 'manual') NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_stock_movements_once (order_item_id, product_id, reason),
    KEY idx_stock_movements_product (product_id),
    KEY idx_stock_movements_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
