SELECT id, product_id, order_id, change_qty, short_qty, reason, note, created_at
FROM {TABLE}
WHERE product_id = :product_id
ORDER BY id DESC
LIMIT 200
