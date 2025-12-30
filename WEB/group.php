<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permissions(['users.access', 'users.groups']);
require_once __DIR__ . '/../API/config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');
$oldJson = gelo_flash_get('old_group');
$old = [];
if (is_string($oldJson) && $oldJson !== '') {
    $decoded = json_decode($oldJson, true);
    if (is_array($decoded)) {
        $old = $decoded;
    }
}

$group = null;
$selectedPermissions = [];

try {
    $pdo = gelo_pdo();
    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT id, name, description, is_active, created_at, updated_at FROM permission_groups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $group = $stmt->fetch();
        if (!is_array($group)) {
            gelo_flash_set('error', 'Grupo não encontrado.');
            gelo_redirect(GELO_BASE_URL . '/groups.php');
        }

        $stmt = $pdo->prepare('SELECT permission_key FROM permission_group_permissions WHERE group_id = :id');
        $stmt->execute(['id' => $id]);
        foreach ($stmt->fetchAll() as $row) {
            $key = isset($row['permission_key']) ? (string) $row['permission_key'] : '';
            if ($key !== '') {
                $selectedPermissions[] = $key;
            }
        }
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar grupo. Verifique o banco e as migrações.';
}

$values = [
    'id' => $isEdit ? $id : 0,
    'name' => $isEdit && is_array($group) ? (string) ($group['name'] ?? '') : '',
    'description' => $isEdit && is_array($group) ? (string) ($group['description'] ?? '') : '',
    'is_active' => $isEdit && is_array($group) ? (int) ($group['is_active'] ?? 1) : 1,
    'permissions' => $selectedPermissions,
];

if ($old) {
    foreach (['name', 'description', 'is_active'] as $field) {
        if (array_key_exists($field, $old)) {
            $values[$field] = $old[$field];
        }
    }
    if (isset($old['permissions']) && is_array($old['permissions'])) {
        $values['permissions'] = array_values(array_unique(array_map('strval', $old['permissions'])));
    }
}

$catalog = gelo_permissions_catalog();
$allKeys = gelo_permissions_all_keys();
$permissionSet = array_fill_keys($values['permissions'], true);

$pageTitle = ($isEdit ? 'Editar grupo' : 'Novo grupo') . ' · GELO';
$activePage = 'groups';

$meta = [
    'created_at' => $isEdit && is_array($group) ? (string) ($group['created_at'] ?? '') : '',
    'updated_at' => $isEdit && is_array($group) ? (string) ($group['updated_at'] ?? '') : '',
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
                <h1 class="text-2xl font-semibold tracking-tight"><?= gelo_e($isEdit ? 'Editar grupo' : 'Novo grupo') ?></h1>
                <p class="text-sm opacity-70 mt-1">Defina o que este grupo pode acessar.</p>
            </div>
            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/groups.php') ?>">Voltar</a>
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
                <form class="space-y-6" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/group_save.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $values['id'] ?>">

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="form-control w-full sm:col-span-2">
                            <div class="label"><span class="label-text">Nome do grupo</span></div>
                            <input class="input input-bordered w-full" type="text" name="name" value="<?= gelo_e((string) $values['name']) ?>" maxlength="80" required />
                        </label>

                        <label class="form-control w-full sm:col-span-2">
                            <div class="label"><span class="label-text">Descrição</span></div>
                            <input class="input input-bordered w-full" type="text" name="description" value="<?= gelo_e((string) $values['description']) ?>" maxlength="255" placeholder="Opcional" />
                        </label>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <label class="label cursor-pointer gap-3">
                            <span class="label-text">Grupo ativo</span>
                            <input class="toggle toggle-primary" type="checkbox" name="is_active" value="1" <?= ((int) $values['is_active'] === 1) ? 'checked' : '' ?> />
                        </label>
                        <?php if ($isEdit): ?>
                            <div class="text-xs opacity-70 text-right">
                                <?php if ($meta['created_at'] !== ''): ?>
                                    <div>Criado em <?= gelo_e(date('d/m/Y H:i', strtotime($meta['created_at']))) ?></div>
                                <?php endif; ?>
                                <?php if ($meta['updated_at'] !== ''): ?>
                                    <div>Atualizado em <?= gelo_e(date('d/m/Y H:i', strtotime($meta['updated_at']))) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="divider !my-2"></div>

                    <div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-lg font-semibold">Permissões</h2>
                            <div class="flex items-center gap-2">
                                <button class="btn btn-ghost btn-sm" type="button" id="checkAll">Marcar tudo</button>
                                <button class="btn btn-ghost btn-sm" type="button" id="uncheckAll">Desmarcar tudo</button>
                            </div>
                        </div>
                        <p class="text-sm opacity-70 mt-1">Escolha exatamente o que este grupo pode fazer no sistema.</p>

                        <div class="mt-4 space-y-3">
                            <?php foreach ($catalog as $sectionTitle => $perms): ?>
                                <div class="rounded-box border border-base-200 bg-base-100 p-4">
                                    <div class="font-medium"><?= gelo_e((string) $sectionTitle) ?></div>
                                    <div class="mt-3 grid gap-3">
                                        <?php foreach ($perms as $perm): ?>
                                            <?php
                                                if (!is_array($perm)) {
                                                    continue;
                                                }
                                                $key = isset($perm['key']) ? (string) $perm['key'] : '';
                                                if ($key === '' || !in_array($key, $allKeys, true)) {
                                                    continue;
                                                }
                                                $label = isset($perm['label']) ? (string) $perm['label'] : $key;
                                                $desc = isset($perm['description']) ? trim((string) $perm['description']) : '';
                                                $checked = isset($permissionSet[$key]);
                                            ?>
                                            <label class="flex items-start justify-between gap-4 rounded-box border border-base-200 px-4 py-3">
                                                <span class="min-w-0">
                                                    <span class="block font-medium"><?= gelo_e($label) ?></span>
                                                    <?php if ($desc !== ''): ?>
                                                        <span class="block text-xs opacity-70 mt-0.5"><?= gelo_e($desc) ?></span>
                                                    <?php endif; ?>
                                                </span>
                                                <input class="checkbox checkbox-primary permission-checkbox" type="checkbox" name="permissions[]" value="<?= gelo_e($key) ?>" <?= $checked ? 'checked' : '' ?> />
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/groups.php') ?>">Cancelar</a>
                        <button class="btn btn-outline" type="submit" name="action" value="save_new">Salvar e novo</button>
                        <button class="btn btn-primary" type="submit" name="action" value="save">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
      (function () {
        const checkAll = document.getElementById('checkAll');
        const uncheckAll = document.getElementById('uncheckAll');
        const boxes = Array.from(document.querySelectorAll('.permission-checkbox'));
        if (!checkAll || !uncheckAll || boxes.length === 0) return;
        checkAll.addEventListener('click', () => boxes.forEach((b) => { b.checked = true; }));
        uncheckAll.addEventListener('click', () => boxes.forEach((b) => { b.checked = false; }));
      })();
    </script>
</body>
</html>

