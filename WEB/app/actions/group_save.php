<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['users.access', 'users.groups']);
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/groups.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/groups.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isEdit = $id > 0;

$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';
$isActive = isset($_POST['is_active']) ? 1 : 0;
$action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';
$action = in_array($action, ['save', 'save_new'], true) ? $action : 'save';

$permissionsInput = $_POST['permissions'] ?? [];
if (!is_array($permissionsInput)) {
    $permissionsInput = [];
}

$allowed = gelo_permissions_all_keys();
$selected = [];
foreach ($permissionsInput as $key) {
    if (!is_string($key)) {
        continue;
    }
    $key = trim($key);
    if ($key === '') {
        continue;
    }
    if (!in_array($key, $allowed, true)) {
        continue;
    }
    $selected[] = $key;
}
$selected = array_values(array_unique($selected));

$redirectTo = GELO_BASE_URL . '/group.php' . ($isEdit ? ('?id=' . $id) : '');

$old = json_encode([
    'name' => $name,
    'description' => $description,
    'is_active' => $isActive,
    'permissions' => $selected,
]);

function gelo_group_save_error(string $message, string $redirectTo, ?string $old): void
{
    gelo_flash_set('error', $message);
    if (is_string($old) && $old !== '') {
        gelo_flash_set('old_group', $old);
    }
    gelo_redirect($redirectTo);
}

$nameLen = function_exists('mb_strlen') ? (int) mb_strlen($name, 'UTF-8') : strlen($name);
if ($name === '' || $nameLen < 2) {
    gelo_group_save_error('Informe um nome válido para o grupo.', $redirectTo, $old);
}
if ($nameLen > 80) {
    gelo_group_save_error('O nome do grupo deve ter no máximo 80 caracteres.', $redirectTo, $old);
}

if ($description !== '') {
    $descLen = function_exists('mb_strlen') ? (int) mb_strlen($description, 'UTF-8') : strlen($description);
    if ($descLen > 255) {
        gelo_group_save_error('A descrição deve ter no máximo 255 caracteres.', $redirectTo, $old);
    }
}

try {
    $pdo = gelo_pdo();

    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT id FROM permission_groups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $exists = $stmt->fetch();
        if (!is_array($exists)) {
            gelo_group_save_error('Grupo não encontrado.', GELO_BASE_URL . '/groups.php', null);
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM permission_groups WHERE name = :name AND id <> :id LIMIT 1');
    $stmt->execute(['name' => $name, 'id' => $id]);
    $dup = $stmt->fetch();
    if (is_array($dup)) {
        gelo_group_save_error('Já existe um grupo com este nome.', $redirectTo, $old);
    }

    if ($isEdit && $isActive !== 1) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE permission_group_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        $count = is_array($row) ? (int) ($row['c'] ?? 0) : 0;
        if ($count > 0) {
            gelo_group_save_error('Este grupo está vinculado a usuários. Reatribua os usuários antes de inativar o grupo.', $redirectTo, $old);
        }
    }

    $pdo->beginTransaction();

    $groupId = $id;
    if (!$isEdit) {
        $stmt = $pdo->prepare('INSERT INTO permission_groups (name, description, is_active) VALUES (:name, :description, :is_active)');
        $stmt->execute([
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'is_active' => $isActive,
        ]);
        $groupId = (int) $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare('UPDATE permission_groups SET name = :name, description = :description, is_active = :is_active WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'is_active' => $isActive,
            'id' => $groupId,
        ]);
    }

    $pdo->prepare('DELETE FROM permission_group_permissions WHERE group_id = :id')->execute(['id' => $groupId]);
    if (!empty($selected)) {
        $stmt = $pdo->prepare('INSERT INTO permission_group_permissions (group_id, permission_key) VALUES (:group_id, :permission_key)');
        foreach ($selected as $key) {
            $stmt->execute(['group_id' => $groupId, 'permission_key' => $key]);
        }
    }

    $pdo->commit();

    gelo_flash_set('success', $isEdit ? 'Grupo atualizado.' : 'Grupo criado.');
    if ($action === 'save_new') {
        gelo_redirect(GELO_BASE_URL . '/group.php');
    }
    gelo_redirect(GELO_BASE_URL . '/group.php?id=' . $groupId);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
        // ignore
    }
    gelo_group_save_error('Erro ao salvar grupo. Tente novamente.', $redirectTo, $old);
}

