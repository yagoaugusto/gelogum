-- GELO - Permissão para gerenciar preços de produtos por usuário

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

-- Garante que o grupo Administrador receba a permissão products.user_prices
SET @gelo_admin_group_id := (SELECT id FROM permission_groups WHERE name = 'Administrador' LIMIT 1);
INSERT IGNORE INTO permission_group_permissions (group_id, permission_key)
VALUES (@gelo_admin_group_id, 'products.user_prices');
