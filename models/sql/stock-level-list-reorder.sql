SELECT p.id, TRIM(CONCAT(COALESCE(b.name, ''), ' ', p.model_name)) AS name, l.quantity, l.reorder_level, l.on_order_qty
FROM {TABLE} l
JOIN {PREFIX}products p ON p.id = l.product_id
LEFT JOIN {PREFIX}productbrands b ON b.id = p.brand_id
WHERE l.reorder_level IS NOT NULL AND p.deleted_at IS NULL
