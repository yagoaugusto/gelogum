<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.pay']);
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Histórico de pagamentos · GELO';
$activePage = 'payments';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$user = gelo_current_user();
$sessionUserId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
if ($userId <= 0 || !$canViewAll) {
    $userId = $sessionUserId;
}

$targetUser = null;
$payments = [];

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('SELECT id, name, phone FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $targetUser = $stmt->fetch();
    if (!is_array($targetUser)) {
        gelo_flash_set('error', 'Usuário não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }

    $stmt = $pdo->prepare('
        SELECT
            up.id,
            up.amount,
            up.method,
            up.paid_at,
            up.note,
            up.open_before,
            up.open_after,
            cu.name AS created_by_name
        FROM user_payments up
        INNER JOIN users cu ON cu.id = up.created_by_user_id
        WHERE up.user_id = :uid
        ORDER BY up.paid_at DESC, up.id DESC
        LIMIT 200
    ');
    $stmt->execute(['uid' => $userId]);
    $payments = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar histórico. Verifique o banco e as migrações.';
}

$methods = gelo_withdrawal_payment_methods();
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
                <h1 class="text-2xl font-semibold tracking-tight">Histórico de pagamentos</h1>
                <p class="text-sm opacity-70 mt-1">
                    <?= $canViewAll ? 'Cliente: ' . gelo_e((string) ($targetUser['name'] ?? '')) : 'Veja seus pagamentos registrados.' ?>
                </p>
            </div>
            <div class="flex gap-2">
                <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payments.php') ?>">Voltar</a>
                <?php if ($canViewAll): ?>
                    <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payment_new.php?user_id=' . (int) $userId) ?>">Registrar pagamento</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4"><span><?= gelo_e($success) ?></span></div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4"><span><?= gelo_e($error) ?></span></div>
        <?php endif; ?>

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-4 sm:p-6">
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th class="text-right">Valor</th>
                                <th class="text-right">Em aberto (antes)</th>
                                <th class="text-right">Em aberto (depois)</th>
                                <th>Registrado por</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="7" class="py-8 text-center opacity-70">Nenhum pagamento registrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $p): ?>
                                    <?php
                                        $pid = (int) ($p['id'] ?? 0);
                                        $paidAt = (string) ($p['paid_at'] ?? '');
                                        $paidLabel = $paidAt !== '' ? date('d/m/Y H:i', strtotime($paidAt)) : '';
                                        $method = (string) ($p['method'] ?? '');
                                        $methodLabel = $methods[$method] ?? $method;
                                    ?>
                                    <tr>
                                        <td><?= gelo_e($paidLabel) ?></td>
                                        <td><span class="badge badge-outline"><?= gelo_e($methodLabel) ?></span></td>
                                        <td class="text-right font-semibold"><?= gelo_e(gelo_format_money($p['amount'] ?? 0)) ?></td>
                                        <td class="text-right"><?= gelo_e(gelo_format_money($p['open_before'] ?? 0)) ?></td>
                                        <td class="text-right"><?= gelo_e(gelo_format_money($p['open_after'] ?? 0)) ?></td>
                                        <td><?= gelo_e((string) ($p['created_by_name'] ?? '')) ?></td>
                                        <td class="text-right">
                                            <a class="btn btn-sm btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payment_receipt.php?id=' . $pid) ?>">Extrato</a>
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
