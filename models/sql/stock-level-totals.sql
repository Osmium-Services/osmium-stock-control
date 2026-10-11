SELECT COUNT(*) AS tracked, COALESCE(SUM(l.quantity <= 0), 0) AS out_of_stock
FROM {TABLE} l
JOIN {PREFIX}products p ON p.id = l.product_id
WHERE p.deleted_at IS NULL
