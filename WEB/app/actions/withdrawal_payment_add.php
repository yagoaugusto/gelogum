<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.pay']);
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

$orderId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$method = isset($_POST['method']) ? (string) $_POST['method'] : '';
$amountInput = isset($_POST['amount']) ? (string) $_POST['amount'] : '';
$note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';

if ($orderId <= 0) {
    gelo_flash_set('error', 'Pedido inválido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$methods = gelo_withdrawal_payment_methods();
if (!array_key_exists($method, $methods)) {
    gelo_flash_set('error', 'Selecione o tipo de pagamento.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
}

$amount = gelo_parse_money($amountInput);
if ($amount === null || (float) $amount <= 0) {
    gelo_flash_set('error', 'Informe um valor de pagamento válido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
}

$sessionUser = gelo_current_user();
$actorId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('SELECT user_id, status, total_amount FROM withdrawal_orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    if (!is_array($order)) {
        gelo_flash_set('error', 'Pedido não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    if (!$canViewAll && (int) ($order['user_id'] ?? 0) !== $actorId) {
        gelo_flash_set('error', 'Você não tem permissão para acessar este pedido.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    if ((string) ($order['status'] ?? '') !== 'saida') {
        gelo_flash_set('error', 'Pagamento só pode ser lançado após o pedido ter saída.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
    }

    $returnsTotal = '0.00';
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(ri.line_total), 0) AS returned_amount
        FROM withdrawal_returns r
        INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
        WHERE r.order_id = :id
    ');
    $stmt->execute(['id' => $orderId]);
    $ret = $stmt->fetch();
    if (is_array($ret) && isset($ret['returned_amount'])) {
        $returnsTotal = (string) $ret['returned_amount'];
    }

    $paidTotal = '0.00';
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid_amount FROM withdrawal_payments WHERE order_id = :id');
    $stmt->execute(['id' => $orderId]);
    $paid = $stmt->fetch();
    if (is_array($paid) && isset($paid['paid_amount'])) {
        $paidTotal = (string) $paid['paid_amount'];
    }

    $orderTotal = (string) ($order['total_amount'] ?? '0.00');
    $netTotal = bcsub($orderTotal, $returnsTotal, 2);
    if (bccomp($netTotal, '0.00', 2) < 0) {
        $netTotal = '0.00';
    }
    $remaining = bcsub($netTotal, $paidTotal, 2);

    if (bccomp($amount, $remaining, 2) === 1) {
        gelo_flash_set('error', 'O pagamento não pode exceder o valor em aberto.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
    }

	    $pdo->beginTransaction();

	    $stmt = $pdo->prepare('
	        INSERT INTO withdrawal_payments (order_id, amount, method, paid_at, created_by_user_id, note)
	        VALUES (:order_id, :amount, :method, NOW(), :created_by, :note)
	    ');
	    $stmt->execute([
	        'order_id' => $orderId,
	        'amount' => $amount,
	        'method' => $method,
	        'created_by' => $actorId,
	        'note' => $note !== '' ? $note : null,
	    ]);

        $paymentId = (int) $pdo->lastInsertId();

    $newPaid = bcadd($paidTotal, $amount, 2);
    $newRemaining = bcsub($netTotal, $newPaid, 2);
    if (bccomp($newRemaining, '0.00', 2) <= 0) {
        $pdo->prepare('UPDATE withdrawal_orders SET paid_at = IFNULL(paid_at, NOW()) WHERE id = :id')->execute(['id' => $orderId]);
    }

    $pdo->commit();

    if (isset($paymentId) && $paymentId > 0) {
        gelo_whatsapp_notify_order_payment($orderId, $paymentId);
    }

    gelo_flash_set('success', 'Pagamento registrado.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
        // ignore
    }
    gelo_flash_set('error', 'Erro ao registrar pagamento. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
}
