<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.deliver']);
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
if ($id <= 0) {
    gelo_flash_set('error', 'Pedido inválido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$canViewAll = gelo_has_permission('withdrawals.view_all');
$sessionUser = gelo_current_user();
$actorId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;

try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('SELECT user_id, status FROM withdrawal_orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        gelo_flash_set('error', 'Pedido não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    if (!$canViewAll && (int) ($row['user_id'] ?? 0) !== $actorId) {
        gelo_flash_set('error', 'Você não tem permissão para atualizar este pedido.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    $status = (string) ($row['status'] ?? '');
    if ($status === 'delivered') {
        gelo_flash_set('success', 'Pedido já estava como entregue.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
    }

    if ($status !== 'separated') {
        gelo_flash_set('error', 'O pedido precisa estar como separado antes de ser entregue.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
    }

    $pdo->prepare("UPDATE withdrawal_orders SET status = 'delivered', delivered_at = NOW(), delivered_by_user_id = :actor WHERE id = :id")
        ->execute(['actor' => $actorId, 'id' => $id]);

    // Disparo WPP (best-effort)
    try {
        gelo_whatsapp_notify_order($id, $status !== '' ? $status : null, 'delivered');
    } catch (Throwable $ignored) {
        // ignore
    }
    gelo_flash_set('success', 'Pedido marcado como entregue.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
} catch (Throwable $e) {
    gelo_flash_set('error', 'Erro ao atualizar pedido. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
}
