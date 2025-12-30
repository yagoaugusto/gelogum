<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('deposits.access');
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/depots.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/depots.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isEdit = $id > 0;
$redirectTo = GELO_BASE_URL . '/depot.php' . ($isEdit ? ('?id=' . $id) : '');

$title = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
$phone = isset($_POST['phone']) ? gelo_digits((string) $_POST['phone']) : '';
$address = isset($_POST['address']) ? trim((string) $_POST['address']) : '';
$isActive = isset($_POST['is_active']) ? 1 : 0;
$action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';
$action = in_array($action, ['save', 'save_new'], true) ? $action : 'save';

$old = json_encode([
    'title' => $title,
    'phone' => $phone,
    'address' => $address,
    'is_active' => $isActive,
]);

function gelo_depot_save_error(string $message, string $redirectTo, ?string $old): void
{
    gelo_flash_set('error', $message);
    if (is_string($old) && $old !== '') {
        gelo_flash_set('old_depot', $old);
    }
    gelo_redirect($redirectTo);
}

$titleLen = function_exists('mb_strlen') ? (int) mb_strlen($title, 'UTF-8') : strlen($title);
if ($title === '' || $titleLen < 2) {
    gelo_depot_save_error('Informe um título válido.', $redirectTo, $old);
}

if ($phone === '' || !in_array(strlen($phone), [10, 11], true)) {
    gelo_depot_save_error('Informe um telefone válido.', $redirectTo, $old);
}

$addressLen = function_exists('mb_strlen') ? (int) mb_strlen($address, 'UTF-8') : strlen($address);
if ($address === '' || $addressLen < 8) {
    gelo_depot_save_error('Informe um endereço válido.', $redirectTo, $old);
}

try {
    $pdo = gelo_pdo();

    if ($isEdit) {
        $existsStmt = $pdo->prepare('SELECT id FROM deposits WHERE id = :id LIMIT 1');
        $existsStmt->execute(['id' => $id]);
        $exists = $existsStmt->fetch();
        if (!is_array($exists)) {
            gelo_depot_save_error('Depósito não encontrado.', GELO_BASE_URL . '/depots.php', null);
        }
    }

    if (!$isEdit) {
        $stmt = $pdo->prepare('INSERT INTO deposits (title, phone, address, is_active) VALUES (:title, :phone, :address, :is_active)');
        $stmt->execute([
            'title' => $title,
            'phone' => $phone,
            'address' => $address,
            'is_active' => $isActive,
        ]);

        $newId = (int) $pdo->lastInsertId();
        gelo_flash_set('success', 'Depósito criado.');
        if ($action === 'save_new') {
            gelo_redirect(GELO_BASE_URL . '/depot.php');
        }
        gelo_redirect(GELO_BASE_URL . '/depot.php?id=' . $newId);
    }

    $stmt = $pdo->prepare('UPDATE deposits SET title = :title, phone = :phone, address = :address, is_active = :is_active WHERE id = :id');
    $stmt->execute([
        'title' => $title,
        'phone' => $phone,
        'address' => $address,
        'is_active' => $isActive,
        'id' => $id,
    ]);

    gelo_flash_set('success', 'Depósito atualizado.');
    if ($action === 'save_new') {
        gelo_redirect(GELO_BASE_URL . '/depot.php');
    }
    gelo_redirect($redirectTo);
} catch (Throwable $e) {
    gelo_depot_save_error('Erro ao salvar depósito. Tente novamente.', $redirectTo, $old);
}
