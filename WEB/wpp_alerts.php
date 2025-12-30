<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('whatsapp_alerts.manage');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Alertas WPP · GELO';
$activePage = 'wpp_alerts';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR phone LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$recipients = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('SELECT id, name, phone, receive_order_alerts, receive_daily_summary, is_active, created_at FROM whatsapp_alert_recipients WHERE ' . implode(' AND ', $where) . ' ORDER BY is_active DESC, name ASC LIMIT 300');
    $stmt->execute($params);
    $recipients = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar alertas. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Alertas de WhatsApp</h1>
                <p class="text-sm opacity-70 mt-1">Gerencie quem recebe alertas de pedidos e o resumo do dia.</p>
            </div>
            <a class="btn btn-primary" href="<?= gelo_e(GELO_BASE_URL . '/wpp_alert.php') ?>">Novo destinatário</a>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4"><span><?= gelo_e($success) ?></span></div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4"><span><?= gelo_e($error) ?></span></div>
        <?php endif; ?>

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-4 sm:p-6">
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/wpp_alerts.php') ?>">
                    <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                        <input class="grow" type="text" name="q" value="<?= gelo_e($q) ?>" placeholder="Buscar por nome ou telefone" />
                        <span class="opacity-60 text-sm">⌕</span>
                    </label>

                    <div class="flex gap-2">
                        <button class="btn btn-sm btn-primary" type="submit">Buscar</button>
                        <a class="btn btn-sm btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/wpp_alerts.php') ?>">Limpar</a>
                    </div>
                </form>

                <div class="overflow-x-auto mt-4">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Pedido</th>
                                <th>Resumo do dia</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recipients)): ?>
                                <tr>
                                    <td colspan="6" class="py-8 text-center opacity-70">Nenhum destinatário encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recipients as $r): ?>
                                    <?php
                                        $isActive = (int) ($r['is_active'] ?? 0) === 1;
                                        $orderOn = (int) ($r['receive_order_alerts'] ?? 0) === 1;
                                        $dailyOn = (int) ($r['receive_daily_summary'] ?? 0) === 1;
                                    ?>
                                    <tr>
                                        <td class="font-medium"><?= gelo_e((string) ($r['name'] ?? '')) ?></td>
                                        <td><?= gelo_e(gelo_format_phone((string) ($r['phone'] ?? ''))) ?></td>
                                        <td>
                                            <?php if ($orderOn): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($dailyOn): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/wpp_alert.php?id=' . (int) ($r['id'] ?? 0)) ?>">Editar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-4 text-xs opacity-70">
            Configuração da UltraMsg via env: <span class="font-mono">GELO_ULTRAMSG_INSTANCE_ID</span> e <span class="font-mono">GELO_ULTRAMSG_TOKEN</span>.
        </div>
    </main>
</body>
</html>
