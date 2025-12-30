<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_auth();
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/password.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/password.php');
}

$current = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
$new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
$confirm = isset($_POST['new_password_confirm']) ? (string) $_POST['new_password_confirm'] : '';

if ($current === '' || $new === '' || $confirm === '') {
    gelo_flash_set('error', 'Preencha todos os campos.');
    gelo_redirect(GELO_BASE_URL . '/password.php');
}

if (strlen($new) < 8) {
    gelo_flash_set('error', 'A nova senha deve ter pelo menos 8 caracteres.');
    gelo_redirect(GELO_BASE_URL . '/password.php');
}

if ($new !== $confirm) {
    gelo_flash_set('error', 'A confirmação de senha não confere.');
    gelo_redirect(GELO_BASE_URL . '/password.php');
}

$sessionUser = gelo_current_user();
$userId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;
if ($userId <= 0) {
    gelo_flash_set('error', 'Sessão inválida. Faça login novamente.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('SELECT password_hash, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row) || empty($row['password_hash'])) {
        gelo_flash_set('error', 'Usuário não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/login.php');
    }

    if ((int) ($row['is_active'] ?? 0) !== 1) {
        gelo_flash_set('error', 'Usuário inativo.');
        gelo_redirect(GELO_BASE_URL . '/login.php');
    }

    if (!password_verify($current, (string) $row['password_hash'])) {
        gelo_flash_set('error', 'Senha atual incorreta.');
        gelo_redirect(GELO_BASE_URL . '/password.php');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => $hash, 'id' => $userId]);

    session_regenerate_id(true);
    gelo_flash_set('success', 'Senha atualizada com sucesso.');
    gelo_redirect(GELO_BASE_URL . '/dashboard.php');
} catch (Throwable $e) {
    gelo_flash_set('error', 'Erro ao atualizar senha. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/password.php');
}

