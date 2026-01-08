<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('withdrawals.access');
require_once __DIR__ . '/../../../API/config/database.php';
require_once __DIR__ . '/../lib/whatsapp_ultramsg.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$csrf = $_POST['_csrf'] ?? null;
// Decide retorno (tela self/admin) mesmo em caso de CSRF inválido
$source = isset($_POST['_source']) ? (string) $_POST['_source'] : 'self';
$source = in_array($source, ['self', 'admin'], true) ? $source : 'self';
$redirectNew = $source === 'admin' ? (GELO_BASE_URL . '/withdrawal_new_admin.php') : (GELO_BASE_URL . '/withdrawal_new.php');

if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect($redirectNew);
}

$comment = isset($_POST['comment']) ? trim((string) $_POST['comment']) : '';
$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$requestedUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

function gelo_withdrawal_error(string $message, string $redirectNew, string $comment, array $items, int $userId): void
{
    gelo_flash_set('error', $message);
    gelo_flash_set('old_withdrawal', json_encode([
        'user_id' => $userId,
        'comment' => $comment,
        'items' => $items,
    ]));
    gelo_redirect($redirectNew);
}

if (!is_array($productIds) || !is_array($quantities) || count($productIds) !== count($quantities)) {
    gelo_withdrawal_error('Itens inválidos. Tente novamente.', $redirectNew, $comment, [], $requestedUserId);
}

$merged = [];
$rawItems = [];
for ($i = 0; $i < count($productIds); $i++) {
    $pid = (int) $productIds[$i];
    $qty = (int) $quantities[$i];
    if ($pid <= 0 || $qty <= 0) {
        continue;
    }
    $merged[$pid] = ($merged[$pid] ?? 0) + $qty;
}

foreach ($merged as $pid => $qty) {
    $rawItems[] = ['product_id' => $pid, 'quantity' => $qty];
}

if (empty($merged)) {
    gelo_withdrawal_error('Selecione pelo menos um produto.', $redirectNew, $comment, [], $requestedUserId);
}

$sessionUser = gelo_current_user();
$userId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;
if ($userId <= 0) {
    gelo_flash_set('error', 'Sessão inválida. Faça login novamente.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

$orderUserId = $userId;
if ($source === 'admin') {
    if (!gelo_has_permission('withdrawals.create_for_client')) {
        gelo_flash_set('error', 'Você não tem permissão para criar pedidos para outros usuários.');
        gelo_redirect($redirectNew);
    }
    if ($requestedUserId <= 0) {
        gelo_withdrawal_error('Selecione um cliente.', $redirectNew, $comment, $rawItems, 0);
    }
    $orderUserId = $requestedUserId;
}

try {
    $pdo = gelo_pdo();

    if ($source === 'admin') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $orderUserId]);
        $targetUser = $stmt->fetch();
        if (!is_array($targetUser)) {
            gelo_withdrawal_error('Cliente inválido ou inativo.', $redirectNew, $comment, $rawItems, $orderUserId);
        }
    }

    $placeholders = implode(',', array_fill(0, count($merged), '?'));
    $stmt = $pdo->prepare("SELECT id, title, unit_price, is_active FROM products WHERE id IN ($placeholders)");
    $stmt->execute(array_keys($merged));
    $rows = $stmt->fetchAll();

    $products = [];
    foreach ($rows as $r) {
        $products[(int) $r['id']] = $r;
    }

    // Preço efetivo por usuário (override). Se não existir, usa o preço genérico do produto.
    $userPrices = [];
    try {
        $pricePlaceholders = implode(',', array_fill(0, count($merged), '?'));
        $stmt = $pdo->prepare("SELECT product_id, unit_price FROM user_product_prices WHERE user_id = ? AND product_id IN ($pricePlaceholders)");
        $stmt->execute(array_merge([$orderUserId], array_keys($merged)));
        foreach ($stmt->fetchAll() as $r) {
            $pid = (int) ($r['product_id'] ?? 0);
            if ($pid > 0) {
                $userPrices[$pid] = (string) ($r['unit_price'] ?? '0.00');
            }
        }
    } catch (Throwable $ignored) {
        $userPrices = [];
    }

    foreach ($merged as $pid => $qty) {
        $row = $products[$pid] ?? null;
        if (!is_array($row)) {
            gelo_withdrawal_error('Um ou mais produtos não foram encontrados.', $redirectNew, $comment, $rawItems, $orderUserId);
        }
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            gelo_withdrawal_error('Um ou mais produtos estão inativos.', $redirectNew, $comment, $rawItems, $orderUserId);
        }
    }

    $totalItems = 0;
    $totalAmount = '0.00';
    $itemsToInsert = [];

    foreach ($merged as $pid => $qty) {
        $row = $products[$pid];
        $unitPrice = isset($userPrices[$pid]) ? (string) $userPrices[$pid] : (string) $row['unit_price'];
        $lineTotal = bcmul($unitPrice, (string) $qty, 2);

        $totalItems += $qty;
        $totalAmount = bcadd($totalAmount, $lineTotal, 2);

        $itemsToInsert[] = [
            'product_id' => $pid,
            'product_title' => (string) $row['title'],
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'line_total' => $lineTotal,
        ];
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO withdrawal_orders
            (user_id, created_by_user_id, status, comment, total_items, total_amount)
        VALUES
            (:user_id, :created_by_user_id, :status, :comment, :total_items, :total_amount)
    ');
    $stmt->execute([
        'user_id' => $orderUserId,
        'created_by_user_id' => $userId,
        'status' => 'requested',
        'comment' => $comment !== '' ? $comment : null,
        'total_items' => $totalItems,
        'total_amount' => $totalAmount,
    ]);

    $orderId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('
        INSERT INTO withdrawal_order_items
            (order_id, product_id, product_title, unit_price, quantity, line_total)
        VALUES
            (:order_id, :product_id, :product_title, :unit_price, :quantity, :line_total)
    ');

    foreach ($itemsToInsert as $it) {
        $stmt->execute([
            'order_id' => $orderId,
            'product_id' => $it['product_id'],
            'product_title' => $it['product_title'],
            'unit_price' => $it['unit_price'],
            'quantity' => $it['quantity'],
            'line_total' => $it['line_total'],
        ]);
    }

    $pdo->commit();

    // Disparo WPP (best-effort)
    try {
        gelo_whatsapp_notify_order($orderId, null, 'requested');
    } catch (Throwable $ignored) {
        // ignore
    }

    gelo_flash_set('success', 'Pedido criado.');
    gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $orderId);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
        // ignore
    }
    gelo_withdrawal_error('Erro ao criar pedido. Tente novamente.', $redirectNew, $comment, $rawItems, $orderUserId);
}
