<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
gelo_require_permission('whatsapp_alerts.manage');
require_once __DIR__ . '/../../../API/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gelo_redirect(GELO_BASE_URL . '/wpp_alerts.php');
}

$csrf = $_POST['_csrf'] ?? null;
if (!gelo_csrf_validate(is_string($csrf) ? $csrf : null)) {
    gelo_flash_set('error', 'Sessão expirada. Tente novamente.');
    gelo_redirect(GELO_BASE_URL . '/wpp_alerts.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isEdit = $id > 0;
$redirectTo = GELO_BASE_URL . '/wpp_alert.php' . ($isEdit ? ('?id=' . $id) : '');

$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$phoneRaw = isset($_POST['phone']) ? (string) $_POST['phone'] : '';
$phone = gelo_phone_normalize_e164($phoneRaw, '55') ?? '';
$receiveOrder = isset($_POST['receive_order_alerts']) ? 1 : 0;
$receiveDaily = isset($_POST['receive_daily_summary']) ? 1 : 0;
$isActive = isset($_POST['is_active']) ? 1 : 0;

$old = json_encode([
    'name' => $name,
    'phone' => $phoneRaw,
    'receive_order_alerts' => $receiveOrder,
    'receive_daily_summary' => $receiveDaily,
    'is_active' => $isActive,
]);

function gelo_wpp_alert_save_error(string $message, string $redirectTo, ?string $old): void
{
    gelo_flash_set('error', $message);
    if (is_string($old) && $old !== '') {
        gelo_flash_set('old_wpp_alert', $old);
    }
    gelo_redirect($redirectTo);
}

$nameLen = function_exists('mb_strlen') ? (int) mb_strlen($name, 'UTF-8') : strlen($name);
if ($name === '' || $nameLen < 2) {
    gelo_wpp_alert_save_error('Informe um nome válido.', $redirectTo, $old);
}

if ($phone === '') {
    gelo_wpp_alert_save_error('Informe um telefone válido. Para números internacionais, use o formato +DDI (ex: +1..., +351...).', $redirectTo, $old);
}

try {
    $pdo = gelo_pdo();

    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT id FROM whatsapp_alert_recipients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        if (!is_array($stmt->fetch())) {
            gelo_wpp_alert_save_error('Destinatário não encontrado.', GELO_BASE_URL . '/wpp_alerts.php', null);
        }
    }

    if (!$isEdit) {
        $stmt = $pdo->prepare('
            INSERT INTO whatsapp_alert_recipients
                (name, phone, receive_order_alerts, receive_daily_summary, is_active)
            VALUES
                (:name, :phone, :ro, :rd, :ia)
        ');
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'ro' => $receiveOrder,
            'rd' => $receiveDaily,
            'ia' => $isActive,
        ]);

        $newId = (int) $pdo->lastInsertId();
        gelo_flash_set('success', 'Destinatário criado.');
        gelo_redirect(GELO_BASE_URL . '/wpp_alert.php?id=' . $newId);
    }

    $stmt = $pdo->prepare('
        UPDATE whatsapp_alert_recipients
        SET name = :name, phone = :phone, receive_order_alerts = :ro, receive_daily_summary = :rd, is_active = :ia
        WHERE id = :id
    ');
    $stmt->execute([
        'name' => $name,
        'phone' => $phone,
        'ro' => $receiveOrder,
        'rd' => $receiveDaily,
        'ia' => $isActive,
        'id' => $id,
    ]);

    gelo_flash_set('success', 'Destinatário atualizado.');
    gelo_redirect($redirectTo);
} catch (Throwable $e) {
    gelo_wpp_alert_save_error('Erro ao salvar destinatário. Tente novamente.', $redirectTo, $old);
}
