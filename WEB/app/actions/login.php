<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

$phoneRaw = isset($_POST['phone']) ? (string) $_POST['phone'] : '';
$phoneDigits = preg_replace('/\\D+/', '', $phoneRaw);
$phoneDigits = is_string($phoneDigits) ? $phoneDigits : '';
$phoneNormalized = function_exists('gelo_phone_normalize_e164') ? (gelo_phone_normalize_e164($phoneRaw, '55') ?? '') : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';

gelo_flash_set('old_phone', trim($phoneRaw));

if (trim($phoneRaw) === '' || $password === '') {
    gelo_flash_set('error', 'Informe telefone e senha.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('
        SELECT id, name, phone, password_hash, role, is_active
        FROM users
        WHERE phone = :p1 OR phone = :p2
        LIMIT 1
    ');
    $stmt->execute([
        'p1' => ($phoneNormalized !== '' ? $phoneNormalized : $phoneDigits),
        'p2' => $phoneDigits,
    ]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    gelo_flash_set('error', 'Erro ao conectar no banco. Verifique a migração e credenciais.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

if (!is_array($user) || empty($user['password_hash']) || (int) ($user['is_active'] ?? 0) !== 1) {
    gelo_flash_set('error', 'Telefone ou senha inválidos.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

if (!password_verify($password, (string) $user['password_hash'])) {
    gelo_flash_set('error', 'Telefone ou senha inválidos.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

session_regenerate_id(true);
unset($_SESSION['_flash']['old_phone']);

$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'name' => (string) $user['name'],
    'phone' => (string) $user['phone'],
    'role' => (string) $user['role'],
];

try {
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => (int) $user['id']]);
} catch (Throwable $e) {
    // ignore
}

gelo_redirect(GELO_BASE_URL . '/dashboard.php');

