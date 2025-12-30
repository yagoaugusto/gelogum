<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('products.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Produtos · GELO';
$activePage = 'products';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
$status = in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(title LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if ($status === 'active') {
    $where[] = 'is_active = 1';
}
if ($status === 'inactive') {
    $where[] = 'is_active = 0';
}

$sql = 'SELECT id, title, unit_price, is_active, created_at FROM products';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY title ASC LIMIT 200';

$products = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar produtos. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Produtos</h1>
                <p class="text-sm opacity-70 mt-1">Gerencie o catálogo e preços.</p>
            </div>
            <a class="btn btn-primary" href="<?= gelo_e(GELO_BASE_URL . '/product.php') ?>">Novo produto</a>
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
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/products.php') ?>">
                    <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                        <input class="grow" type="text" name="q" value="<?= gelo_e($q) ?>" placeholder="Buscar por título" />
                        <span class="opacity-60 text-sm">⌕</span>
                    </label>

                    <div class="join">
                        <a class="btn btn-sm join-item <?= $status === 'all' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/products.php?q=' . urlencode($q) . '&status=all') ?>">Todos</a>
                        <a class="btn btn-sm join-item <?= $status === 'active' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/products.php?q=' . urlencode($q) . '&status=active') ?>">Ativos</a>
                        <a class="btn btn-sm join-item <?= $status === 'inactive' ? 'btn-active' : '' ?>" href="<?= gelo_e(GELO_BASE_URL . '/products.php?q=' . urlencode($q) . '&status=inactive') ?>">Inativos</a>
                    </div>
                </form>

                <div class="overflow-x-auto mt-4">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Preço unitário</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="4" class="py-8 text-center opacity-70">Nenhum produto encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                    <?php $isActive = (int) ($p['is_active'] ?? 0) === 1; ?>
                                    <tr>
                                        <td class="font-medium"><?= gelo_e((string) ($p['title'] ?? '')) ?></td>
                                        <td><?= gelo_e(gelo_format_money($p['unit_price'] ?? 0)) ?></td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="badge badge-success badge-outline">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/product.php?id=' . (int) $p['id']) ?>">Editar</a>
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
