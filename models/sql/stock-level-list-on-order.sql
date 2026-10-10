SELECT p.id, TRIM(CONCAT(COALESCE(b.name, ''), ' ', p.model_name)) AS name, l.on_order_qty, l.expected_at
FROM {TABLE} l
JOIN {PREFIX}products p ON p.id = l.product_id
LEFT JOIN {PREFIX}productbrands b ON b.id = p.brand_id
WHERE l.on_order_qty IS NOT NULL AND l.on_order_qty > 0 AND p.deleted_at IS NULL
