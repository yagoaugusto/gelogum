<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.pay']);
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Registrar pagamento · GELO';
$activePage = 'payments';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$user = gelo_current_user();
$sessionUserId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
if ($userId <= 0) {
    $userId = $sessionUserId;
}
if (!$canViewAll && $userId !== $sessionUserId) {
    gelo_flash_set('error', 'Você não tem permissão para acessar este usuário.');
    gelo_redirect(GELO_BASE_URL . '/payments.php');
}

$targetUser = null;
$openTotal = '0.00';

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

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('SELECT id, name, phone, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $targetUser = $stmt->fetch();
    if (!is_array($targetUser)) {
        gelo_flash_set('error', 'Usuário não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/payments.php');
    }

    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(
                GREATEST(
                    GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0),
                    0
                )
            ), 0) AS open_total
        FROM withdrawal_orders o
        ' . $returnsJoin . '
        ' . $paymentsJoin . '
        WHERE o.user_id = :uid
          AND o.status IN (\'saida\', \'delivered\')
    ');
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $openTotal = (string) ($row['open_total'] ?? '0.00');
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar dados. Verifique o banco e as migrações.';
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

    <main class="mx-auto max-w-3xl p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Registrar pagamento</h1>
                <p class="text-sm opacity-70 mt-1">O valor será compensado do pedido mais antigo para o mais novo.</p>
            </div>
            <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payments.php') ?>">Voltar</a>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4"><span><?= gelo_e($success) ?></span></div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4"><span><?= gelo_e($error) ?></span></div>
        <?php endif; ?>

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-4 sm:p-6">
                <div class="flex flex-col gap-2">
                    <div class="text-sm">
                        <span class="opacity-70">Cliente:</span>
                        <span class="font-medium"><?= gelo_e((string) ($targetUser['name'] ?? '')) ?></span>
                        <span class="opacity-70">·</span>
                        <span class="opacity-70"><?= gelo_e(gelo_format_phone((string) ($targetUser['phone'] ?? ''))) ?></span>
                    </div>
                    <div class="text-sm">
                        <span class="opacity-70">Em aberto:</span>
                        <span class="font-semibold"><?= gelo_e(gelo_format_money($openTotal)) ?></span>
                    </div>
                </div>

                <form class="mt-6 space-y-4" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/user_payment_create.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">

                    <label class="form-control w-full">
                        <div class="label"><span class="label-text">Tipo</span></div>
                        <select class="select select-bordered w-full" name="method" required>
                            <option value="" disabled selected>Selecione…</option>
                            <?php foreach ($methods as $key => $label): ?>
                                <option value="<?= gelo_e($key) ?>"><?= gelo_e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="form-control w-full">
                        <div class="label"><span class="label-text">Valor</span></div>
                        <input class="input input-bordered w-full" type="text" name="amount" data-mask="money" placeholder="R$ 0,00" required />
                        <div class="label"><span class="label-text-alt opacity-70">Máximo: <?= gelo_e(gelo_format_money($openTotal)) ?></span></div>
                    </label>

                    <label class="form-control w-full">
                        <div class="label"><span class="label-text">Observação (opcional)</span></div>
                        <input class="input input-bordered w-full" type="text" name="note" maxlength="255" placeholder="Ex.: comprovante, detalhes…" />
                    </label>

                    <button class="btn btn-primary w-full" type="submit">Registrar</button>
                </form>

                <div class="mt-4 text-xs opacity-70">
                    O sistema registra o pagamento e gera um extrato com os pedidos compensados.
                </div>
            </div>
        </div>
    </main>
</body>
</html>
