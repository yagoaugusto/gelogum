<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.return']);
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$orderId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$reason = isset($_POST['reason']) ? trim((string) $_POST['reason']) : '';
$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['return_qty'] ?? [];

if ($orderId <= 0) {
    gelo_flash_set('error', 'Pedido inválido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$reasonLen = function_exists('mb_strlen') ? (int) mb_strlen($reason, 'UTF-8') : strlen($reason);
if ($reasonLen < 5) {
    gelo_flash_set('error', 'Informe uma justificativa (mínimo 5 caracteres).');
    gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
}

if (!is_array($productIds) || !is_array($quantities) || count($productIds) !== count($quantities)) {
    gelo_flash_set('error', 'Itens inválidos. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
}

$toReturn = [];
for ($i = 0; $i < count($productIds); $i++) {
    $pid = (int) $productIds[$i];
    $qty = (int) $quantities[$i];
    if ($pid <= 0 || $qty <= 0) {
        continue;
    }
    $toReturn[$pid] = ($toReturn[$pid] ?? 0) + $qty;
}

if (empty($toReturn)) {
    gelo_flash_set('error', 'Informe pelo menos uma devolução.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
}

$sessionUser = gelo_current_user();
$actorId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('SELECT user_id, status FROM withdrawal_orders WHERE id = :id LIMIT 1');
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

    if ((string) ($order['status'] ?? '') !== 'delivered') {
        gelo_flash_set('error', 'Devolução só é permitida após o pedido ser entregue.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM withdrawal_payments WHERE order_id = :id');
    $stmt->execute(['id' => $orderId]);
    $pay = $stmt->fetch();
    $paymentCount = is_array($pay) ? (int) ($pay['c'] ?? 0) : 0;
    if ($paymentCount > 0) {
        gelo_flash_set('error', 'Não é possível registrar devolução após iniciar pagamentos.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
    }

    $stmt = $pdo->prepare('SELECT product_id, product_title, unit_price, quantity FROM withdrawal_order_items WHERE order_id = :id');
    $stmt->execute(['id' => $orderId]);
    $items = $stmt->fetchAll();
    $byProduct = [];
    foreach ($items as $it) {
        $byProduct[(int) $it['product_id']] = $it;
    }

    $stmt = $pdo->prepare('
        SELECT ri.product_id, COALESCE(SUM(ri.quantity), 0) AS returned_qty
        FROM withdrawal_returns r
        INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
        WHERE r.order_id = :id
        GROUP BY ri.product_id
    ');
    $stmt->execute(['id' => $orderId]);
    $returnedMap = [];
    foreach ($stmt->fetchAll() as $r) {
        $returnedMap[(int) $r['product_id']] = (int) ($r['returned_qty'] ?? 0);
    }

    $returnLines = [];
    foreach ($toReturn as $pid => $qty) {
        $row = $byProduct[$pid] ?? null;
        if (!is_array($row)) {
            gelo_flash_set('error', 'Um ou mais produtos não pertencem a este pedido.');
            gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
        }

        $orderedQty = (int) ($row['quantity'] ?? 0);
        $alreadyReturned = (int) ($returnedMap[$pid] ?? 0);
        $available = $orderedQty - $alreadyReturned;
        if ($available <= 0) {
            gelo_flash_set('error', 'Este pedido não possui itens disponíveis para devolução.');
            gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
        }
        if ($qty > $available) {
            gelo_flash_set('error', 'A quantidade devolvida excede o disponível para um ou mais itens.');
            gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
        }

        $unitPrice = (string) $row['unit_price'];
        $lineTotal = bcmul($unitPrice, (string) $qty, 2);
        $returnLines[] = [
            'product_id' => $pid,
            'product_title' => (string) $row['product_title'],
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'line_total' => $lineTotal,
        ];
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO withdrawal_returns (order_id, created_by_user_id, reason) VALUES (:order_id, :created_by, :reason)');
    $stmt->execute([
        'order_id' => $orderId,
        'created_by' => $actorId,
        'reason' => $reason,
    ]);
    $returnId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('
        INSERT INTO withdrawal_return_items
            (return_id, product_id, product_title, unit_price, quantity, line_total)
        VALUES
            (:return_id, :product_id, :product_title, :unit_price, :quantity, :line_total)
    ');
    foreach ($returnLines as $line) {
        $stmt->execute([
            'return_id' => $returnId,
            'product_id' => $line['product_id'],
            'product_title' => $line['product_title'],
            'unit_price' => $line['unit_price'],
            'quantity' => $line['quantity'],
            'line_total' => $line['line_total'],
        ]);
    }

    $pdo->commit();

    gelo_flash_set('success', 'Devolução registrada.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
        // ignore
    }
    gelo_flash_set('error', 'Erro ao registrar devolução. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal_return.php?id=' . $orderId);
}
