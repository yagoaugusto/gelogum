<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.cancel']);
require_once __DIR__ . '/../../../API/config/database.php';
require_once __DIR__ . '/../lib/whatsapp_ultramsg.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$reason = isset($_POST['reason']) ? trim((string) $_POST['reason']) : '';

if ($id <= 0) {
    gelo_flash_set('error', 'Pedido inválido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$reasonLen = function_exists('mb_strlen') ? (int) mb_strlen($reason, 'UTF-8') : strlen($reason);
if ($reasonLen < 5) {
    gelo_flash_set('error', 'Informe uma justificativa (mínimo 5 caracteres).');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
}

$canViewAll = gelo_has_permission('withdrawals.view_all');
$sessionUser = gelo_current_user();
$actorId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;

try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('SELECT user_id, status FROM withdrawal_orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $order = $stmt->fetch();
    if (!is_array($order)) {
        gelo_flash_set('error', 'Pedido não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    $orderUserId = (int) ($order['user_id'] ?? 0);
    if (!$canViewAll && $orderUserId !== (int) ($sessionUser['id'] ?? 0)) {
        gelo_flash_set('error', 'Você não tem permissão para cancelar este pedido.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    $status = (string) ($order['status'] ?? '');
    if ($status === 'delivered') {
        gelo_flash_set('error', 'Não é possível cancelar um pedido entregue.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
    }
    if ($status === 'cancelled') {
        gelo_flash_set('success', 'Pedido já estava cancelado.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
    }

    $pdo->prepare("UPDATE withdrawal_orders SET status = 'cancelled', cancelled_at = NOW(), cancelled_by_user_id = :actor, cancellation_reason = :reason WHERE id = :id")
        ->execute(['actor' => $actorId, 'reason' => $reason, 'id' => $id]);

    // Disparo WPP (best-effort)
    try {
        gelo_whatsapp_notify_order($id, $status !== '' ? $status : null, 'cancelled');
    } catch (Throwable $ignored) {
        // ignore
    }

    gelo_flash_set('success', 'Pedido cancelado.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
} catch (Throwable $e) {
    gelo_flash_set('error', 'Erro ao cancelar pedido. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
}
