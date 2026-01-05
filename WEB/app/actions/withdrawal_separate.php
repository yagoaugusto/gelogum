<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.separate']);
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

// Etapa SEPARADO foi removida do fluxo.
gelo_flash_set('error', 'A etapa "Separado" foi desativada. Use "Marcar saída".');
gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);

$canViewAll = gelo_has_permission('withdrawals.view_all');
$sessionUser = gelo_current_user();
$actorId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;

// Código antigo de separação removido.
