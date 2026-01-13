<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('products.user_prices');
require_once __DIR__ . '/../../../API/config/database.php';

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->query('
        SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.phone AS user_phone,
            p.title,
            p.unit_price AS base_unit_price,
            upp.unit_price AS override_unit_price
        FROM users u
        CROSS JOIN products p
        LEFT JOIN user_product_prices upp
            ON upp.user_id = u.id AND upp.product_id = p.id
        WHERE u.is_active = 1
            AND p.is_active = 1
        ORDER BY u.name ASC, p.title ASC
    ');

    $filename = 'precos-usuarios.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Falha ao exportar.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    $delimiter = ';';
    fputcsv($output, ['Usuário', 'Produto', 'Preço padrão', 'Preço usuário'], $delimiter);

    while (($row = $stmt->fetch()) !== false) {
        $uid = (int) ($row['user_id'] ?? 0);
        $userName = trim((string) ($row['user_name'] ?? ''));
        $userPhone = (string) ($row['user_phone'] ?? '');
        $userLabel = $userName !== '' ? ($userName . ' · ' . gelo_format_phone($userPhone)) : ('Usuário #' . $uid);
        $title = (string) ($row['title'] ?? '');
        $base = (string) ($row['base_unit_price'] ?? '0.00');
        $override = $row['override_unit_price'];
        $overrideDisplay = '';
        if ($override !== null && (string) $override !== '') {
            $overrideDisplay = gelo_format_money((string) $override);
        }

        fputcsv(
            $output,
            [$userLabel, $title, gelo_format_money($base), $overrideDisplay],
            $delimiter
        );
    }

    fclose($output);
    exit;
} catch (Throwable $e) {
    gelo_flash_set('error', 'Erro ao exportar preços. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/user_product_prices.php');
}
