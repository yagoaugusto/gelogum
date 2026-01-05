<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('withdrawals.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Extrato de pagamento · GELO';
$activePage = 'payments';
$error = gelo_flash_get('error');

$user = gelo_current_user();
$sessionUserId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    gelo_flash_set('error', 'Pagamento inválido.');
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}

$payment = null;
$allocations = [];

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('
        SELECT
            up.id,
            up.user_id,
            up.amount,
            up.method,
            up.paid_at,
            up.note,
            up.open_before,
            up.open_after,
            u.name AS user_name,
            u.phone AS user_phone,
            cu.name AS created_by_name
        FROM user_payments up
        INNER JOIN users u ON u.id = up.user_id
        INNER JOIN users cu ON cu.id = up.created_by_user_id
        WHERE up.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $payment = $stmt->fetch();
    if (!is_array($payment)) {
        gelo_flash_set('error', 'Pagamento não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }

    $targetUserId = (int) ($payment['user_id'] ?? 0);
    if (!$canViewAll && $targetUserId !== $sessionUserId) {
        gelo_flash_set('error', 'Você não tem permissão para acessar este extrato.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }

    $stmt = $pdo->prepare('
        SELECT
            a.order_id,
            a.amount,
            a.open_before,
            a.open_after,
            o.created_at,
            o.delivered_at
        FROM user_payment_allocations a
        INNER JOIN withdrawal_orders o ON o.id = a.order_id
        WHERE a.user_payment_id = :id
        ORDER BY COALESCE(o.delivered_at, o.created_at) ASC, o.id ASC
    ');
    $stmt->execute(['id' => $id]);
    $allocations = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar extrato. Verifique o banco e as migrações.';
}

$methods = gelo_withdrawal_payment_methods();
$methodKey = is_array($payment) ? (string) ($payment['method'] ?? '') : '';
$methodLabel = $methods[$methodKey] ?? $methodKey;
$paidAt = is_array($payment) ? (string) ($payment['paid_at'] ?? '') : '';
$paidLabel = $paidAt !== '' ? date('d/m/Y H:i', strtotime($paidAt)) : '';
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto max-w-5xl p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Extrato de pagamento</h1>
                <p class="text-sm opacity-70 mt-1">Pagamento #<?= (int) $id ?></p>
            </div>
            <div class="flex gap-2">
                <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payment_history.php?user_id=' . (int) ($payment['user_id'] ?? 0)) ?>">Voltar</a>
            </div>
        </div>

        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4"><span><?= gelo_e($error) ?></span></div>
        <?php endif; ?>

        <?php if (is_array($payment)): ?>
            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
                <div class="card-body p-4 sm:p-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs uppercase tracking-wide opacity-60">Cliente</div>
                            <div class="mt-1 font-medium"><?= gelo_e((string) ($payment['user_name'] ?? '')) ?></div>
                            <div class="text-sm opacity-70"><?= gelo_e(gelo_format_phone((string) ($payment['user_phone'] ?? ''))) ?></div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide opacity-60">Pagamento</div>
                            <div class="mt-1 font-semibold"><?= gelo_e(gelo_format_money($payment['amount'] ?? 0)) ?></div>
                            <div class="text-sm opacity-70"><?= gelo_e($methodLabel) ?> · <?= gelo_e($paidLabel) ?></div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide opacity-60">Em aberto</div>
                            <div class="mt-1 text-sm">Antes: <span class="font-medium"><?= gelo_e(gelo_format_money($payment['open_before'] ?? 0)) ?></span></div>
                            <div class="text-sm">Depois: <span class="font-medium"><?= gelo_e(gelo_format_money($payment['open_after'] ?? 0)) ?></span></div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-wide opacity-60">Registrado por</div>
                            <div class="mt-1 text-sm"><?= gelo_e((string) ($payment['created_by_name'] ?? '')) ?></div>
                            <?php $note = trim((string) ($payment['note'] ?? '')); ?>
                            <?php if ($note !== ''): ?>
                                <div class="text-sm opacity-70">Obs.: <?= gelo_e($note) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
                <div class="card-body p-4 sm:p-6">
                    <h2 class="text-lg font-semibold">Pedidos compensados</h2>
                    <p class="text-sm opacity-70 mt-1">Compensação do mais antigo para o mais novo.</p>

                    <div class="overflow-x-auto mt-4">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Pedido</th>
                                    <th>Data</th>
                                    <th class="text-right">Aplicado</th>
                                    <th class="text-right">Em aberto (antes)</th>
                                    <th class="text-right">Em aberto (depois)</th>
                                    <th class="text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allocations)): ?>
                                    <tr>
                                        <td colspan="6" class="py-8 text-center opacity-70">Nenhuma compensação encontrada.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allocations as $a): ?>
                                        <?php
                                            $oid = (int) ($a['order_id'] ?? 0);
                                            $dt = (string) ($a['delivered_at'] ?? $a['created_at'] ?? '');
                                            $dtLabel = $dt !== '' ? date('d/m/Y', strtotime($dt)) : '';
                                        ?>
                                        <tr>
                                            <td class="font-medium">#<?= (int) $oid ?></td>
                                            <td><?= gelo_e($dtLabel) ?></td>
                                            <td class="text-right font-semibold"><?= gelo_e(gelo_format_money($a['amount'] ?? 0)) ?></td>
                                            <td class="text-right"><?= gelo_e(gelo_format_money($a['open_before'] ?? 0)) ?></td>
                                            <td class="text-right"><?= gelo_e(gelo_format_money($a['open_after'] ?? 0)) ?></td>
                                            <td class="text-right">
                                                <a class="btn btn-sm btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal.php?id=' . $oid) ?>">Ver pedido</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
