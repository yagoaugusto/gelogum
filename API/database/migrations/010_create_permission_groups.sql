-- GELO - Grupos de permissões e vínculo com usuários

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS permission_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_permission_groups_name (name),
  KEY idx_permission_groups_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_group_permissions (
  group_id BIGINT UNSIGNED NOT NULL,
  permission_key VARCHAR(64) NOT NULL,
  PRIMARY KEY (group_id, permission_key),
  KEY idx_permission_group_permissions_key (permission_key),
  CONSTRAINT fk_permission_group_permissions_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users.permission_group_id (idempotente)
SET @gelo_has_group_col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'permission_group_id'
);
SET @gelo_sql := IF(
  @gelo_has_group_col = 0,
  "ALTER TABLE users ADD COLUMN permission_group_id BIGINT UNSIGNED NULL AFTER role",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_group_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_permission_group'
);
SET @gelo_sql := IF(
  @gelo_has_group_idx = 0,
  "ALTER TABLE users ADD KEY idx_users_permission_group (permission_group_id)",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_group_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_users_permission_group'
);
SET @gelo_sql := IF(
  @gelo_has_group_fk = 0,
  "ALTER TABLE users ADD CONSTRAINT fk_users_permission_group FOREIGN KEY (permission_group_id) REFERENCES permission_groups(id)",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Grupos padrão
INSERT INTO permission_groups (name, description, is_active)
VALUES
  ('Administrador', 'Acesso administrativo ao sistema', 1),
  ('Usuário', 'Acesso básico para pedidos próprios', 1)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  is_active = VALUES(is_active);

SET @gelo_admin_group_id := (SELECT id FROM permission_groups WHERE name = 'Administrador' LIMIT 1);
SET @gelo_user_group_id := (SELECT id FROM permission_groups WHERE name = 'Usuário' LIMIT 1);

-- Permissões padrão (Administrador: tudo)
INSERT IGNORE INTO permission_group_permissions (group_id, permission_key) VALUES
  (@gelo_admin_group_id, 'withdrawals.access'),
  (@gelo_admin_group_id, 'withdrawals.view_all'),
  (@gelo_admin_group_id, 'withdrawals.create_for_client'),
  (@gelo_admin_group_id, 'withdrawals.cancel'),
  (@gelo_admin_group_id, 'withdrawals.separate'),
  (@gelo_admin_group_id, 'withdrawals.deliver'),
  (@gelo_admin_group_id, 'withdrawals.return'),
  (@gelo_admin_group_id, 'withdrawals.pay'),
  (@gelo_admin_group_id, 'products.access'),
  (@gelo_admin_group_id, 'deposits.access'),
  (@gelo_admin_group_id, 'users.access'),
  (@gelo_admin_group_id, 'users.groups');

-- Permissões padrão (Usuário: retiradas próprias)
INSERT IGNORE INTO permission_group_permissions (group_id, permission_key) VALUES
  (@gelo_user_group_id, 'withdrawals.access'),
  (@gelo_user_group_id, 'withdrawals.cancel');

-- Vínculo inicial (sem sobrescrever se já existir)
UPDATE users
SET permission_group_id = @gelo_admin_group_id
WHERE permission_group_id IS NULL AND role IN ('master','admin');

UPDATE users
SET permission_group_id = @gelo_user_group_id
WHERE permission_group_id IS NULL AND role = 'user';

