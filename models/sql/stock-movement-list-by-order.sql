SELECT m.id, m.product_id, m.order_id, m.order_item_id, m.change_qty, m.short_qty, m.reason, m.note, m.created_at,
    TRIM(CONCAT(COALESCE(b.name, ''), ' ', COALESCE(p.model_name, ''))) AS product_name
FROM {TABLE} m
LEFT JOIN {PREFIX}products p ON p.id = m.product_id
LEFT JOIN {PREFIX}productbrands b ON b.id = p.brand_id
WHERE m.order_id = :order_id
ORDER BY m.id ASC
