<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('products.access');
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/products.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/products.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isEdit = $id > 0;
$redirectTo = GELO_BASE_URL . '/product.php' . ($isEdit ? ('?id=' . $id) : '');

$title = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
$unitPriceInput = isset($_POST['unit_price']) ? (string) $_POST['unit_price'] : '';
$isActive = isset($_POST['is_active']) ? 1 : 0;
$action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';
$action = in_array($action, ['save', 'save_new'], true) ? $action : 'save';

$old = json_encode([
    'title' => $title,
    'unit_price' => $unitPriceInput,
    'is_active' => $isActive,
]);

function gelo_product_save_error(string $message, string $redirectTo, ?string $old): void
{
    gelo_flash_set('error', $message);
    if (is_string($old) && $old !== '') {
        gelo_flash_set('old_product', $old);
    }
    gelo_redirect($redirectTo);
}

$titleLen = function_exists('mb_strlen') ? (int) mb_strlen($title, 'UTF-8') : strlen($title);
if ($title === '' || $titleLen < 2) {
    gelo_product_save_error('Informe um título válido.', $redirectTo, $old);
}

$unitPrice = gelo_parse_money($unitPriceInput);
if ($unitPrice === null) {
    gelo_product_save_error('Informe um preço válido.', $redirectTo, $old);
}

if ((float) $unitPrice <= 0) {
    gelo_product_save_error('O preço deve ser maior que zero.', $redirectTo, $old);
}

try {
    $pdo = gelo_pdo();

    if ($isEdit) {
        $existsStmt = $pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
        $existsStmt->execute(['id' => $id]);
        $exists = $existsStmt->fetch();
        if (!is_array($exists)) {
            gelo_product_save_error('Produto não encontrado.', GELO_BASE_URL . '/products.php', null);
        }
    }

    if (!$isEdit) {
        $stmt = $pdo->prepare('INSERT INTO products (title, unit_price, is_active) VALUES (:title, :unit_price, :is_active)');
        $stmt->execute([
            'title' => $title,
            'unit_price' => $unitPrice,
            'is_active' => $isActive,
        ]);

        $newId = (int) $pdo->lastInsertId();
        gelo_flash_set('success', 'Produto criado.');
        if ($action === 'save_new') {
            gelo_redirect(GELO_BASE_URL . '/product.php');
        }
        gelo_redirect(GELO_BASE_URL . '/product.php?id=' . $newId);
    }

    $stmt = $pdo->prepare('UPDATE products SET title = :title, unit_price = :unit_price, is_active = :is_active WHERE id = :id');
    $stmt->execute([
        'title' => $title,
        'unit_price' => $unitPrice,
        'is_active' => $isActive,
        'id' => $id,
    ]);

    gelo_flash_set('success', 'Produto atualizado.');
    if ($action === 'save_new') {
        gelo_redirect(GELO_BASE_URL . '/product.php');
    }
    gelo_redirect($redirectTo);
} catch (Throwable $e) {
    gelo_product_save_error('Erro ao salvar produto. Tente novamente.', $redirectTo, $old);
}
