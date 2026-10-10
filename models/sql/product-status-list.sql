SELECT id, descr_short, descr_long, product_available, restocking
FROM {PREFIX}productstatus
ORDER BY product_available DESC, id
