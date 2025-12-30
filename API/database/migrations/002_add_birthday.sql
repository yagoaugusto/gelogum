-- GELO - Adiciona campo de aniversário em users (idempotente)

USE gelo;

SET @gelo_has_birthday := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'birthday'
);

SET @gelo_sql := IF(
  @gelo_has_birthday = 0,
  'ALTER TABLE users ADD COLUMN birthday DATE NULL AFTER phone',
  'SELECT 1'
);

PREPARE stmt FROM @gelo_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

