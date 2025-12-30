<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('whatsapp_alerts.manage');
require_once __DIR__ . '/../API/config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');
$oldJson = gelo_flash_get('old_wpp_alert');
$old = [];
if (is_string($oldJson) && $oldJson !== '') {
    $decoded = json_decode($oldJson, true);
    if (is_array($decoded)) {
        $old = $decoded;
    }
}

$row = null;
try {
    $pdo = gelo_pdo();
    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT id, name, phone, receive_order_alerts, receive_daily_summary, is_active, created_at FROM whatsapp_alert_recipients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            gelo_flash_set('error', 'Destinatário não encontrado.');
            gelo_redirect(GELO_BASE_URL . '/wpp_alerts.php');
        }
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao conectar no banco. Verifique a migração e credenciais.';
}

$values = [
    'id' => $isEdit ? $id : 0,
    'name' => $isEdit ? (string) ($row['name'] ?? '') : '',
    'phone' => $isEdit ? (string) ($row['phone'] ?? '') : '',
    'receive_order_alerts' => $isEdit ? (int) ($row['receive_order_alerts'] ?? 0) : 1,
    'receive_daily_summary' => $isEdit ? (int) ($row['receive_daily_summary'] ?? 0) : 0,
    'is_active' => $isEdit ? (int) ($row['is_active'] ?? 1) : 1,
];

foreach (['name', 'phone', 'receive_order_alerts', 'receive_daily_summary', 'is_active'] as $field) {
    if (array_key_exists($field, $old)) {
        $values[$field] = $old[$field];
    }
}

$pageTitle = ($isEdit ? 'Editar destinatário' : 'Novo destinatário') . ' · GELO';
$activePage = 'wpp_alerts';
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto max-w-3xl p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight"><?= gelo_e($isEdit ? 'Editar destinatário' : 'Novo destinatário') ?></h1>
                <p class="text-sm opacity-70 mt-1">Defina quais alertas serão enviados via WhatsApp.</p>
            </div>
            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/wpp_alerts.php') ?>">Voltar</a>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4"><span><?= gelo_e($success) ?></span></div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4"><span><?= gelo_e($error) ?></span></div>
        <?php endif; ?>

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-6 sm:p-8">
                <form class="space-y-5" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/wpp_alert_save.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $values['id'] ?>">

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="form-control w-full sm:col-span-2">
                            <div class="label"><span class="label-text">Nome</span></div>
                            <input class="input input-bordered w-full" type="text" name="name" value="<?= gelo_e((string) $values['name']) ?>" required />
                        </label>

                        <label class="form-control w-full sm:col-span-2">
                            <div class="label"><span class="label-text">Telefone</span></div>
                            <input class="input input-bordered w-full" type="tel" name="phone" value="<?= gelo_e(gelo_format_phone((string) $values['phone'])) ?>" placeholder="+5511999999999" required />
                            <div class="label py-0"><span class="label-text-alt opacity-70">Brasil: pode informar só DDD+número. Internacional: use +DDI (ex: +1..., +351...).</span></div>
                        </label>

                        <label class="label cursor-pointer gap-3 sm:col-span-2 justify-start">
                            <input class="toggle toggle-primary" type="checkbox" name="receive_order_alerts" value="1" <?= ((int) $values['receive_order_alerts'] === 1) ? 'checked' : '' ?> />
                            <span class="label-text">Receber disparo de pedido (criação e mudança de status)</span>
                        </label>

                        <label class="label cursor-pointer gap-3 sm:col-span-2 justify-start">
                            <input class="toggle toggle-primary" type="checkbox" name="receive_daily_summary" value="1" <?= ((int) $values['receive_daily_summary'] === 1) ? 'checked' : '' ?> />
                            <span class="label-text">Receber resumo do dia</span>
                        </label>

                        <div class="divider sm:col-span-2 !my-1"></div>

                        <label class="label cursor-pointer gap-3 sm:col-span-2 justify-between">
                            <span class="label-text">Destinatário ativo</span>
                            <input class="toggle toggle-primary" type="checkbox" name="is_active" value="1" <?= ((int) $values['is_active'] === 1) ? 'checked' : '' ?> />
                        </label>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/wpp_alerts.php') ?>">Cancelar</a>
                        <button class="btn btn-primary" type="submit">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
