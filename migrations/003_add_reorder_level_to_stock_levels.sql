ALTER TABLE {PREFIX}stock_levels
    ADD COLUMN reorder_level INT UNSIGNED DEFAULT NULL COMMENT 'Reorder when the quantity is at or below this, null means no alert' AFTER quantity;
