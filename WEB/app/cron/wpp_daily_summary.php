<?php
declare(strict_types=1);

// Executar via CLI: php WEB/app/cron/wpp_daily_summary.php
// Envia o resumo do dia para destinatários configurados (receive_daily_summary).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../API/config/database.php';
require_once __DIR__ . '/../lib/whatsapp_ultramsg.php';

$day = new DateTimeImmutable('now');

// Opcional: permitir passar data YYYY-MM-DD como argumento.
if (isset($argv) && is_array($argv) && isset($argv[1]) && is_string($argv[1])) {
    $arg = trim($argv[1]);
    if ($arg !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $arg);
        $errs = DateTimeImmutable::getLastErrors();
        if ($dt instanceof DateTimeImmutable && is_array($errs) && $errs['warning_count'] === 0 && $errs['error_count'] === 0) {
            $day = $dt;
        }
    }
}

gelo_whatsapp_daily_summary_send($day);

echo "OK\n";
