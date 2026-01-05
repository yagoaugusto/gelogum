<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('products.user_prices');
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php');
}

$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$productIds = $_POST['product_id'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];

if ($userId <= 0) {
    gelo_flash_set('error', 'Usuário inválido.');
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php');
}

if (!is_array($productIds) || !is_array($unitPrices) || count($productIds) !== count($unitPrices)) {
    gelo_flash_set('error', 'Dados inválidos.');
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php?user_id=' . $userId);
}

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $userId]);
    if (!is_array($stmt->fetch())) {
        gelo_flash_set('error', 'Usuário inválido ou inativo.');
        gelo_redirect(GELO_BASE_URL . '/user_product_prices.php');
    }

    $uniqueProductIds = [];
    for ($i = 0; $i < count($productIds); $i++) {
        $pid = (int) $productIds[$i];
        if ($pid > 0) {
            $uniqueProductIds[$pid] = true;
        }
    }

    if (empty($uniqueProductIds)) {
        gelo_flash_set('error', 'Nenhum produto recebido.');
        gelo_redirect(GELO_BASE_URL . '/user_product_prices.php?user_id=' . $userId);
    }

    $placeholders = implode(',', array_fill(0, count($uniqueProductIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM products WHERE is_active = 1 AND id IN ($placeholders)");
    $stmt->execute(array_keys($uniqueProductIds));
    $validProducts = [];
    foreach ($stmt->fetchAll() as $row) {
        $validProducts[(int) ($row['id'] ?? 0)] = true;
    }

    $pdo->beginTransaction();

    $upsert = $pdo->prepare('
        INSERT INTO user_product_prices (user_id, product_id, unit_price)
        VALUES (:user_id, :product_id, :unit_price)
        ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price)
    ');

    $delete = $pdo->prepare('DELETE FROM user_product_prices WHERE user_id = :user_id AND product_id = :product_id');

    for ($i = 0; $i < count($productIds); $i++) {
        $pid = (int) $productIds[$i];
        if ($pid <= 0 || !isset($validProducts[$pid])) {
            continue;
        }

        $raw = isset($unitPrices[$i]) ? trim((string) $unitPrices[$i]) : '';
        if ($raw === '') {
            $delete->execute(['user_id' => $userId, 'product_id' => $pid]);
            continue;
        }

        $parsed = gelo_parse_money($raw);
        if ($parsed === null || (float) $parsed <= 0) {
            $pdo->rollBack();
            gelo_flash_set('error', 'Informe preços válidos (maiores que zero) ou deixe em branco para usar o padrão.');
            gelo_redirect(GELO_BASE_URL . '/user_product_prices.php?user_id=' . $userId);
        }

        $upsert->execute([
            'user_id' => $userId,
            'product_id' => $pid,
            'unit_price' => $parsed,
        ]);
    }

    $pdo->commit();

    gelo_flash_set('success', 'Preços atualizados.');
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php?user_id=' . $userId);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
        // ignore
    }

    gelo_flash_set('error', 'Erro ao salvar preços. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php?user_id=' . $userId);
}
