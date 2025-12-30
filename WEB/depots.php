<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('deposits.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Depósitos · GELO';
$activePage = 'depots';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
$status = in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(title LIKE :q_title OR phone LIKE :q_phone OR address LIKE :q_address)';
    $like = '%' . $q . '%';
    $params['q_title'] = $like;
    $params['q_phone'] = $like;
    $params['q_address'] = $like;
}

if ($status === 'active') {
    $where[] = 'is_active = 1';
}
if ($status === 'inactive') {
    $where[] = 'is_active = 0';
}

$sql = 'SELECT id, title, phone, address, is_active, created_at FROM deposits';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY title ASC LIMIT 200';

$depots = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $depots = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar depósitos. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Depósitos</h1>
                <p class="text-sm opacity-70 mt-1">Cadastre locais de armazenamento e retirada.</p>
            </div>
            <a class="btn btn-primary" href="<?= gelo_e(GELO_BASE_URL . '/depot.php') ?>">Novo depósito</a>
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
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/depots.php') ?>">
                    <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                        <input class="grow" type="text" name="q" value="<?= gelo_e($q) ?>" placeholder="Buscar por título, telefone ou endereço" />
                        <span class="opacity-60 text-sm">⌕</span>
                    </label>

                    <div class="join">
                        <a class="btn btn-sm join-item <?= $status === 'all' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/depots.php?q=' . urlencode($q) . '&status=all') ?>">Todos</a>
                        <a class="btn btn-sm join-item <?= $status === 'active' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/depots.php?q=' . urlencode($q) . '&status=active') ?>">Ativos</a>
                        <a class="btn btn-sm join-item <?= $status === 'inactive' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/depots.php?q=' . urlencode($q) . '&status=inactive') ?>">Inativos</a>
                    </div>
                </form>

                <div class="overflow-x-auto mt-4">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Telefone</th>
                                <th>Endereço</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($depots)): ?>
                                <tr>
                                    <td colspan="5" class="py-8 text-center opacity-70">Nenhum depósito encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($depots as $d): ?>
                                    <?php $isActive = (int) ($d['is_active'] ?? 0) === 1; ?>
                                    <tr>
                                        <td class="font-medium"><?= gelo_e((string) ($d['title'] ?? '')) ?></td>
                                        <td><?= gelo_e(gelo_format_phone((string) ($d['phone'] ?? ''))) ?></td>
                                        <td class="max-w-md truncate"><?= gelo_e((string) ($d['address'] ?? '')) ?></td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/depot.php?id=' . (int) $d['id']) ?>">Editar</a>
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
