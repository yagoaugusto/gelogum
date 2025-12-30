<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permissions(['users.access', 'users.groups']);
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Grupos · GELO';
$activePage = 'groups';

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE (pg.name LIKE :q OR pg.description LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$groups = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare('
        SELECT
            pg.id,
            pg.name,
            pg.description,
            pg.is_active,
            COUNT(DISTINCT pgp.permission_key) AS permission_count,
            COUNT(DISTINCT u.id) AS user_count
        FROM permission_groups pg
        LEFT JOIN permission_group_permissions pgp ON pgp.group_id = pg.id
        LEFT JOIN users u ON u.permission_group_id = pg.id
        ' . $where . '
        GROUP BY pg.id, pg.name, pg.description, pg.is_active
        ORDER BY pg.name ASC
        LIMIT 200
    ');
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar grupos. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Grupos</h1>
                <p class="text-sm opacity-70 mt-1">Defina permissões e vincule aos usuários.</p>
            </div>
            <a class="btn btn-primary" href="<?= gelo_e(GELO_BASE_URL . '/group.php') ?>">Novo grupo</a>
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
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/groups.php') ?>">
                    <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                        <input class="grow" type="text" name="q" value="<?= gelo_e($q) ?>" placeholder="Buscar por nome" />
                        <span class="opacity-60 text-sm">⌕</span>
                    </label>
                    <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/groups.php') ?>">Limpar</a>
                </form>

                <div class="overflow-x-auto mt-4">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Grupo</th>
                                <th>Permissões</th>
                                <th>Usuários</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($groups)): ?>
                                <tr>
                                    <td colspan="5" class="py-8 text-center opacity-70">Nenhum grupo encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($groups as $g): ?>
                                    <?php
                                        $isActive = (int) ($g['is_active'] ?? 0) === 1;
                                        $desc = trim((string) ($g['description'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="font-medium"><?= gelo_e((string) ($g['name'] ?? '')) ?></div>
                                            <?php if ($desc !== ''): ?>
                                                <div class="text-xs opacity-70"><?= gelo_e($desc) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int) ($g['permission_count'] ?? 0) ?></td>
                                        <td><?= (int) ($g['user_count'] ?? 0) ?></td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/group.php?id=' . (int) $g['id']) ?>">Editar</a>
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

