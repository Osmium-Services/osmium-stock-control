SELECT p.id, p.model_name, p.reference, b.name AS brand_name, s.descr_short AS status_label,
    s.product_available, p.restock_at, l.quantity, l.reorder_level, l.on_order_qty, l.expected_at, l.note, l.updated_at AS stock_updated_at
FROM {PREFIX}products p
LEFT JOIN {PREFIX}productbrands b ON b.id = p.brand_id
LEFT JOIN {PREFIX}productstatus s ON s.id = p.status_id
LEFT JOIN {TABLE} l ON l.product_id = p.id
WHERE p.deleted_at IS NULL
ORDER BY b.name, p.model_name
