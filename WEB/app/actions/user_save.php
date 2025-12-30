<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('users.access');
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/users.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/users.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isEdit = $id > 0;
$redirectTo = GELO_BASE_URL . '/user.php' . ($isEdit ? ('?id=' . $id) : '');

$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$phoneRaw = isset($_POST['phone']) ? (string) $_POST['phone'] : '';
$phone = gelo_phone_normalize_e164($phoneRaw, '55') ?? '';
$phoneDigits = gelo_digits($phoneRaw);
$birthday = isset($_POST['birthday']) ? trim((string) $_POST['birthday']) : '';
$isActive = isset($_POST['is_active']) ? 1 : 0;
$permissionGroupIdInput = isset($_POST['permission_group_id']) ? (int) $_POST['permission_group_id'] : 0;

$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
$passwordConfirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';
$action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';
$action = in_array($action, ['save', 'save_new'], true) ? $action : 'save';

$canManageGroups = gelo_has_permission('users.groups');

$old = json_encode([
    'name' => $name,
    'phone' => $phoneRaw,
    'birthday' => $birthday,
    'is_active' => $isActive,
    'permission_group_id' => $permissionGroupIdInput,
]);

function gelo_user_save_error(string $message, string $redirectTo, ?string $old): void
{
    gelo_flash_set('error', $message);
    if (is_string($old) && $old !== '') {
        gelo_flash_set('old_user', $old);
    }
    gelo_redirect($redirectTo);
}

$nameLen = function_exists('mb_strlen') ? (int) mb_strlen($name, 'UTF-8') : strlen($name);
if ($name === '' || $nameLen < 2) {
    gelo_user_save_error('Informe um nome válido.', $redirectTo, $old);
}

if ($phone === '') {
    gelo_user_save_error('Informe um telefone válido. Para números internacionais, use o formato +DDI (ex: +1..., +351...).', $redirectTo, $old);
}

if ($birthday !== '') {
    $normalized = null;
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $birthday);
        if (!$dt) {
            continue;
        }

        $errors = DateTime::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        $normalized = $dt->format('Y-m-d');
        break;
    }

    if ($normalized === null) {
        gelo_user_save_error('Informe uma data de aniversário válida.', $redirectTo, $old);
    }

    $birthday = $normalized;
}

if (!$isEdit) {
    if ($password === '' || strlen($password) < 8) {
        gelo_user_save_error('Defina uma senha com pelo menos 8 caracteres.', $redirectTo, $old);
    }
    if ($password !== $passwordConfirm) {
        gelo_user_save_error('A confirmação de senha não confere.', $redirectTo, $old);
    }
} elseif ($password !== '' || $passwordConfirm !== '') {
    if (strlen($password) < 8) {
        gelo_user_save_error('A nova senha deve ter pelo menos 8 caracteres.', $redirectTo, $old);
    }
    if ($password !== $passwordConfirm) {
        gelo_user_save_error('A confirmação de senha não confere.', $redirectTo, $old);
    }
}

$currentUser = gelo_current_user();
$currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : 0;
if ($isEdit && $currentUserId > 0 && $currentUserId === $id && $isActive !== 1) {
    gelo_user_save_error('Você não pode desativar seu próprio usuário.', $redirectTo, $old);
}

try {
    $pdo = gelo_pdo();
    $defaultUserGroupId = 0;

    try {
        $stmt = $pdo->prepare('SELECT id FROM permission_groups WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => 'Usuário']);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $defaultUserGroupId = (int) ($row['id'] ?? 0);
        }
    } catch (Throwable $ignored) {
        $defaultUserGroupId = 0;
    }

    if ($isEdit) {
        $existsStmt = $pdo->prepare('SELECT id, permission_group_id FROM users WHERE id = :id LIMIT 1');
        $existsStmt->execute(['id' => $id]);
        $exists = $existsStmt->fetch();
        if (!is_array($exists)) {
            gelo_user_save_error('Usuário não encontrado.', GELO_BASE_URL . '/users.php', null);
        }
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE (phone = :p1 OR phone = :p2) AND id <> :id LIMIT 1');
    $check->execute(['p1' => $phone, 'p2' => $phoneDigits, 'id' => $id]);
    $exists = $check->fetch();
    if (is_array($exists)) {
        gelo_user_save_error('Este telefone já está cadastrado em outro usuário.', $redirectTo, $old);
    }

    $birthdayValue = $birthday !== '' ? $birthday : null;
    $permissionGroupId = null;
    if ($canManageGroups) {
        if ($permissionGroupIdInput > 0) {
            $permissionGroupId = $permissionGroupIdInput;
        } elseif (!$isEdit && $defaultUserGroupId > 0) {
            $permissionGroupId = $defaultUserGroupId;
        }
    } elseif (!$isEdit && $defaultUserGroupId > 0) {
        $permissionGroupId = $defaultUserGroupId;
    }

    if ($permissionGroupId !== null && $permissionGroupId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT id, is_active FROM permission_groups WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $permissionGroupId]);
            $grp = $stmt->fetch();
            if (!is_array($grp) || (int) ($grp['is_active'] ?? 0) !== 1) {
                gelo_user_save_error('Selecione um grupo de permissões válido e ativo.', $redirectTo, $old);
            }
        } catch (Throwable $e) {
            gelo_user_save_error('Não foi possível validar o grupo de permissões. Verifique o banco e as migrações.', $redirectTo, $old);
        }
    }

    if (!$isEdit) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('
            INSERT INTO users (name, phone, birthday, password_hash, permission_group_id, is_active)
            VALUES (:name, :phone, :birthday, :hash, :permission_group_id, :is_active)
        ');
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'birthday' => $birthdayValue,
            'hash' => $hash,
            'permission_group_id' => $permissionGroupId,
            'is_active' => $isActive,
        ]);

        $newId = (int) $pdo->lastInsertId();
        gelo_flash_set('success', 'Usuário criado.');
        if ($action === 'save_new') {
            gelo_redirect(GELO_BASE_URL . '/user.php');
        }
        gelo_redirect(GELO_BASE_URL . '/user.php?id=' . $newId);
    }

    $sql = 'UPDATE users SET name = :name, phone = :phone, birthday = :birthday, is_active = :is_active';
    $params = [
        'name' => $name,
        'phone' => $phone,
        'birthday' => $birthdayValue,
        'is_active' => $isActive,
        'id' => $id,
    ];
    if ($canManageGroups && $permissionGroupId !== null) {
        $sql .= ', permission_group_id = :permission_group_id';
        $params['permission_group_id'] = $permissionGroupId;
    }
    $sql .= ' WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => $hash, 'id' => $id]);
    }

    gelo_flash_set('success', 'Usuário atualizado.');
    if ($action === 'save_new') {
        gelo_redirect(GELO_BASE_URL . '/user.php');
    }
    gelo_redirect($redirectTo);
} catch (Throwable $e) {
    gelo_user_save_error('Erro ao salvar usuário. Tente novamente.', $redirectTo, $old);
}
