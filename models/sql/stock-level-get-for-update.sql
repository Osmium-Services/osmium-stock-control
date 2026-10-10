-- Locks the row until the surrounding transaction ends, so two orders paid at once cannot both take the last unit
SELECT quantity FROM {TABLE} WHERE product_id = :product_id FOR UPDATE
