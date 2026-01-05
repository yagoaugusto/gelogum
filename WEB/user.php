<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('users.access');
require_once __DIR__ . '/../API/config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');
$oldJson = gelo_flash_get('old_user');
$old = [];
if (is_string($oldJson) && $oldJson !== '') {
    $decoded = json_decode($oldJson, true);
    if (is_array($decoded)) {
        $old = $decoded;
    }
}

$user = null;
$groups = [];
$defaultUserGroupId = 0;
$canManageGroups = gelo_has_permission('users.groups');
try {
    $pdo = gelo_pdo();
    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT id, name, phone, birthday, is_active, role, permission_group_id, last_login_at, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        if (!is_array($user)) {
            gelo_flash_set('error', 'Usuário não encontrado.');
            gelo_redirect(GELO_BASE_URL . '/users.php');
        }
    }

    $groups = $pdo->query('SELECT id, name, is_active FROM permission_groups ORDER BY name ASC')->fetchAll();
    foreach ($groups as $g) {
        if ((string) ($g['name'] ?? '') === 'Usuário') {
            $defaultUserGroupId = (int) ($g['id'] ?? 0);
            break;
        }
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao conectar no banco. Verifique a migração e credenciais.';
}

$values = [
    'id' => $isEdit ? $id : 0,
    'name' => $isEdit ? (string) ($user['name'] ?? '') : '',
    'phone' => $isEdit ? (string) ($user['phone'] ?? '') : '',
    'birthday' => $isEdit ? (string) ($user['birthday'] ?? '') : '',
    'is_active' => $isEdit ? (int) ($user['is_active'] ?? 1) : 1,
    'permission_group_id' => $isEdit ? (int) ($user['permission_group_id'] ?? 0) : $defaultUserGroupId,
];

foreach (['name', 'phone', 'birthday', 'is_active', 'permission_group_id'] as $field) {
    if (array_key_exists($field, $old)) {
        $values[$field] = $old[$field];
    }
}

$pageTitle = ($isEdit ? 'Editar usuário' : 'Novo usuário') . ' · GELO';
$activePage = 'users';

$meta = [
    'created_at' => $isEdit ? (string) ($user['created_at'] ?? '') : '',
    'last_login_at' => $isEdit ? (string) ($user['last_login_at'] ?? '') : '',
];
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
                <h1 class="text-2xl font-semibold tracking-tight"><?= gelo_e($isEdit ? 'Editar usuário' : 'Novo usuário') ?></h1>
                <p class="text-sm opacity-70 mt-1">Nome, telefone, aniversário, status e senha.</p>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($isEdit && gelo_has_permission('products.user_prices')): ?>
                    <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/user_product_prices.php?user_id=' . (int) $id) ?>">Preços</a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/users.php') ?>">Voltar</a>
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

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-6 sm:p-8">
                <form class="space-y-5" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/user_save.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $values['id'] ?>">

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="form-control w-full sm:col-span-2">
                            <div class="label"><span class="label-text">Nome</span></div>
                            <input class="input input-bordered w-full" type="text" name="name" value="<?= gelo_e((string) $values['name']) ?>" autocomplete="name" required />
                        </label>

                        <label class="form-control w-full">
                            <div class="label"><span class="label-text">Telefone</span></div>
                            <input class="input input-bordered w-full" type="tel" name="phone" value="<?= gelo_e(gelo_format_phone((string) $values['phone'])) ?>" autocomplete="tel" placeholder="+5511999999999" required />
                            <div class="label py-0"><span class="label-text-alt opacity-70">Brasil: pode informar só DDD+número (ex: (11) 99999-9999). Internacional: use +DDI (ex: +1..., +351...).</span></div>
                        </label>

                        <label class="form-control w-full">
                            <div class="label"><span class="label-text">Data de aniversário</span></div>
                            <input class="input input-bordered w-full" type="date" name="birthday" value="<?= gelo_e((string) $values['birthday']) ?>" />
                        </label>

                        <label class="form-control w-full sm:col-span-2">
                            <div class="label">
                                <span class="label-text">Grupo de permissões</span>
                                <?php if (!$canManageGroups): ?>
                                    <span class="label-text-alt opacity-70">somente leitura</span>
                                <?php endif; ?>
                            </div>
                            <select class="select select-bordered w-full" name="permission_group_id" <?= $canManageGroups ? '' : 'disabled' ?>>
                                <option value="">Selecione…</option>
                                <?php foreach ($groups as $g): ?>
                                    <?php
                                        $gid = (int) ($g['id'] ?? 0);
                                        $gname = (string) ($g['name'] ?? '');
                                        $gActive = (int) ($g['is_active'] ?? 0) === 1;
                                        $label = $gname . ($gActive ? '' : ' (Inativo)');
                                        $selected = (int) $values['permission_group_id'] === $gid;
                                    ?>
                                    <option value="<?= $gid ?>" <?= $selected ? 'selected' : '' ?>><?= gelo_e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$canManageGroups && (int) $values['permission_group_id'] > 0): ?>
                                <input type="hidden" name="permission_group_id" value="<?= (int) $values['permission_group_id'] ?>">
                            <?php endif; ?>
                        </label>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <label class="label cursor-pointer gap-3">
                            <span class="label-text">Usuário ativo</span>
                            <input class="toggle toggle-primary" type="checkbox" name="is_active" value="1" <?= ((int) $values['is_active'] === 1) ? 'checked' : '' ?> />
                        </label>
                        <?php if ($isEdit): ?>
                            <div class="text-xs opacity-70 text-right">
                                <?php if ($meta['created_at'] !== ''): ?>
                                    <div>Criado em <?= gelo_e(date('d/m/Y', strtotime($meta['created_at']))) ?></div>
                                <?php endif; ?>
                                <?php if ($meta['last_login_at'] !== ''): ?>
                                    <div>Último login em <?= gelo_e(date('d/m/Y H:i', strtotime($meta['last_login_at']))) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="divider !my-2"></div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-lg font-semibold">Senha</h2>
                            <?php if ($isEdit): ?>
                                <span class="badge badge-outline">opcional</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm opacity-70 mt-1">
                            <?= $isEdit ? 'Preencha para definir uma nova senha para este usuário.' : 'Defina uma senha inicial para o usuário.' ?>
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2 mt-4">
                            <label class="form-control w-full">
                                <div class="label"><span class="label-text"><?= $isEdit ? 'Nova senha' : 'Senha' ?></span></div>
                                <input class="input input-bordered w-full" type="password" name="password" minlength="8" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> />
                            </label>
                            <label class="form-control w-full">
                                <div class="label"><span class="label-text">Confirmar senha</span></div>
                                <input class="input input-bordered w-full" type="password" name="password_confirm" minlength="8" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> />
                            </label>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/users.php') ?>">Cancelar</a>
                        <button class="btn btn-outline" type="submit" name="action" value="save_new">Salvar e novo</button>
                        <button class="btn btn-primary" type="submit" name="action" value="save">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
