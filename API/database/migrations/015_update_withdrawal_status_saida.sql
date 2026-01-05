-- GELO - Remove status SEPARADO e renomeia ENTREGUE para SAIDA

USE gelo;

-- Passo 1: garantir que o ENUM aceite 'saida' antes de converter dados.
-- (Caso contrário, o UPDATE para 'saida' gera erro 1265 - Data truncated.)
SET @gelo_status_type := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND COLUMN_NAME = 'status'
  LIMIT 1
);

SET @gelo_needs_add_saida := (
  @gelo_status_type IS NOT NULL
  AND LOCATE('saida', @gelo_status_type) = 0
);

SET @gelo_sql := IF(
  @gelo_needs_add_saida,
  "ALTER TABLE withdrawal_orders MODIFY status ENUM('requested','separated','delivered','saida','cancelled') NOT NULL DEFAULT 'requested'",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Passo 2: converter dados existentes (agora com 'saida' permitido)
UPDATE withdrawal_orders SET status = 'saida' WHERE status = 'delivered';
UPDATE withdrawal_orders SET status = 'requested' WHERE status = 'separated';

-- Passo 3: reduzir ENUM para o estado final (requested, saida, cancelled)
SET @gelo_status_type := (
  SELECT COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_orders'
    AND COLUMN_NAME = 'status'
  LIMIT 1
);

-- status enum final: requested, saida, cancelled

SET @gelo_needs_status_update := (
  @gelo_status_type IS NOT NULL
  AND (
    LOCATE('saida', @gelo_status_type) = 0
    OR LOCATE('delivered', @gelo_status_type) > 0
    OR LOCATE('separated', @gelo_status_type) > 0
  )
);

SET @gelo_sql := IF(
  @gelo_needs_status_update,
  "ALTER TABLE withdrawal_orders MODIFY status ENUM('requested','saida','cancelled') NOT NULL DEFAULT 'requested'",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
