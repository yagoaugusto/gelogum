<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('products.access');
require_once __DIR__ . '/../API/config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');
$oldJson = gelo_flash_get('old_product');
$old = [];
if (is_string($oldJson) && $oldJson !== '') {
    $decoded = json_decode($oldJson, true);
    if (is_array($decoded)) {
        $old = $decoded;
    }
}

$product = null;
try {
    $pdo = gelo_pdo();
    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT id, title, unit_price, is_active, created_at, updated_at FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch();
        if (!is_array($product)) {
            gelo_flash_set('error', 'Produto não encontrado.');
            gelo_redirect(GELO_BASE_URL . '/products.php');
        }
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao conectar no banco. Verifique as migrações e credenciais.';
}

$values = [
    'id' => $isEdit ? $id : 0,
    'title' => $isEdit ? (string) ($product['title'] ?? '') : '',
    'unit_price' => $isEdit ? (string) ($product['unit_price'] ?? '') : '',
    'is_active' => $isEdit ? (int) ($product['is_active'] ?? 1) : 1,
];

foreach (['title', 'unit_price', 'is_active'] as $field) {
    if (array_key_exists($field, $old)) {
        $values[$field] = $old[$field];
    }
}

$unitPriceDisplay = (string) $values['unit_price'];
$unitPriceNormalized = $unitPriceDisplay !== '' ? gelo_parse_money($unitPriceDisplay) : null;
if ($unitPriceNormalized !== null) {
    $unitPriceDisplay = gelo_format_money($unitPriceNormalized);
}

$pageTitle = ($isEdit ? 'Editar produto' : 'Novo produto') . ' · GELO';
$activePage = 'products';

$meta = [
    'created_at' => $isEdit ? (string) ($product['created_at'] ?? '') : '',
    'updated_at' => $isEdit ? (string) ($product['updated_at'] ?? '') : '',
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
                <h1 class="text-2xl font-semibold tracking-tight"><?= gelo_e($isEdit ? 'Editar produto' : 'Novo produto') ?></h1>
                <p class="text-sm opacity-70 mt-1">Título, preço e status.</p>
            </div>
            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/products.php') ?>">Voltar</a>
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
                <form class="space-y-5" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/product_save.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $values['id'] ?>">

                    <label class="form-control w-full">
                        <div class="label"><span class="label-text">Título</span></div>
                        <input class="input input-bordered w-full" type="text" name="title" value="<?= gelo_e((string) $values['title']) ?>" required />
                    </label>

                    <label class="form-control w-full">
                        <div class="label"><span class="label-text">Preço unitário</span></div>
                        <input class="input input-bordered w-full" type="text" name="unit_price" inputmode="decimal" placeholder="R$ 0,00" value="<?= gelo_e($unitPriceDisplay) ?>" data-mask="money" required />
                        <div class="label">
                            <span class="label-text-alt opacity-70">Aceita vírgula ou ponto (ex.: 10,50).</span>
                        </div>
                    </label>

                    <div class="flex items-center justify-between gap-4">
                        <label class="label cursor-pointer gap-3">
                            <span class="label-text">Produto ativo</span>
                            <input class="toggle toggle-primary" type="checkbox" name="is_active" value="1" <?= ((int) $values['is_active'] === 1) ? 'checked' : '' ?> />
                        </label>
                        <?php if ($isEdit): ?>
                            <div class="text-xs opacity-70 text-right">
                                <?php if ($meta['created_at'] !== ''): ?>
                                    <div>Criado em <?= gelo_e(date('d/m/Y', strtotime($meta['created_at']))) ?></div>
                                <?php endif; ?>
                                <?php if ($meta['updated_at'] !== ''): ?>
                                    <div>Atualizado em <?= gelo_e(date('d/m/Y H:i', strtotime($meta['updated_at']))) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/products.php') ?>">Cancelar</a>
                        <button class="btn btn-outline" type="submit" name="action" value="save_new">Salvar e novo</button>
                        <button class="btn btn-primary" type="submit" name="action" value="save">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
