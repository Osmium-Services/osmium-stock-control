SELECT id, product_id, order_id, order_item_id, change_qty, short_qty, reason, note, created_at
FROM {TABLE}
WHERE order_id = :order_id
ORDER BY id ASC
