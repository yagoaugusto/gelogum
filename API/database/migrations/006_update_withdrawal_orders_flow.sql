-- GELO - Evolui fluxo de retirada (status, separação, cancelamento, pagamento)

USE gelo;

-- status enum: requested, separated, delivered, cancelled
SET @gelo_status_type := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND COLUMN_NAME = 'status'
  LIMIT 1
);

SET @gelo_needs_status_update := (
  @gelo_status_type IS NOT NULL
  AND (
    LOCATE('separated', @gelo_status_type) = 0
    OR LOCATE('cancelled', @gelo_status_type) = 0
  )
);

SET @gelo_sql := IF(
  @gelo_needs_status_update,
  "ALTER TABLE withdrawal_orders MODIFY status ENUM('requested','separated','delivered','cancelled') NOT NULL DEFAULT 'requested'",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add columns (idempotent)
SET @gelo_cols := (
  SELECT GROUP_CONCAT(COLUMN_NAME)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND COLUMN_NAME IN ('separated_at','separated_by_user_id','delivered_by_user_id','cancelled_at','cancelled_by_user_id','cancellation_reason','paid_at')
);

SET @gelo_has_separated_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'separated_at'
);
SET @gelo_sql := IF(@gelo_has_separated_at = 0, "ALTER TABLE withdrawal_orders ADD COLUMN separated_at DATETIME NULL AFTER total_amount", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_separated_by := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'separated_by_user_id'
);
SET @gelo_sql := IF(@gelo_has_separated_by = 0, "ALTER TABLE withdrawal_orders ADD COLUMN separated_by_user_id BIGINT UNSIGNED NULL AFTER separated_at", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_delivered_by := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'delivered_by_user_id'
);
SET @gelo_sql := IF(@gelo_has_delivered_by = 0, "ALTER TABLE withdrawal_orders ADD COLUMN delivered_by_user_id BIGINT UNSIGNED NULL AFTER delivered_at", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_cancelled_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'cancelled_at'
);
SET @gelo_sql := IF(@gelo_has_cancelled_at = 0, "ALTER TABLE withdrawal_orders ADD COLUMN cancelled_at DATETIME NULL AFTER delivered_by_user_id", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_cancelled_by := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'cancelled_by_user_id'
);
SET @gelo_sql := IF(@gelo_has_cancelled_by = 0, "ALTER TABLE withdrawal_orders ADD COLUMN cancelled_by_user_id BIGINT UNSIGNED NULL AFTER cancelled_at", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_cancel_reason := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'cancellation_reason'
);
SET @gelo_sql := IF(@gelo_has_cancel_reason = 0, "ALTER TABLE withdrawal_orders ADD COLUMN cancellation_reason TEXT NULL AFTER cancelled_by_user_id", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_paid_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_orders' AND COLUMN_NAME = 'paid_at'
);
SET @gelo_sql := IF(@gelo_has_paid_at = 0, "ALTER TABLE withdrawal_orders ADD COLUMN paid_at DATETIME NULL AFTER cancellation_reason", "SELECT 1");
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Foreign keys (idempotent by name)
SET @gelo_fk_sep := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND CONSTRAINT_NAME = 'fk_withdrawal_orders_separated_by'
);
SET @gelo_sql := IF(
  @gelo_fk_sep = 0,
  "ALTER TABLE withdrawal_orders ADD CONSTRAINT fk_withdrawal_orders_separated_by FOREIGN KEY (separated_by_user_id) REFERENCES users(id)",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_fk_del := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND CONSTRAINT_NAME = 'fk_withdrawal_orders_delivered_by'
);
SET @gelo_sql := IF(
  @gelo_fk_del = 0,
  "ALTER TABLE withdrawal_orders ADD CONSTRAINT fk_withdrawal_orders_delivered_by FOREIGN KEY (delivered_by_user_id) REFERENCES users(id)",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_fk_can := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND CONSTRAINT_NAME = 'fk_withdrawal_orders_cancelled_by'
);
SET @gelo_sql := IF(
  @gelo_fk_can = 0,
  "ALTER TABLE withdrawal_orders ADD CONSTRAINT fk_withdrawal_orders_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id)",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

