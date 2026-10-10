ALTER TABLE {PREFIX}stock_levels
    ADD COLUMN on_order_qty INT UNSIGNED DEFAULT NULL COMMENT 'Units ordered from the supplier and not yet received' AFTER reorder_level,
    ADD COLUMN expected_at DATE DEFAULT NULL COMMENT 'When the on-order units are expected, null when unknown' AFTER on_order_qty;
