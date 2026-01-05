<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.pay']);
require_once __DIR__ . '/../../../API/config/database.php';
require_once __DIR__ . '/../lib/whatsapp_ultramsg.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}

$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$method = isset($_POST['method']) ? (string) $_POST['method'] : '';
$amountInput = isset($_POST['amount']) ? (string) $_POST['amount'] : '';
$note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';

if ($userId <= 0) {
    gelo_flash_set('error', 'Usuário inválido.');
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}

$methods = gelo_withdrawal_payment_methods();
if (!array_key_exists($method, $methods)) {
    gelo_flash_set('error', 'Selecione o tipo de pagamento.');
    gelo_redirect(GELO_BASE_URL . '/payment_new.php?user_id=' . $userId);
}

$amount = gelo_parse_money($amountInput);
if ($amount === null || bccomp($amount, '0.00', 2) <= 0) {
    gelo_flash_set('error', 'Informe um valor de pagamento válido.');
    gelo_redirect(GELO_BASE_URL . '/payment_new.php?user_id=' . $userId);
}

$sessionUser = gelo_current_user();
$actorId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

if (!$canViewAll && $actorId !== $userId) {
    gelo_flash_set('error', 'Você não tem permissão para registrar pagamento para outro usuário.');
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}

try {
    $pdo = gelo_pdo();

    // Carrega usuário alvo
    $stmt = $pdo->prepare('SELECT id, name, phone, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $targetUser = $stmt->fetch();
    if (!is_array($targetUser)) {
        gelo_flash_set('error', 'Usuário não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }
    if ((int) ($targetUser['is_active'] ?? 0) !== 1) {
        gelo_flash_set('error', 'Usuário inativo.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }

    $returnsJoin = '
        LEFT JOIN (
            SELECT r.order_id, COALESCE(SUM(ri.line_total), 0) AS returned_amount
            FROM withdrawal_returns r
            INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
            GROUP BY r.order_id
        ) ret ON ret.order_id = o.id
    ';
    $paymentsJoin = '
        LEFT JOIN (
            SELECT order_id, COALESCE(SUM(amount), 0) AS paid_amount
            FROM withdrawal_payments
            GROUP BY order_id
        ) pay ON pay.order_id = o.id
    ';

    // Lista pedidos com saldo em aberto (mais antigo -> mais novo)
    $stmt = $pdo->prepare('
        SELECT
            o.id,
            o.created_at,
            o.delivered_at,
            o.total_amount,
            COALESCE(ret.returned_amount, 0) AS returned_amount,
            COALESCE(pay.paid_amount, 0) AS paid_amount,
            GREATEST(
                GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0),
                0
            ) AS open_amount
        FROM withdrawal_orders o
        ' . $returnsJoin . '
        ' . $paymentsJoin . '
        WHERE o.user_id = :uid
          AND o.status IN (\'saida\', \'delivered\')
        ORDER BY COALESCE(o.delivered_at, o.created_at) ASC, o.id ASC
    ');
    $stmt->execute(['uid' => $userId]);
    $orders = $stmt->fetchAll();

    $openBefore = '0.00';
    $openOrders = [];
    foreach ($orders as $o) {
        if (!is_array($o)) {
            continue;
        }
        $open = (string) ($o['open_amount'] ?? '0.00');
        if (bccomp($open, '0.00', 2) <= 0) {
            continue;
        }
        $openBefore = bcadd($openBefore, $open, 2);
        $openOrders[] = $o;
    }

    if (bccomp($openBefore, '0.00', 2) <= 0) {
        gelo_flash_set('error', 'Este usuário não possui saldo em aberto.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }

    if (bccomp($amount, $openBefore, 2) === 1) {
        gelo_flash_set('error', 'O pagamento não pode exceder o total em aberto.');
        gelo_redirect(GELO_BASE_URL . '/payment_new.php?user_id=' . $userId);
    }

    $openAfter = bcsub($openBefore, $amount, 2);
    if (bccomp($openAfter, '0.00', 2) < 0) {
        $openAfter = '0.00';
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO user_payments (user_id, amount, method, paid_at, created_by_user_id, note, open_before, open_after)
        VALUES (:user_id, :amount, :method, NOW(), :created_by, :note, :open_before, :open_after)
    ');
    $stmt->execute([
        'user_id' => $userId,
        'amount' => $amount,
        'method' => $method,
        'created_by' => $actorId,
        'note' => $note !== '' ? $note : null,
        'open_before' => $openBefore,
        'open_after' => $openAfter,
    ]);
    $userPaymentId = (int) $pdo->lastInsertId();

    $remaining = $amount;

    $paymentNote = 'Pagamento lote #' . $userPaymentId;
    if ($note !== '') {
        $paymentNote .= ' · ' . $note;
    }
    if (function_exists('mb_substr')) {
        $paymentNote = mb_substr($paymentNote, 0, 255, 'UTF-8');
    } else {
        $paymentNote = substr($paymentNote, 0, 255);
    }

    $insertPayment = $pdo->prepare('
        INSERT INTO withdrawal_payments (order_id, amount, method, paid_at, created_by_user_id, note)
        VALUES (:order_id, :amount, :method, NOW(), :created_by, :note)
    ');

    $insertAlloc = $pdo->prepare('
        INSERT INTO user_payment_allocations (user_payment_id, order_id, withdrawal_payment_id, amount, open_before, open_after)
        VALUES (:user_payment_id, :order_id, :withdrawal_payment_id, :amount, :open_before, :open_after)
    ');

    foreach ($openOrders as $o) {
        if (bccomp($remaining, '0.00', 2) <= 0) {
            break;
        }

        $orderId = (int) ($o['id'] ?? 0);
        $orderOpenBefore = (string) ($o['open_amount'] ?? '0.00');
        if ($orderId <= 0 || bccomp($orderOpenBefore, '0.00', 2) <= 0) {
            continue;
        }

        $alloc = (bccomp($remaining, $orderOpenBefore, 2) >= 0) ? $orderOpenBefore : $remaining;
        $orderOpenAfter = bcsub($orderOpenBefore, $alloc, 2);
        if (bccomp($orderOpenAfter, '0.00', 2) < 0) {
            $orderOpenAfter = '0.00';
        }

        $insertPayment->execute([
            'order_id' => $orderId,
            'amount' => $alloc,
            'method' => $method,
            'created_by' => $actorId,
            'note' => $paymentNote !== '' ? $paymentNote : null,
        ]);
        $withdrawalPaymentId = (int) $pdo->lastInsertId();

        $insertAlloc->execute([
            'user_payment_id' => $userPaymentId,
            'order_id' => $orderId,
            'withdrawal_payment_id' => $withdrawalPaymentId,
            'amount' => $alloc,
            'open_before' => $orderOpenBefore,
            'open_after' => $orderOpenAfter,
        ]);

        if (bccomp($orderOpenAfter, '0.00', 2) <= 0) {
            $pdo->prepare('UPDATE withdrawal_orders SET paid_at = IFNULL(paid_at, NOW()) WHERE id = :id')->execute(['id' => $orderId]);
        }

        $remaining = bcsub($remaining, $alloc, 2);
    }

    $pdo->commit();

    gelo_whatsapp_notify_user_payment($userPaymentId);

    gelo_flash_set('success', 'Pagamento registrado.');
    gelo_redirect(GELO_BASE_URL . '/payment_receipt.php?id=' . $userPaymentId);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
        // ignore
    }

    gelo_flash_set('error', 'Erro ao registrar pagamento. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}
