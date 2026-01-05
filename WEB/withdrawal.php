<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('withdrawals.access');
require_once __DIR__ . '/../API/config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    gelo_flash_set('error', 'Pedido inválido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$canViewAll = gelo_has_permission('withdrawals.view_all');
$sessionUser = gelo_current_user();
$sessionUserId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$order = null;
$items = [];
$returnEvents = [];
$returnEventItems = [];
$payments = [];
$returnedAmount = '0.00';
$returnedItems = 0;
$paidAmount = '0.00';
$paymentCount = 0;
$lastPaidAt = '';

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('
        SELECT
            o.*,
            u.name AS user_name,
            u.phone AS user_phone,
            c.name AS created_by_name,
            s.name AS separated_by_name,
            d.name AS delivered_by_name,
            x.name AS cancelled_by_name
        FROM withdrawal_orders o
        INNER JOIN users u ON u.id = o.user_id
        INNER JOIN users c ON c.id = o.created_by_user_id
        LEFT JOIN users s ON s.id = o.separated_by_user_id
        LEFT JOIN users d ON d.id = o.delivered_by_user_id
        LEFT JOIN users x ON x.id = o.cancelled_by_user_id
        WHERE o.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $order = $stmt->fetch();

    if (!is_array($order)) {
        gelo_flash_set('error', 'Pedido não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    if (!$canViewAll && (int) ($order['user_id'] ?? 0) !== $sessionUserId) {
        gelo_flash_set('error', 'Você não tem permissão para acessar este pedido.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

	    $stmt = $pdo->prepare('
	        SELECT
	            oi.product_id,
	            oi.product_title,
	            oi.unit_price,
	            oi.quantity,
	            oi.line_total,
	            COALESCE(ret.returned_qty, 0) AS returned_qty,
	            COALESCE(ret.returned_total, 0) AS returned_total
	        FROM withdrawal_order_items oi
	        LEFT JOIN (
	            SELECT
	                ri.product_id,
	                SUM(ri.quantity) AS returned_qty,
	                SUM(ri.line_total) AS returned_total
	            FROM withdrawal_returns r
	            INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
	            WHERE r.order_id = :order_id_returns
	            GROUP BY ri.product_id
	        ) ret ON ret.product_id = oi.product_id
	        WHERE oi.order_id = :order_id
	        ORDER BY oi.id ASC
	    ');
	    $stmt->execute(['order_id' => $id, 'order_id_returns' => $id]);
	    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(ri.quantity), 0) AS returned_items,
            COALESCE(SUM(ri.line_total), 0) AS returned_amount
        FROM withdrawal_returns r
        INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
        WHERE r.order_id = :id
    ');
    $stmt->execute(['id' => $id]);
    $retTotals = $stmt->fetch();
    if (is_array($retTotals)) {
        $returnedItems = (int) ($retTotals['returned_items'] ?? 0);
        $returnedAmount = (string) ($retTotals['returned_amount'] ?? '0.00');
    }

    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(amount), 0) AS paid_amount,
            COUNT(*) AS payment_count,
            MAX(paid_at) AS last_paid_at
        FROM withdrawal_payments
        WHERE order_id = :id
    ');
    $stmt->execute(['id' => $id]);
    $payTotals = $stmt->fetch();
    if (is_array($payTotals)) {
        $paidAmount = (string) ($payTotals['paid_amount'] ?? '0.00');
        $paymentCount = (int) ($payTotals['payment_count'] ?? 0);
        $lastPaidAt = isset($payTotals['last_paid_at']) ? (string) $payTotals['last_paid_at'] : '';
    }

	    $stmt = $pdo->prepare('
	        SELECT
	            p.amount,
	            p.method,
	            p.paid_at,
	            p.note,
	            u.name AS created_by_name
	        FROM withdrawal_payments p
	        INNER JOIN users u ON u.id = p.created_by_user_id
        WHERE p.order_id = :id
        ORDER BY p.paid_at ASC, p.id ASC
    ');
    $stmt->execute(['id' => $id]);
    $payments = $stmt->fetchAll();

	    $stmt = $pdo->prepare('
	        SELECT
	            r.id,
	            r.reason,
	            r.created_at,
	            u.name AS created_by_name,
	            COALESCE(SUM(ri.quantity), 0) AS items_count,
	            COALESCE(SUM(ri.line_total), 0) AS total_amount
	        FROM withdrawal_returns r
	        INNER JOIN users u ON u.id = r.created_by_user_id
	        INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
	        WHERE r.order_id = :id
	        GROUP BY r.id, r.reason, r.created_at, u.name
	        ORDER BY r.created_at DESC, r.id DESC
	    ');
    $stmt->execute(['id' => $id]);
    $returnEvents = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT
            r.id AS return_id,
            ri.product_title,
            ri.quantity,
            ri.line_total
        FROM withdrawal_returns r
        INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
        WHERE r.order_id = :id
        ORDER BY r.created_at DESC, ri.id ASC
    ');
    $stmt->execute(['id' => $id]);
    foreach ($stmt->fetchAll() as $row) {
        $rid = (int) ($row['return_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $returnEventItems[$rid][] = $row;
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar pedido. Verifique o banco e as migrações.';
}

$pageTitle = 'Retirada #' . $id . ' · GELO';
$activePage = 'withdrawals';

$status = is_array($order) ? (string) ($order['status'] ?? '') : '';
$isSaida = $status === 'saida';
$isCancelled = $status === 'cancelled';
$isRequested = $status === 'requested';

$orderUserId = is_array($order) ? (int) ($order['user_id'] ?? 0) : 0;
$isOwner = $orderUserId > 0 && $orderUserId === $sessionUserId;

$orderTotal = is_array($order) ? (string) ($order['total_amount'] ?? '0.00') : '0.00';
$netTotal = bcsub($orderTotal, $returnedAmount, 2);
if (bccomp($netTotal, '0.00', 2) < 0) {
    $netTotal = '0.00';
}
$remaining = bcsub($netTotal, $paidAmount, 2);
if (bccomp($remaining, '0.00', 2) < 0) {
    $remaining = '0.00';
}
$isPaid = bccomp($remaining, '0.00', 2) <= 0 && bccomp($netTotal, '0.00', 2) === 1;

$canCancel = gelo_has_permission('withdrawals.cancel') && !$isCancelled && !$isSaida && ($isOwner || $canViewAll);
$canDeliver = gelo_has_permission('withdrawals.deliver') && $isRequested;
$canReturn = gelo_has_permission('withdrawals.return') && $isSaida && $paymentCount === 0;
$canPay = gelo_has_permission('withdrawals.pay') && $isSaida && bccomp($remaining, '0.00', 2) === 1;

if ($isSaida) {
    $statusLabel = 'Saída';
    $statusBadge = 'badge-success badge-outline';
} elseif ($isCancelled) {
    $statusLabel = 'Cancelado';
    $statusBadge = 'badge-ghost';
} else {
    $statusLabel = 'Solicitado';
    $statusBadge = 'badge-warning badge-outline';
}
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200 overflow-x-hidden">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto w-full max-w-6xl px-4 py-5 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">Pedido #<?= (int) $id ?></h1>
                    <span class="badge <?= gelo_e($statusBadge) ?>"><?= gelo_e($statusLabel) ?></span>
                    <?php if ($isPaid): ?>
                        <span class="badge badge-success">Pago</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm opacity-70 mt-1">Detalhes do pedido de retirada.</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php') ?>">Voltar</a>
                <?php if ($canReturn): ?>
					<a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal_return.php?id=' . (int) $id) ?>">Registrar retorno</a>
                <?php endif; ?>
                <?php if ($canCancel): ?>
                    <label for="cancelModal" class="btn btn-outline btn-error">Cancelar</label>
                <?php endif; ?>
                <?php if ($canDeliver): ?>
                    <form method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/withdrawal_deliver.php') ?>">
                        <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <button class="btn btn-success" type="submit">Marcar saída</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4">
                <span><?= gelo_e($success) ?></span>
            </div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4">
                <span><?= gelo_e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (is_array($order)): ?>
            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-6">
	                    <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
	                        <div class="card-body p-4 sm:p-8">
	                            <h2 class="text-lg font-semibold">Itens</h2>
	                            <div class="mt-4 sm:hidden overflow-hidden rounded-box border border-base-200 bg-base-100 divide-y divide-base-200">
	                                <?php if (empty($items)): ?>
	                                    <div class="p-6 text-center opacity-70">Nenhum item.</div>
	                                <?php else: ?>
	                                    <?php foreach ($items as $it): ?>
	                                        <?php
	                                            $orderedQty = (int) ($it['quantity'] ?? 0);
	                                            $retQty = (int) ($it['returned_qty'] ?? 0);
	                                            $netQty = max(0, $orderedQty - $retQty);
	                                            $returnedLine = (string) ($it['returned_total'] ?? '0.00');
	                                            $lineTotal = (string) ($it['line_total'] ?? '0.00');
	                                            $netLine = bcsub($lineTotal, $returnedLine, 2);
	                                            if (bccomp($netLine, '0.00', 2) < 0) {
	                                                $netLine = '0.00';
	                                            }
	                                        ?>
	                                        <div class="p-4">
	                                            <div class="flex items-start justify-between gap-3">
	                                                <div class="min-w-0">
	                                                    <div class="font-medium truncate"><?= gelo_e((string) ($it['product_title'] ?? '')) ?></div>
	                                                    <div class="text-xs opacity-70 mt-1">Preço: <?= gelo_e(gelo_format_money($it['unit_price'] ?? 0)) ?></div>
	                                                </div>
	                                                <div class="text-right shrink-0">
	                                                    <div class="font-semibold"><?= gelo_e(gelo_format_money($netLine)) ?></div>
	                                                    <div class="text-xs opacity-70">Subtotal</div>
	                                                </div>
	                                            </div>
	                                            <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2">
	                                                <div class="text-xs opacity-70">Qtd</div>
	                                                <div class="text-sm font-medium text-right"><?= $orderedQty ?></div>
	                                                <div class="text-xs opacity-70">Devolvida</div>
	                                                <div class="text-sm font-medium text-right"><?= $retQty ?></div>
                                                    <div class="text-xs opacity-70">Saída</div>
	                                                <div class="text-sm font-medium text-right"><?= $netQty ?></div>
	                                            </div>
	                                        </div>
	                                    <?php endforeach; ?>
	                                <?php endif; ?>
	                            </div>

	                            <div class="overflow-x-auto mt-4 hidden sm:block w-full max-w-full overscroll-x-contain touch-pan-x">
	                                <table class="table">
	                                    <thead>
	                                        <tr>
	                                            <th>Produto</th>
	                                            <th class="text-right">Preço</th>
	                                            <th class="text-right">Qtd</th>
	                                            <th class="text-right">Devolvida</th>
                                                <th class="text-right">Saída</th>
	                                            <th class="text-right">Subtotal</th>
	                                        </tr>
	                                    </thead>
	                                    <tbody>
	                                        <?php foreach ($items as $it): ?>
	                                            <?php
	                                                $orderedQty = (int) ($it['quantity'] ?? 0);
	                                                $retQty = (int) ($it['returned_qty'] ?? 0);
	                                                $netQty = max(0, $orderedQty - $retQty);
	                                                $returnedLine = (string) ($it['returned_total'] ?? '0.00');
	                                                $lineTotal = (string) ($it['line_total'] ?? '0.00');
	                                                $netLine = bcsub($lineTotal, $returnedLine, 2);
	                                                if (bccomp($netLine, '0.00', 2) < 0) {
	                                                    $netLine = '0.00';
	                                                }
	                                            ?>
	                                            <tr>
	                                                <td class="font-medium"><?= gelo_e((string) ($it['product_title'] ?? '')) ?></td>
	                                                <td class="text-right"><?= gelo_e(gelo_format_money($it['unit_price'] ?? 0)) ?></td>
	                                                <td class="text-right"><?= $orderedQty ?></td>
	                                                <td class="text-right"><?= $retQty ?></td>
	                                                <td class="text-right font-medium"><?= $netQty ?></td>
	                                                <td class="text-right font-medium"><?= gelo_e(gelo_format_money($netLine)) ?></td>
	                                            </tr>
	                                        <?php endforeach; ?>
	                                    </tbody>
	                                </table>
	                            </div>
	                        </div>
	                    </div>

                    <?php $comment = (string) ($order['comment'] ?? ''); ?>
                    <?php if (trim($comment) !== ''): ?>
                        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                            <div class="card-body p-6 sm:p-8">
                                <h2 class="text-lg font-semibold">Comentário</h2>
                                <p class="mt-3 text-sm opacity-80 whitespace-pre-line"><?= gelo_e($comment) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php $cancelReason = (string) ($order['cancellation_reason'] ?? ''); ?>
                    <?php if ($isCancelled && trim($cancelReason) !== ''): ?>
                        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                            <div class="card-body p-6 sm:p-8">
                                <h2 class="text-lg font-semibold">Cancelamento</h2>
                                <p class="mt-3 text-sm opacity-80 whitespace-pre-line"><?= gelo_e($cancelReason) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($returnEvents)): ?>
	                        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
	                            <div class="card-body p-4 sm:p-8">
	                                <h2 class="text-lg font-semibold">Devoluções</h2>
	                                <p class="text-sm opacity-70 mt-1">Registro de itens devolvidos.</p>

                                <div class="mt-4 space-y-3">
                                    <?php foreach ($returnEvents as $ev): ?>
                                        <?php
                                            $rid = (int) ($ev['id'] ?? 0);
                                            $evAt = isset($ev['created_at']) ? (string) $ev['created_at'] : '';
                                            $evAtLabel = $evAt !== '' ? date('d/m/Y H:i', strtotime($evAt)) : '';
                                            $evItems = (int) ($ev['items_count'] ?? 0);
                                            $evAmount = $ev['total_amount'] ?? 0;
                                            $evReason = (string) ($ev['reason'] ?? '');
                                            $evBy = (string) ($ev['created_by_name'] ?? '');
                                        ?>
                                        <details class="collapse collapse-arrow bg-base-200/40 ring-1 ring-base-300/60">
                                            <summary class="collapse-title">
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                    <div class="font-medium"><?= gelo_e($evAtLabel) ?> · <?= gelo_e($evBy) ?></div>
                                                    <div class="text-sm opacity-80"><?= $evItems ?> itens · <?= gelo_e(gelo_format_money($evAmount)) ?></div>
                                                </div>
                                            </summary>
                                            <div class="collapse-content">
                                                <div class="text-sm opacity-80 whitespace-pre-line"><?= gelo_e($evReason) ?></div>
	                                                <?php $rows = $returnEventItems[$rid] ?? []; ?>
	                                                <?php if (!empty($rows)): ?>
	                                                    <div class="mt-3 sm:hidden overflow-hidden rounded-box border border-base-200 bg-base-100 divide-y divide-base-200">
	                                                        <?php foreach ($rows as $r): ?>
	                                                            <div class="p-3">
	                                                                <div class="flex items-start justify-between gap-3">
	                                                                    <div class="min-w-0">
	                                                                        <div class="text-sm font-medium truncate"><?= gelo_e((string) ($r['product_title'] ?? '')) ?></div>
	                                                                        <div class="text-xs opacity-70 mt-1">Qtd: <?= (int) ($r['quantity'] ?? 0) ?></div>
	                                                                    </div>
	                                                                    <div class="text-right shrink-0">
	                                                                        <div class="text-sm font-semibold"><?= gelo_e(gelo_format_money($r['line_total'] ?? 0)) ?></div>
	                                                                        <div class="text-xs opacity-70">Valor</div>
	                                                                    </div>
	                                                                </div>
	                                                            </div>
	                                                        <?php endforeach; ?>
	                                                    </div>

	                                                    <div class="mt-3 overflow-x-auto hidden sm:block w-full max-w-full overscroll-x-contain touch-pan-x">
	                                                        <table class="table table-sm">
	                                                            <thead>
	                                                                <tr>
	                                                                    <th>Item</th>
                                                                    <th class="text-right">Qtd</th>
                                                                    <th class="text-right">Valor</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($rows as $r): ?>
                                                                    <tr>
                                                                        <td><?= gelo_e((string) ($r['product_title'] ?? '')) ?></td>
                                                                        <td class="text-right"><?= (int) ($r['quantity'] ?? 0) ?></td>
                                                                        <td class="text-right"><?= gelo_e(gelo_format_money($r['line_total'] ?? 0)) ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
	                                                            </tbody>
	                                                        </table>
	                                                    </div>
	                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-6">
	                    <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
	                        <div class="card-body p-4 sm:p-8">
	                            <h2 class="text-lg font-semibold">Resumo</h2>
	                            <div class="mt-4 space-y-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Total de itens</span>
                                    <span class="font-medium"><?= (int) ($order['total_items'] ?? 0) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Valor do pedido</span>
                                    <span class="font-semibold"><?= gelo_e(gelo_format_money($orderTotal)) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Devolvido</span>
                                    <span class="font-medium"><?= gelo_e(gelo_format_money($returnedAmount)) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Total a pagar</span>
                                    <span class="font-semibold text-base"><?= gelo_e(gelo_format_money($netTotal)) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Pago</span>
                                    <span class="font-medium"><?= gelo_e(gelo_format_money($paidAmount)) ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Em aberto</span>
                                    <span class="font-semibold"><?= gelo_e(gelo_format_money($remaining)) ?></span>
                                </div>

                                <div class="divider my-4"></div>
                                <div class="text-xs opacity-70 space-y-1">
                                    <div>Cliente: <?= gelo_e((string) ($order['user_name'] ?? '')) ?> · <?= gelo_e(gelo_format_phone((string) ($order['user_phone'] ?? ''))) ?></div>
                                    <div>Criado em <?= gelo_e(date('d/m/Y H:i', strtotime((string) ($order['created_at'] ?? 'now')))) ?><?= !empty($order['created_by_name']) ? (' · ' . gelo_e((string) $order['created_by_name'])) : '' ?></div>
                                    <?php if (!empty($order['delivered_at'])): ?>
										<div>Saída em <?= gelo_e(date('d/m/Y H:i', strtotime((string) $order['delivered_at']))) ?><?= !empty($order['delivered_by_name']) ? (' · ' . gelo_e((string) $order['delivered_by_name'])) : '' ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['cancelled_at'])): ?>
                                        <div>Cancelado em <?= gelo_e(date('d/m/Y H:i', strtotime((string) $order['cancelled_at']))) ?><?= !empty($order['cancelled_by_name']) ? (' · ' . gelo_e((string) $order['cancelled_by_name'])) : '' ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['paid_at'])): ?>
                                        <div>Quitado em <?= gelo_e(date('d/m/Y H:i', strtotime((string) $order['paid_at']))) ?></div>
                                    <?php elseif ($paymentCount > 0 && $lastPaidAt !== ''): ?>
                                        <div>Último pagamento em <?= gelo_e(date('d/m/Y H:i', strtotime($lastPaidAt))) ?></div>
                                    <?php endif; ?>
                                </div>
	                            </div>
	                        </div>
	                    </div>
	
	                    <?php if ($isSaida || $paymentCount > 0): ?>
		                        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
		                            <div class="card-body p-4 sm:p-8">
		                                <div class="flex items-center justify-between gap-3">
		                                    <h2 class="text-lg font-semibold">Pagamentos</h2>
	                                    <?php if ($paymentCount > 0): ?>
	                                        <span class="badge badge-outline"><?= (int) $paymentCount ?></span>
                                    <?php endif; ?>
                                </div>
		                                <p class="text-sm opacity-70 mt-1">Registre pagamentos parciais até quitar o pedido.</p>

		                                <?php if (!empty($payments)): ?>
		                                    <div class="mt-4 sm:hidden overflow-hidden rounded-box border border-base-200 bg-base-100 divide-y divide-base-200">
		                                        <?php foreach ($payments as $p): ?>
		                                            <?php
		                                                $paidAt = isset($p['paid_at']) ? (string) $p['paid_at'] : '';
		                                                $paidLabel = $paidAt !== '' ? date('d/m/Y H:i', strtotime($paidAt)) : '';
		                                                $note = (string) ($p['note'] ?? '');
		                                            ?>
		                                            <div class="p-4">
		                                                <div class="flex items-start justify-between gap-3">
		                                                    <div class="min-w-0">
		                                                        <div class="text-sm font-medium truncate"><?= gelo_e($paidLabel) ?></div>
		                                                        <div class="text-xs opacity-70 mt-1">
		                                                            <span class="badge badge-outline badge-sm"><?= gelo_e(gelo_withdrawal_payment_method_label(isset($p['method']) ? (string) $p['method'] : null)) ?></span>
		                                                            <?php $byName = (string) ($p['created_by_name'] ?? ''); ?>
		                                                            <?php if ($byName !== ''): ?>
		                                                                <span class="ml-2"><?= gelo_e($byName) ?></span>
		                                                            <?php endif; ?>
		                                                        </div>
		                                                    </div>
		                                                    <div class="text-right shrink-0">
		                                                        <div class="font-semibold"><?= gelo_e(gelo_format_money($p['amount'] ?? 0)) ?></div>
		                                                        <div class="text-xs opacity-70">Valor</div>
		                                                    </div>
		                                                </div>
		                                                <?php if (trim($note) !== ''): ?>
		                                                    <div class="mt-2 text-xs opacity-70"><?= gelo_e($note) ?></div>
		                                                <?php endif; ?>
		                                            </div>
		                                        <?php endforeach; ?>
		                                    </div>

		                                    <div class="overflow-x-auto mt-4 hidden sm:block w-full max-w-full overscroll-x-contain touch-pan-x">
		                                        <table class="table table-sm">
		                                            <thead>
		                                                <tr>
		                                                    <th>Data</th>
	                                                    <th>Tipo</th>
	                                                    <th>Por</th>
	                                                    <th class="text-right">Valor</th>
	                                                </tr>
	                                            </thead>
	                                            <tbody>
	                                                <?php foreach ($payments as $p): ?>
                                                    <?php
                                                        $paidAt = isset($p['paid_at']) ? (string) $p['paid_at'] : '';
                                                        $paidLabel = $paidAt !== '' ? date('d/m/Y H:i', strtotime($paidAt)) : '';
                                                    ?>
	                                                    <tr>
	                                                        <td class="text-sm opacity-80"><?= gelo_e($paidLabel) ?></td>
	                                                        <td class="text-sm opacity-80">
	                                                            <span class="badge badge-outline"><?= gelo_e(gelo_withdrawal_payment_method_label(isset($p['method']) ? (string) $p['method'] : null)) ?></span>
	                                                        </td>
	                                                        <td class="text-sm opacity-80"><?= gelo_e((string) ($p['created_by_name'] ?? '')) ?></td>
	                                                        <td class="text-right font-medium"><?= gelo_e(gelo_format_money($p['amount'] ?? 0)) ?></td>
	                                                    </tr>
		                                                    <?php $note = (string) ($p['note'] ?? ''); ?>
		                                                    <?php if (trim($note) !== ''): ?>
		                                                        <tr>
		                                                            <td colspan="4" class="pt-0">
		                                                                <div class="text-xs opacity-70"><?= gelo_e($note) ?></div>
		                                                            </td>
		                                                        </tr>
		                                                    <?php endif; ?>
		                                                <?php endforeach; ?>
		                                            </tbody>
		                                        </table>
	                                    </div>
	                                <?php else: ?>
	                                    <div class="mt-4 text-sm opacity-70">Nenhum pagamento registrado.</div>
		                                <?php endif; ?>

		                                <?php if ($canPay): ?>
		                                    <form class="mt-5 space-y-3" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/withdrawal_payment_add.php') ?>">
	                                        <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
	                                        <input type="hidden" name="id" value="<?= (int) $id ?>">

	                                        <label class="form-control w-full">
	                                            <div class="label"><span class="label-text">Tipo</span></div>
	                                            <select class="select select-bordered w-full" name="method" required>
	                                                <option value="" disabled selected>Selecione…</option>
	                                                <?php foreach (gelo_withdrawal_payment_methods() as $key => $label): ?>
	                                                    <option value="<?= gelo_e($key) ?>"><?= gelo_e($label) ?></option>
	                                                <?php endforeach; ?>
	                                            </select>
	                                        </label>

	                                        <label class="form-control w-full">
	                                            <div class="label"><span class="label-text">Valor</span></div>
	                                            <input class="input input-bordered w-full" type="text" name="amount" data-mask="money" placeholder="R$ 0,00" required />
	                                        </label>

	                                        <label class="form-control w-full">
	                                            <div class="label"><span class="label-text">Observação (opcional)</span></div>
	                                            <input class="input input-bordered w-full" type="text" name="note" maxlength="255" placeholder="Ex.: troco, comprovante, detalhes…" />
	                                        </label>

	                                        <button class="btn btn-primary w-full" type="submit">Registrar pagamento</button>
	                                    </form>
	                                <?php else: ?>
	                                    <div class="mt-5 text-xs opacity-70">
	                                        <?php if (!$isSaida): ?>
	                                            Pagamentos disponíveis somente após a saída.
	                                        <?php elseif (bccomp($remaining, '0.00', 2) <= 0): ?>
	                                            Pedido já está quitado.
	                                        <?php else: ?>
	                                            Você não tem permissão para registrar pagamentos.
	                                        <?php endif; ?>
	                                    </div>
	                                <?php endif; ?>
	                            </div>
	                        </div>
	                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($canCancel): ?>
        <input type="checkbox" id="cancelModal" class="modal-toggle" />
        <div class="modal" role="dialog">
            <div class="modal-box">
                <h3 class="font-semibold text-lg">Cancelar pedido</h3>
                <p class="text-sm opacity-70 mt-1">Informe a justificativa. Esta ação não pode ser desfeita.</p>

                <form class="mt-4 space-y-4" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/withdrawal_cancel.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <textarea class="textarea textarea-bordered w-full" name="reason" rows="4" minlength="5" placeholder="Digite a justificativa…" required></textarea>

                    <div class="modal-action">
                        <label for="cancelModal" class="btn btn-ghost">Voltar</label>
                        <button class="btn btn-error" type="submit">Confirmar cancelamento</button>
                    </div>
                </form>
            </div>
            <label class="modal-backdrop" for="cancelModal">Fechar</label>
        </div>
    <?php endif; ?>
</body>
</html>
