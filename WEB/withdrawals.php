<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('withdrawals.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Retiradas · GELO';
$activePage = 'withdrawals';

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
$status = in_array($status, ['all', 'requested', 'saida', 'cancelled'], true) ? $status : 'all';
$canViewFinancial = gelo_has_permission('withdrawals.view_financial');
$showTotalColumn = $canViewFinancial;
$showPaymentColumn = $status === 'saida' && $canViewFinancial;

$mine = isset($_GET['mine']) ? strtolower(trim((string) $_GET['mine'])) : '';
$forceMine = in_array($mine, ['1', 'true', 'yes'], true);
$mineQuery = $forceMine ? '&mine=1' : '';

$hasViewAll = gelo_has_permission('withdrawals.view_all');
$canViewAll = $hasViewAll && !$forceMine;
$canCreateForClient = gelo_has_permission('withdrawals.create_for_client');
$sessionUser = gelo_current_user();
$sessionUserId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;

$where = [];
$params = [];

if (!$canViewAll) {
    if ($canCreateForClient) {
        $where[] = '(o.user_id = :user_id OR o.created_by_user_id = :user_id)';
    } else {
        $where[] = 'o.user_id = :user_id';
    }
    $params['user_id'] = $sessionUserId;
}

if ($q !== '') {
    if ($canViewAll || $canCreateForClient) {
        $where[] = '(u.name LIKE :q_name OR u.phone LIKE :q_phone OR o.id = :qid)';
        $like = '%' . $q . '%';
        $params['q_name'] = $like;
        $params['q_phone'] = $like;
        $params['qid'] = ctype_digit($q) ? (int) $q : -1;
    } else {
        $where[] = '(o.id = :qid)';
        $params['qid'] = ctype_digit($q) ? (int) $q : -1;
    }
}

if ($status !== 'all') {
    $where[] = 'o.status = :status';
    $params['status'] = $status;
}

$sql = '
    SELECT
        o.id,
        o.user_id,
        o.status,
        o.total_items,
        o.total_amount,
        o.created_at,
        o.delivered_at,
        u.name AS user_name,
        u.phone AS user_phone
';
if ($showPaymentColumn) {
    $sql .= ',
        COALESCE(pay.paid_amount, 0) AS paid_amount,
        COALESCE(ret.returned_amount, 0) AS returned_amount
    ';
}
$sql .= '
    FROM withdrawal_orders o
    INNER JOIN users u ON u.id = o.user_id
';
if ($showPaymentColumn) {
    $sql .= '
        LEFT JOIN (
            SELECT order_id, COALESCE(SUM(amount), 0) AS paid_amount
            FROM withdrawal_payments
            GROUP BY order_id
        ) pay ON pay.order_id = o.id
        LEFT JOIN (
            SELECT r.order_id, COALESCE(SUM(ri.line_total), 0) AS returned_amount
            FROM withdrawal_returns r
            INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
            GROUP BY r.order_id
        ) ret ON ret.order_id = o.id
    ';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY o.created_at DESC LIMIT 200';

$orders = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar retiradas. Verifique o banco e as migrações.';
}
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto max-w-6xl p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">Retiradas</h1>
                    <?php if ($forceMine): ?>
                        <span class="badge badge-outline">Meus pedidos</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm opacity-70 mt-1">Pedidos de retirada com produtos e quantidades.</p>
            </div>
            <a class="btn btn-primary" href="<?= gelo_e($canCreateForClient ? (GELO_BASE_URL . '/withdrawal_new_admin.php') : (GELO_BASE_URL . '/withdrawal_new.php')) ?>">Novo pedido</a>
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

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-4 sm:p-6">
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php') ?>">
                    <?php if ($forceMine): ?>
                        <input type="hidden" name="mine" value="1">
                    <?php endif; ?>
                    <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                        <input class="grow" type="text" name="q" value="<?= gelo_e($q) ?>" placeholder="<?= $canViewAll ? 'Buscar por cliente ou #pedido' : 'Buscar por #pedido' ?>" />
                        <span class="opacity-60 text-sm">⌕</span>
                    </label>

                    <div class="join">
                        <a class="btn btn-sm join-item <?= $status === 'all' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?q=' . urlencode($q) . '&status=all' . $mineQuery) ?>">Todos</a>
                        <a class="btn btn-sm join-item <?= $status === 'requested' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?q=' . urlencode($q) . '&status=requested' . $mineQuery) ?>">Solicitados</a>
                        <a class="btn btn-sm join-item <?= $status === 'saida' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?q=' . urlencode($q) . '&status=saida' . $mineQuery) ?>">Saídas</a>
                        <a class="btn btn-sm join-item <?= $status === 'cancelled' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?q=' . urlencode($q) . '&status=cancelled' . $mineQuery) ?>">Cancelados</a>
                    </div>
                </form>

                <div class="overflow-x-auto mt-4">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Cliente</th>
                                <th>Itens</th>
                                <?php if ($showTotalColumn): ?>
                                    <th>Total</th>
                                <?php endif; ?>
                                <?php if ($showPaymentColumn): ?>
                                    <th>Pagamento</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Criado</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="<?= 6 + ($showTotalColumn ? 1 : 0) + ($showPaymentColumn ? 1 : 0) ?>" class="py-8 text-center opacity-70">Nenhum pedido encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $o): ?>
                                    <?php
                                        $statusValue = (string) ($o['status'] ?? '');
                                        $createdLabel = '';
                                        $createdAt = isset($o['created_at']) ? (string) $o['created_at'] : '';
                                        if ($createdAt !== '') {
                                            $createdLabel = date('d/m/Y H:i', strtotime($createdAt));
                                        }
                                        $clientLabel = trim((string) ($o['user_name'] ?? ''));
                                        $clientPhone = (string) ($o['user_phone'] ?? '');

                                        $paymentBadgeLabel = '';
                                        $paymentBadgeClass = 'badge-ghost';
                                        $paidAmount = '0.00';
                                        $payableAmount = '0.00';
                                        $openAmount = '0.00';
                                        if ($showPaymentColumn) {
                                            $paidAmount = (string) ($o['paid_amount'] ?? '0.00');
                                            $returnedAmount = (string) ($o['returned_amount'] ?? '0.00');
                                            $orderTotal = (string) ($o['total_amount'] ?? '0.00');

                                            $netTotal = bcsub($orderTotal, $returnedAmount, 2);
                                            if (bccomp($netTotal, '0.00', 2) < 0) {
                                                $netTotal = '0.00';
                                            }
                                            $payableAmount = $netTotal;

                                            $openAmount = bcsub($netTotal, $paidAmount, 2);
                                            if (bccomp($openAmount, '0.00', 2) < 0) {
                                                $openAmount = '0.00';
                                            }

                                            if (bccomp($netTotal, '0.00', 2) === 0) {
                                                $paymentBadgeLabel = 'Sem cobrança';
                                                $paymentBadgeClass = 'badge-ghost';
                                            } elseif (bccomp($openAmount, '0.00', 2) === 0 && bccomp($paidAmount, '0.00', 2) === 1) {
                                                $paymentBadgeLabel = 'Pago';
                                                $paymentBadgeClass = 'badge-success badge-outline';
                                            } elseif (bccomp($paidAmount, '0.00', 2) === 1) {
                                                $paymentBadgeLabel = 'Parcial';
                                                $paymentBadgeClass = 'badge-info badge-outline';
                                            } else {
                                                $paymentBadgeLabel = 'Em aberto';
                                                $paymentBadgeClass = 'badge-warning badge-outline';
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td class="font-mono">#<?= (int) ($o['id'] ?? 0) ?></td>
                                        <td>
                                            <div class="font-medium"><?= gelo_e($clientLabel) ?></div>
                                            <div class="text-xs opacity-70"><?= gelo_e(gelo_format_phone($clientPhone)) ?></div>
                                        </td>
                                        <td><?= (int) ($o['total_items'] ?? 0) ?></td>
                                        <?php if ($showTotalColumn): ?>
                                            <td><?= gelo_e(gelo_format_money($o['total_amount'] ?? 0)) ?></td>
                                        <?php endif; ?>
                                        <?php if ($showPaymentColumn): ?>
                                            <td>
                                                <div class="flex flex-col gap-1">
                                                    <div><span class="badge <?= gelo_e($paymentBadgeClass) ?>"><?= gelo_e($paymentBadgeLabel) ?></span></div>
                                                    <div class="text-xs opacity-70">
                                                        <div>A pagar: <span class="font-medium"><?= gelo_e(gelo_format_money($payableAmount)) ?></span></div>
                                                        <div>Pago: <span class="font-medium"><?= gelo_e(gelo_format_money($paidAmount)) ?></span></div>
                                                        <div>Em aberto: <span class="font-medium"><?= gelo_e(gelo_format_money($openAmount)) ?></span></div>
                                                    </div>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if ($statusValue === 'saida'): ?>
                                                <span class="badge badge-success badge-outline">Saída</span>
                                            <?php elseif ($statusValue === 'cancelled'): ?>
                                                <span class="badge badge-ghost">Cancelado</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning badge-outline">Solicitado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-sm opacity-80"><?= gelo_e($createdLabel) ?></td>
                                        <td class="text-right">
                                            <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal.php?id=' . (int) $o['id']) ?>">Ver</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
