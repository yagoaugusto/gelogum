<?php
declare(strict_types=1);

/**
 * Integração WhatsApp via UltraMsg.
 * Configuração via variáveis de ambiente:
 * - GELO_ULTRAMSG_INSTANCE_ID
 * - GELO_ULTRAMSG_TOKEN
 */

function gelo_whatsapp_normalize_to(?string $phone): ?string
{
    if (!is_string($phone)) {
        return null;
    }

    $raw = trim($phone);
    if ($raw === '') {
        return null;
    }

    // Reaproveita normalização global (E.164) quando disponível.
    if (function_exists('gelo_phone_normalize_e164')) {
        $normalized = gelo_phone_normalize_e164($raw, '55');
        if (is_string($normalized) && $normalized !== '') {
            return $normalized;
        }
    }

    // Se já vier no formato chatId (ex: 1415...@c.us / ...@g.us), mantém.
    if (strpos($raw, '@c.us') !== false || strpos($raw, '@g.us') !== false) {
        return $raw;
    }

    $digits = function_exists('gelo_digits') ? gelo_digits($raw) : preg_replace('/\D+/', '', $raw);
    if (!is_string($digits)) {
        $digits = '';
    }

    if ($digits === '') {
        return null;
    }

    $explicitIntl = (isset($raw[0]) && $raw[0] === '+') || strncmp($raw, '00', 2) === 0;
    if (strncmp($raw, '00', 2) === 0 && strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
    }

    // Default Brasil apenas quando não há DDI explícito.
    if (!$explicitIntl && (strlen($digits) === 10 || strlen($digits) === 11) && strncmp($digits, '55', 2) !== 0) {
        $digits = '55' . $digits;
    }

    return '+' . $digits;
}

function gelo_ultramsg_send_text(string $to, string $body): array
{
    $instanceId = '';
    $token = '';

    // Prioriza config por arquivo (WEB/app/config/ultramsg.php)
    if (function_exists('gelo_config')) {
        $instanceId = (string) gelo_config('ultramsg.instance_id', '');
        $token = (string) gelo_config('ultramsg.token', '');
    }

    // Fallback para env vars
    if (trim($instanceId) === '' && function_exists('gelo_env')) {
        $instanceId = (string) gelo_env('GELO_ULTRAMSG_INSTANCE_ID', '');
    }
    if (trim($token) === '' && function_exists('gelo_env')) {
        $token = (string) gelo_env('GELO_ULTRAMSG_TOKEN', '');
    }

    if (trim($instanceId) === '' || trim($token) === '') {
        return [
            'ok' => false,
            'error' => 'UltraMsg não configurado. Preencha WEB/app/config/ultramsg.php (instance_id/token) ou defina GELO_ULTRAMSG_INSTANCE_ID e GELO_ULTRAMSG_TOKEN.',
        ];
    }

    $url = 'https://api.ultramsg.com/' . rawurlencode($instanceId) . '/messages/chat';
    $payload = [
        'token' => $token,
        'to' => $to,
        'body' => $body,
    ];

    $responseBody = '';
    $httpCode = 0;
    $curlError = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $responseBody = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
                'timeout' => 20,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $responseBody = is_string($result) ? $result : '';
        $httpCode = 200;
    }

    $decoded = null;
    if ($responseBody !== '') {
        $tmp = json_decode($responseBody, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    if ($curlError !== null) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => $curlError,
            'response' => $decoded ?? $responseBody,
        ];
    }

    // Considera sucesso por HTTP 2xx; UltraMsg costuma responder JSON com campos.
    $ok = ($httpCode >= 200 && $httpCode < 300);
    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'response' => $decoded ?? $responseBody,
    ];
}

function gelo_whatsapp_status_label(string $status): string
{
    switch ($status) {
        case 'requested':
            return 'Solicitado';
        case 'saida':
            return 'Saída';
        // Compatibilidade com status antigos (antes da migração)
        case 'separated':
            return 'Solicitado';
        case 'delivered':
            return 'Saída';
        case 'cancelled':
            return 'Cancelado';
        default:
            return $status;
    }
}

