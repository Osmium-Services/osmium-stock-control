ALTER TABLE {PREFIX}stock_levels
    ADD COLUMN note VARCHAR(255) DEFAULT NULL COMMENT 'A lasting note about the product, shown whenever its stock is edited' AFTER expected_at;
