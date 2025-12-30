-- GELO - Permissão de visualização de pedidos próprios e grupo padrão para operação de retiradas

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

-- Adiciona a permissão "withdrawals.view_own" aos grupos padrão (idempotente)
SET @gelo_admin_group_id := (SELECT id FROM permission_groups WHERE name = 'Administrador' LIMIT 1);
SET @gelo_user_group_id := (SELECT id FROM permission_groups WHERE name = 'Usuário' LIMIT 1);

INSERT IGNORE INTO permission_group_permissions (group_id, permission_key) VALUES
  (@gelo_admin_group_id, 'withdrawals.view_own'),
  (@gelo_user_group_id, 'withdrawals.view_own');

-- Grupo padrão para quem opera retiradas de todos os clientes (sem acesso a cadastros/usuários)
INSERT INTO permission_groups (name, description, is_active)
VALUES ('Retiradas - Operação', 'Gerencia retiradas de todos os clientes (sem acesso a cadastros/usuários).', 1)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  is_active = VALUES(is_active);

SET @gelo_withdrawals_ops_group_id := (SELECT id FROM permission_groups WHERE name = 'Retiradas - Operação' LIMIT 1);

INSERT IGNORE INTO permission_group_permissions (group_id, permission_key) VALUES
  (@gelo_withdrawals_ops_group_id, 'withdrawals.access'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.view_own'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.view_all'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.create_for_client'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.cancel'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.separate'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.deliver'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.return'),
  (@gelo_withdrawals_ops_group_id, 'withdrawals.pay');
