-- Add per-entity idempotency keys for offline sync retry safety.

SET @db_name = DATABASE();

-- measurements.idempotency_key
SET @measurements_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'measurements'
      AND COLUMN_NAME = 'idempotency_key'
);
SET @sql = IF(
    @measurements_col_exists = 0,
    'ALTER TABLE measurements ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @measurements_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'measurements'
      AND INDEX_NAME = 'uniq_measurements_idempotency_key'
);
SET @sql = IF(
    @measurements_idx_exists = 0,
    'ALTER TABLE measurements ADD UNIQUE KEY uniq_measurements_idempotency_key (idempotency_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- consumables_used.idempotency_key
SET @consumables_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'consumables_used'
      AND COLUMN_NAME = 'idempotency_key'
);
SET @sql = IF(
    @consumables_col_exists = 0,
    'ALTER TABLE consumables_used ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER is_billable',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @consumables_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'consumables_used'
      AND INDEX_NAME = 'uniq_consumables_idempotency_key'
);
SET @sql = IF(
    @consumables_idx_exists = 0,
    'ALTER TABLE consumables_used ADD UNIQUE KEY uniq_consumables_idempotency_key (idempotency_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- media_items.idempotency_key
SET @media_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'media_items'
      AND COLUMN_NAME = 'idempotency_key'
);
SET @sql = IF(
    @media_col_exists = 0,
    'ALTER TABLE media_items ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER is_uploaded',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @media_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'media_items'
      AND INDEX_NAME = 'uniq_media_items_idempotency_key'
);
SET @sql = IF(
    @media_idx_exists = 0,
    'ALTER TABLE media_items ADD UNIQUE KEY uniq_media_items_idempotency_key (idempotency_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
