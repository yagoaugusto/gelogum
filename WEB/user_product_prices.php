<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('products.user_prices');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Preços por usuário · Produtos';
$activePage = 'user_product_prices';

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

$users = [];
$products = [];

try {
    $pdo = gelo_pdo();
    $users = $pdo->query('SELECT id, name, phone FROM users WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll();

    if ($selectedUserId > 0) {
        $stmt = $pdo->prepare('
            SELECT
                p.id,
                p.title,
                p.unit_price AS base_unit_price,
                upp.unit_price AS override_unit_price
            FROM products p
            LEFT JOIN user_product_prices upp
                ON upp.product_id = p.id AND upp.user_id = :user_id
            WHERE p.is_active = 1
            ORDER BY p.title ASC
        ');
        $stmt->execute(['user_id' => $selectedUserId]);
        $products = $stmt->fetchAll();

        $check = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $check->execute(['id' => $selectedUserId]);
        if (!is_array($check->fetch())) {
            $selectedUserId = 0;
            $products = [];
            $error = $error ?? 'Selecione um usuário válido e ativo.';
        }
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar dados. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Preços por usuário</h1>
                <p class="text-sm opacity-70 mt-1">Defina valores por usuário. Se ficar em branco, o sistema usa o preço padrão do produto.</p>
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
            <div class="card-body p-4 sm:p-6">
                <form class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" method="get" action="<?= gelo_e(GELO_BASE_URL . '/user_product_prices.php') ?>">
                    <label class="form-control w-full sm:max-w-lg">
                        <div class="label"><span class="label-text">Usuário</span></div>
                        <select class="select select-bordered w-full" name="user_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($users as $u): ?>
                                <?php
                                    $uid = (int) ($u['id'] ?? 0);
                                    $name = trim((string) ($u['name'] ?? ''));
                                    $phone = (string) ($u['phone'] ?? '');
                                    $label = $name !== '' ? ($name . ' · ' . gelo_format_phone($phone)) : ('Usuário #' . $uid);
                                    $selected = $selectedUserId > 0 && $selectedUserId === $uid;
                                ?>
                                <option value="<?= $uid ?>" <?= $selected ? 'selected' : '' ?>><?= gelo_e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="flex gap-2">
                        <button class="btn btn-primary" type="submit">Carregar</button>
                        <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/user_product_prices.php') ?>">Limpar</a>
                    </div>
                </form>

                <?php if ($selectedUserId > 0): ?>
                    <form class="mt-6" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/user_product_prices_save.php') ?>">
                        <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">

                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>Produto</th>
                                        <th class="text-right">Preço padrão</th>
                                        <th class="text-right">Preço do usuário</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                        <tr>
                                            <td colspan="3" class="py-8 text-center opacity-70">Nenhum produto encontrado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $p): ?>
                                            <?php
                                                $pid = (int) ($p['id'] ?? 0);
                                                $title = (string) ($p['title'] ?? '');
                                                $base = (string) ($p['base_unit_price'] ?? '0.00');
                                                $override = $p['override_unit_price'];
                                                $overrideDisplay = '';
                                                if ($override !== null && (string) $override !== '') {
                                                    $overrideDisplay = gelo_format_money((string) $override);
                                                }
                                            ?>
                                            <tr>
                                                <td class="font-medium">
                                                    <?= gelo_e($title) ?>
                                                    <input type="hidden" name="product_id[]" value="<?= $pid ?>">
                                                </td>
                                                <td class="text-right"><?= gelo_e(gelo_format_money($base)) ?></td>
                                                <td class="text-right">
                                                    <input
                                                        class="input input-bordered input-sm w-40 text-right"
                                                        type="text"
                                                        name="unit_price[]"
                                                        value="<?= gelo_e($overrideDisplay) ?>"
                                                        placeholder="Usar padrão"
                                                        inputmode="decimal"
                                                        data-mask="money"
                                                    />
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end mt-6">
                            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/user_product_prices.php?user_id=' . (int) $selectedUserId) ?>">Cancelar</a>
                            <button class="btn btn-primary" type="submit">Salvar preços</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert mt-6">
                        <span>Selecione um usuário para editar os preços.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
