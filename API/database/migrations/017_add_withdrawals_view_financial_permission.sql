-- Adiciona permissão para visualizar colunas financeiras na listagem de retiradas.
-- Permissão: withdrawals.view_financial

SET @gelo_admin_group_id := (SELECT id FROM permission_groups WHERE name = 'Administrador' LIMIT 1);

INSERT IGNORE INTO permission_group_permissions (group_id, permission_key)
VALUES (@gelo_admin_group_id, 'withdrawals.view_financial');
