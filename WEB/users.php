<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('users.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Usuários · GELO';
$activePage = 'users';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
$status = in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(u.name LIKE :q_name OR u.phone LIKE :q_phone)';
    $like = '%' . $q . '%';
    $params['q_name'] = $like;
    $params['q_phone'] = $like;
}

if ($status === 'active') {
    $where[] = 'u.is_active = 1';
}
if ($status === 'inactive') {
    $where[] = 'u.is_active = 0';
}

$sql = '
    SELECT
        u.id,
        u.name,
        u.phone,
        u.birthday,
        u.is_active,
        u.created_at,
        pg.name AS group_name
    FROM users u
    LEFT JOIN permission_groups pg ON pg.id = u.permission_group_id
';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.name ASC LIMIT 200';

$users = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar usuários. Verifique o banco e a migração.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Usuários</h1>
                <p class="text-sm opacity-70 mt-1">Cadastre e gerencie acessos.</p>
            </div>
            <a class="btn btn-primary" href="<?= gelo_e(GELO_BASE_URL . '/user.php') ?>">Novo usuário</a>
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
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/users.php') ?>">
                    <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                        <input class="grow" type="text" name="q" value="<?= gelo_e($q) ?>" placeholder="Buscar por nome ou telefone" />
                        <span class="opacity-60 text-sm">⌕</span>
                    </label>

                    <div class="join">
                        <a class="btn btn-sm join-item <?= $status === 'all' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/users.php?q=' . urlencode($q) . '&status=all') ?>">Todos</a>
                        <a class="btn btn-sm join-item <?= $status === 'active' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/users.php?q=' . urlencode($q) . '&status=active') ?>">Ativos</a>
                        <a class="btn btn-sm join-item <?= $status === 'inactive' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/users.php?q=' . urlencode($q) . '&status=inactive') ?>">Inativos</a>
                    </div>
                </form>

                <div class="overflow-x-auto mt-4">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Grupo</th>
                                <th>Aniversário</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6" class="py-8 text-center opacity-70">Nenhum usuário encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <?php
                                        $birthday = isset($u['birthday']) ? (string) $u['birthday'] : '';
                                        $birthdayLabel = $birthday !== '' ? date('d/m/Y', strtotime($birthday)) : '—';
                                        $isActive = (int) ($u['is_active'] ?? 0) === 1;
                                        $groupName = trim((string) ($u['group_name'] ?? ''));
                                        $groupLabel = $groupName !== '' ? $groupName : '—';
                                    ?>
                                    <tr>
                                        <td class="font-medium"><?= gelo_e((string) ($u['name'] ?? '')) ?></td>
                                        <td><?= gelo_e(gelo_format_phone((string) ($u['phone'] ?? ''))) ?></td>
                                        <td class="text-sm opacity-80"><?= gelo_e($groupLabel) ?></td>
                                        <td><?= gelo_e($birthdayLabel) ?></td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/user.php?id=' . (int) $u['id']) ?>">Editar</a>
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