function gelo_whatsapp_get_recipients(PDO $pdo, string $type): array
{
    $type = trim($type);
    $field = null;
    if ($type === 'order') {
        $field = 'receive_order_alerts';
    } elseif ($type === 'daily') {
        $field = 'receive_daily_summary';
    }

    if ($field === null) {
        return [];
    }

    $stmt = $pdo->query('SELECT id, name, phone FROM whatsapp_alert_recipients WHERE is_active = 1 AND ' . $field . ' = 1 ORDER BY name ASC');
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $phone = (string) ($r['phone'] ?? '');
        $to = gelo_whatsapp_normalize_to($phone);
        if ($to === null) {
            continue;
        }
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name'] ?? ''),
            'phone' => $phone,
            'to' => $to,
        ];
    }

    return $out;
}

function gelo_whatsapp_log(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare('
        INSERT INTO whatsapp_message_logs
            (message_type, order_id, target_user_id, recipient_phone, body, api_response, is_success)
        VALUES
            (:message_type, :order_id, :target_user_id, :recipient_phone, :body, :api_response, :is_success)
    ');

    $stmt->execute([
        'message_type' => (string) ($data['message_type'] ?? ''),
        'order_id' => $data['order_id'] ?? null,
        'target_user_id' => $data['target_user_id'] ?? null,
        'recipient_phone' => (string) ($data['recipient_phone'] ?? ''),
        'body' => (string) ($data['body'] ?? ''),
        'api_response' => isset($data['api_response']) ? (string) $data['api_response'] : null,
        'is_success' => (int) (($data['is_success'] ?? false) ? 1 : 0),
    ]);
}

function gelo_whatsapp_notify_order(int $orderId, ?string $oldStatus, string $newStatus): void
{
    if ($orderId <= 0 || $newStatus === '') {
        return;
    }

    try {
        $pdo = gelo_pdo();

        $stmt = $pdo->prepare('
            SELECT
                o.id,
                o.user_id,
                o.status,
                o.total_items,
                o.total_amount,
                o.comment,
                o.cancellation_reason,
                u.name AS user_name,
                u.phone AS user_phone
            FROM withdrawal_orders o
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }

        $userPhone = (string) ($row['user_phone'] ?? '');
        $userTo = gelo_whatsapp_normalize_to($userPhone);

        $statusLabel = gelo_whatsapp_status_label($newStatus);
        $oldLabel = is_string($oldStatus) && $oldStatus !== '' ? gelo_whatsapp_status_label($oldStatus) : null;

        $itemsList = [];
        $itemsStmt = $pdo->prepare('
            SELECT product_title, quantity
            FROM withdrawal_order_items
            WHERE order_id = :id
            ORDER BY id ASC
        ');
        $itemsStmt->execute(['id' => $orderId]);
        $itemsRows = $itemsStmt->fetchAll();
        if (is_array($itemsRows)) {
            foreach ($itemsRows as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $title = trim((string) ($it['product_title'] ?? ''));
                $qty = (int) ($it['quantity'] ?? 0);
                if ($title === '' || $qty <= 0) {
                    continue;
                }
                $itemsList[] = '- ' . $qty . 'x ' . $title;
            }
        }

        $baseLines = [];
        $baseLines[] = 'GELOGUM · Pedido #' . (int) ($row['id'] ?? $orderId);

        $customerName = trim((string) ($row['user_name'] ?? ''));
        if ($customerName !== '') {
            $baseLines[] = 'Cliente: ' . $customerName;
        }

        if ($oldLabel !== null && $oldLabel !== $statusLabel) {
            $baseLines[] = 'Status: ' . $oldLabel . ' → ' . $statusLabel;
        } else {
            $baseLines[] = 'Status: ' . $statusLabel;
        }

        $baseLines[] = 'Itens: ' . (int) ($row['total_items'] ?? 0);
        if (count($itemsList) > 0) {
            $baseLines[] = 'Detalhes:';
            foreach ($itemsList as $li) {
                $baseLines[] = $li;
            }
        }

        $comment = trim((string) ($row['comment'] ?? ''));
        if ($comment !== '') {
            $baseLines[] = 'Obs.: ' . $comment;
        }

        if ($newStatus === 'cancelled') {
            $reason = trim((string) ($row['cancellation_reason'] ?? ''));
            if ($reason !== '') {
                $baseLines[] = 'Motivo: ' . $reason;
            }
        }

        $targets = [];
        if ($userTo !== null) {
            $targets[$userTo] = [
                'to' => $userTo,
                'phone' => $userPhone,
                'name' => (string) ($row['user_name'] ?? ''),
                'target_user_id' => (int) ($row['user_id'] ?? 0),
            ];
        }

        foreach (gelo_whatsapp_get_recipients($pdo, 'order') as $r) {
            $targets[(string) $r['to']] = [
                'to' => (string) $r['to'],
                'phone' => (string) ($r['phone'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'target_user_id' => null,
            ];
        }

        foreach ($targets as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                $name = 'tudo bem';
            }

            $lines = [];
            $lines[] = 'Olá, ' . $name . '!';
            $lines[] = '';
            foreach ($baseLines as $bl) {
                $lines[] = $bl;
            }
            $body = implode("\n", $lines);

            $res = gelo_ultramsg_send_text((string) $t['to'], $body);
            $pdoRes = '';
            if (isset($res['response'])) {
                $pdoRes = is_string($res['response']) ? $res['response'] : json_encode($res['response']);
            }

            gelo_whatsapp_log($pdo, [
                'message_type' => $oldStatus === null ? 'order_created' : 'order_status_changed',
                'order_id' => $orderId,
                'target_user_id' => $t['target_user_id'],
                'recipient_phone' => (string) ($t['phone'] ?? ''),
                'body' => $body,
                'api_response' => $pdoRes !== '' ? $pdoRes : null,
                'is_success' => (bool) ($res['ok'] ?? false),
            ]);
        }
    } catch (Throwable $e) {
        // Best-effort: não quebra o fluxo do pedido
    }
}

function gelo_whatsapp_notify_order_return(int $orderId, int $returnId): void
{
    if ($orderId <= 0 || $returnId <= 0) {
        return;
    }

    try {
        $pdo = gelo_pdo();

        $stmt = $pdo->prepare('
            SELECT
                o.id,
                o.user_id,
                o.status,
                o.total_items,
                o.total_amount,
                o.comment,
                u.name AS user_name,
                u.phone AS user_phone
            FROM withdrawal_orders o
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            return;
        }

        $stmt = $pdo->prepare('SELECT id, reason, created_at FROM withdrawal_returns WHERE id = :rid AND order_id = :oid LIMIT 1');
        $stmt->execute(['rid' => $returnId, 'oid' => $orderId]);
        $ret = $stmt->fetch();
        if (!is_array($ret)) {
            return;
        }

        $itemsList = [];
        $returnedQty = 0;
        $returnedTotal = '0.00';
        $itemsStmt = $pdo->prepare('
            SELECT product_title, quantity, line_total
            FROM withdrawal_return_items
            WHERE return_id = :rid
            ORDER BY id ASC
        ');
        $itemsStmt->execute(['rid' => $returnId]);
        foreach ($itemsStmt->fetchAll() as $it) {
            if (!is_array($it)) {
                continue;
            }
            $title = trim((string) ($it['product_title'] ?? ''));
            $qty = (int) ($it['quantity'] ?? 0);
            $lt = (string) ($it['line_total'] ?? '0.00');
            if ($title === '' || $qty <= 0) {
                continue;
            }
            $returnedQty += $qty;
            $returnedTotal = bcadd($returnedTotal, $lt, 2);
            $itemsList[] = '- ' . $qty . 'x ' . $title;
        }

        $status = (string) ($order['status'] ?? '');
        $statusLabel = $status !== '' ? gelo_whatsapp_status_label($status) : '';

        $baseLines = [];
        $baseLines[] = 'GELOGUM · Pedido #' . (int) ($order['id'] ?? $orderId);
        $customerName = trim((string) ($order['user_name'] ?? ''));
        if ($customerName !== '') {
            $baseLines[] = 'Cliente: ' . $customerName;
        }
        if ($statusLabel !== '') {
            $baseLines[] = 'Status: ' . $statusLabel;
        }
        $baseLines[] = 'Evento: Retorno registrado';
        $baseLines[] = 'Retorno: ' . $returnedQty . ' item(ns)';
        if (count($itemsList) > 0) {
            $baseLines[] = 'Itens do retorno:';
            foreach ($itemsList as $li) {
                $baseLines[] = $li;
            }
        }

        $reason = trim((string) ($ret['reason'] ?? ''));
        if ($reason !== '') {
            $baseLines[] = 'Motivo: ' . $reason;
        }

        $comment = trim((string) ($order['comment'] ?? ''));
        if ($comment !== '') {
            $baseLines[] = 'Obs.: ' . $comment;
        }

        $userPhone = (string) ($order['user_phone'] ?? '');
        $userTo = gelo_whatsapp_normalize_to($userPhone);

        $targets = [];
        if ($userTo !== null) {
            $targets[$userTo] = [
                'to' => $userTo,
                'phone' => $userPhone,
                'name' => (string) ($order['user_name'] ?? ''),
                'target_user_id' => (int) ($order['user_id'] ?? 0),
            ];
        }

        foreach (gelo_whatsapp_get_recipients($pdo, 'order') as $r) {
            $targets[(string) $r['to']] = [
                'to' => (string) $r['to'],
                'phone' => (string) ($r['phone'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'target_user_id' => null,
            ];
        }

        foreach ($targets as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                $name = 'tudo bem';
            }

            $lines = [];
            $lines[] = 'Olá, ' . $name . '!';
            $lines[] = '';
            foreach ($baseLines as $bl) {
                $lines[] = $bl;
            }
            $body = implode("\n", $lines);

            $res = gelo_ultramsg_send_text((string) $t['to'], $body);
            $pdoRes = '';
            if (isset($res['response'])) {
                $pdoRes = is_string($res['response']) ? $res['response'] : json_encode($res['response']);
            }

            gelo_whatsapp_log($pdo, [
                'message_type' => 'order_return_created',
                'order_id' => $orderId,
                'target_user_id' => $t['target_user_id'],
                'recipient_phone' => (string) ($t['phone'] ?? ''),
                'body' => $body,
                'api_response' => $pdoRes !== '' ? $pdoRes : null,
                'is_success' => (bool) ($res['ok'] ?? false),
            ]);
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

function gelo_whatsapp_notify_order_payment(int $orderId, int $paymentId): void
{
    if ($orderId <= 0 || $paymentId <= 0) {
        return;
    }

    try {
        $pdo = gelo_pdo();

        $stmt = $pdo->prepare('
            SELECT
                o.id,
                o.user_id,
                o.status,
                o.total_amount,
                o.comment,
                u.name AS user_name,
                u.phone AS user_phone
            FROM withdrawal_orders o
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            return;
        }

        $stmt = $pdo->prepare('SELECT id, amount, method, paid_at, note FROM withdrawal_payments WHERE id = :pid AND order_id = :oid LIMIT 1');
        $stmt->execute(['pid' => $paymentId, 'oid' => $orderId]);
        $pay = $stmt->fetch();
        if (!is_array($pay)) {
            return;
        }

        // Alertas WPP (exceto resumo do dia) não devem conter valores financeiros.

        $status = (string) ($order['status'] ?? '');
        $statusLabel = $status !== '' ? gelo_whatsapp_status_label($status) : '';

        $method = (string) ($pay['method'] ?? '');
        $methodLabel = $method;
        if (function_exists('gelo_withdrawal_payment_method_label')) {
            $methodLabel = (string) gelo_withdrawal_payment_method_label($method !== '' ? $method : null);
        }

        $baseLines = [];
        $baseLines[] = 'GELOGUM · Pedido #' . (int) ($order['id'] ?? $orderId);
        $customerName = trim((string) ($order['user_name'] ?? ''));
        if ($customerName !== '') {
            $baseLines[] = 'Cliente: ' . $customerName;
        }
        if ($statusLabel !== '') {
            $baseLines[] = 'Status: ' . $statusLabel;
        }
        $baseLines[] = 'Evento: Pagamento registrado';
        $baseLines[] = 'Tipo: ' . $methodLabel;

        $note = trim((string) ($pay['note'] ?? ''));
        if ($note !== '') {
            $baseLines[] = 'Obs.: ' . $note;
        }

        $comment = trim((string) ($order['comment'] ?? ''));
        if ($comment !== '') {
            $baseLines[] = 'Obs. pedido: ' . $comment;
        }

        $userPhone = (string) ($order['user_phone'] ?? '');
        $userTo = gelo_whatsapp_normalize_to($userPhone);

        $targets = [];
        if ($userTo !== null) {
            $targets[$userTo] = [
                'to' => $userTo,
                'phone' => $userPhone,
                'name' => (string) ($order['user_name'] ?? ''),
                'target_user_id' => (int) ($order['user_id'] ?? 0),
            ];
        }

        foreach (gelo_whatsapp_get_recipients($pdo, 'order') as $r) {
            $targets[(string) $r['to']] = [
                'to' => (string) $r['to'],
                'phone' => (string) ($r['phone'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'target_user_id' => null,
            ];
        }

        foreach ($targets as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                $name = 'tudo bem';
            }

            $lines = [];
            $lines[] = 'Olá, ' . $name . '!';
            $lines[] = '';
            foreach ($baseLines as $bl) {
                $lines[] = $bl;
            }
            $body = implode("\n", $lines);

            $res = gelo_ultramsg_send_text((string) $t['to'], $body);
            $pdoRes = '';
            if (isset($res['response'])) {
                $pdoRes = is_string($res['response']) ? $res['response'] : json_encode($res['response']);
            }

            gelo_whatsapp_log($pdo, [
                'message_type' => 'order_payment_created',
                'order_id' => $orderId,
                'target_user_id' => $t['target_user_id'],
                'recipient_phone' => (string) ($t['phone'] ?? ''),
                'body' => $body,
                'api_response' => $pdoRes !== '' ? $pdoRes : null,
                'is_success' => (bool) ($res['ok'] ?? false),
            ]);
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

function gelo_whatsapp_notify_user_payment(int $userPaymentId): void
{
    if ($userPaymentId <= 0) {
        return;
    }

    try {
        $pdo = gelo_pdo();

        $stmt = $pdo->prepare('
            SELECT
                up.id,
                up.user_id,
                up.amount,
                up.method,
                up.paid_at,
                up.note,
                up.open_before,
                up.open_after,
                u.name AS user_name,
                u.phone AS user_phone
            FROM user_payments up
            INNER JOIN users u ON u.id = up.user_id
            WHERE up.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $userPaymentId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }

        $userPhone = (string) ($row['user_phone'] ?? '');
        $userTo = gelo_whatsapp_normalize_to($userPhone);

        $method = (string) ($row['method'] ?? '');
        $methodLabel = $method;
        if (function_exists('gelo_withdrawal_payment_method_label')) {
            $methodLabel = (string) gelo_withdrawal_payment_method_label($method !== '' ? $method : null);
        }

        $allocStmt = $pdo->prepare('
            SELECT order_id, amount
            FROM user_payment_allocations
            WHERE user_payment_id = :id
            ORDER BY id ASC
        ');
        $allocStmt->execute(['id' => $userPaymentId]);
        $allocs = $allocStmt->fetchAll();

        $allocLines = [];
        $shown = 0;
        $maxShown = 15;
        $totalAllocs = 0;
        foreach ($allocs as $a) {
            if (!is_array($a)) {
                continue;
            }
            $totalAllocs++;
            if ($shown >= $maxShown) {
                continue;
            }
            $oid = (int) ($a['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $allocLines[] = '- Pedido #' . $oid;
            $shown++;
        }
        if ($totalAllocs > $maxShown) {
            $allocLines[] = '... e +' . ($totalAllocs - $maxShown) . ' pedido(s).';
        }

        $baseLines = [];
        $baseLines[] = 'GELOGUM · Pagamento registrado';

        $customerName = trim((string) ($row['user_name'] ?? ''));
        if ($customerName !== '') {
            $baseLines[] = 'Cliente: ' . $customerName;
        }

        $baseLines[] = 'Método: ' . $methodLabel;

        if (count($allocLines) > 0) {
            $baseLines[] = 'Compensação:';
            foreach ($allocLines as $li) {
                $baseLines[] = $li;
            }
        }

        $note = trim((string) ($row['note'] ?? ''));
        if ($note !== '') {
            $baseLines[] = 'Obs.: ' . $note;
        }

        $targets = [];
        if ($userTo !== null) {
            $targets[$userTo] = [
                'to' => $userTo,
                'phone' => $userPhone,
                'name' => (string) ($row['user_name'] ?? ''),
                'target_user_id' => (int) ($row['user_id'] ?? 0),
            ];
        }

        foreach (gelo_whatsapp_get_recipients($pdo, 'order') as $r) {
            $targets[(string) $r['to']] = [
                'to' => (string) $r['to'],
                'phone' => (string) ($r['phone'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'target_user_id' => null,
            ];
        }

        foreach ($targets as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                $name = 'tudo bem';
            }

            $lines = [];
            $lines[] = 'Olá, ' . $name . '!';
            $lines[] = '';
            foreach ($baseLines as $bl) {
                $lines[] = $bl;
            }
            $body = implode("\n", $lines);

            $res = gelo_ultramsg_send_text((string) $t['to'], $body);
            $pdoRes = '';
            if (isset($res['response'])) {
                $pdoRes = is_string($res['response']) ? $res['response'] : json_encode($res['response']);
            }

            gelo_whatsapp_log($pdo, [
                'message_type' => 'user_payment_created',
                'order_id' => null,
                'target_user_id' => $t['target_user_id'],
                'recipient_phone' => (string) ($t['phone'] ?? ''),
                'body' => $body,
                'api_response' => $pdoRes !== '' ? $pdoRes : null,
                'is_success' => (bool) ($res['ok'] ?? false),
            ]);
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

function gelo_whatsapp_daily_summary_send(DateTimeImmutable $day): void
{
    try {
        $pdo = gelo_pdo();

        $runDate = $day->format('Y-m-d');
        $pdo->prepare('INSERT IGNORE INTO whatsapp_daily_summary_runs (run_date) VALUES (:d)')->execute(['d' => $runDate]);
        $check = $pdo->prepare('SELECT run_date FROM whatsapp_daily_summary_runs WHERE run_date = :d LIMIT 1');
        $check->execute(['d' => $runDate]);
        $exists = $check->fetch();
        if (!is_array($exists)) {
            return;
        }

        // Se já houver logs de daily_summary no dia, não envia novamente.
        $already = $pdo->prepare('SELECT id FROM whatsapp_message_logs WHERE message_type = :t AND DATE(created_at) = :d LIMIT 1');
        $already->execute(['t' => 'daily_summary', 'd' => $runDate]);
        if (is_array($already->fetch())) {
            return;
        }

        $start = $day->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        $returnsJoin = '
            LEFT JOIN (
                SELECT r.order_id, COALESCE(SUM(ri.line_total), 0) AS returned_amount
                FROM withdrawal_returns r
                INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
                GROUP BY r.order_id
            ) ret ON ret.order_id = o.id
        ';
        $paymentsJoin = '
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(amount), 0) AS paid_amount
                FROM withdrawal_payments
                GROUP BY order_id
            ) pay ON pay.order_id = o.id
        ';

        // Vendas do dia (saída líquida no dia)
        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0)), 0) AS v
            FROM withdrawal_orders o
            ' . $returnsJoin . '
            WHERE o.status = \'saida\'
              AND o.delivered_at >= :s AND o.delivered_at < :e
        ');
        $stmt->execute([
            's' => $start->format('Y-m-d H:i:s'),
            'e' => $end->format('Y-m-d H:i:s'),
        ]);
        $sales = (float) ((array) $stmt->fetch())['v'];

        // Pagamentos recebidos no dia
        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(p.amount), 0) AS v
            FROM withdrawal_payments p
            WHERE p.paid_at >= :s AND p.paid_at < :e
        ');
        $stmt->execute([
            's' => $start->format('Y-m-d H:i:s'),
            'e' => $end->format('Y-m-d H:i:s'),
        ]);
        $paidToday = (float) ((array) $stmt->fetch())['v'];

        // Em aberto no momento (global)
        $stmt = $pdo->query('
            SELECT COALESCE(SUM(
                GREATEST(
                    GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0),
                    0
                )
            ), 0) AS v
            FROM withdrawal_orders o
            ' . $returnsJoin . '
            ' . $paymentsJoin . '
            WHERE o.status = \'saida\'
        ');
        $openNow = (float) ((array) $stmt->fetch())['v'];

        $dateLabel = $start->format('d/m/Y');

        $baseBody = "GELOGUM · Resumo do dia ({$dateLabel})\n\n" .
            "- Vendas do dia (saídas): " . gelo_format_money($sales) . "\n" .
            "- Pagamentos recebidos: " . gelo_format_money($paidToday) . "\n" .
            "- Em aberto agora: " . gelo_format_money($openNow) . "\n\n" .
            "Obrigado!";

        $targets = gelo_whatsapp_get_recipients($pdo, 'daily');
        foreach ($targets as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                $name = 'tudo bem';
            }

            $body = "Olá, {$name}!\n\n" . $baseBody;
            $res = gelo_ultramsg_send_text((string) $t['to'], $body);
            $pdoRes = '';
            if (isset($res['response'])) {
                $pdoRes = is_string($res['response']) ? $res['response'] : json_encode($res['response']);
            }

            gelo_whatsapp_log($pdo, [
                'message_type' => 'daily_summary',
                'order_id' => null,
                'target_user_id' => null,
                'recipient_phone' => (string) ($t['phone'] ?? ''),
                'body' => $body,
                'api_response' => $pdoRes !== '' ? $pdoRes : null,
                'is_success' => (bool) ($res['ok'] ?? false),
            ]);
        }
    } catch (Throwable $e) {
        // best-effort
    }
}
