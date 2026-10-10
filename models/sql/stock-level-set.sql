INSERT INTO {TABLE} (product_id, quantity) VALUES (:product_id, :quantity)
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
