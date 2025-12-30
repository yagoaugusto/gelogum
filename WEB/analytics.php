<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('analytics.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Analítico · GELO';
$activePage = 'analytics';
$error = gelo_flash_get('error');

$user = gelo_current_user();
$userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');

$now = new DateTimeImmutable('now');
$currentYear = (int) $now->format('Y');
$currentMonth = (int) $now->format('n');

$years = [];
try {
    $pdo = gelo_pdo();
    $stmt = $pdo->query('SELECT DISTINCT YEAR(created_at) AS y FROM withdrawal_orders ORDER BY y DESC');
    foreach ($stmt->fetchAll() as $row) {
        $y = isset($row['y']) ? (int) $row['y'] : 0;
        if ($y > 0) {
            $years[] = $y;
        }
    }
} catch (Throwable $e) {
    $years = [];
}
if (empty($years)) {
    $years = [$currentYear];
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : $currentYear;
if (!in_array($year, $years, true)) {
    $year = $years[0] ?? $currentYear;
}

$month = isset($_GET['month']) ? (int) $_GET['month'] : $currentMonth;
if ($month < 1 || $month > 12) {
    $month = $currentMonth;
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$weekOptions = [];
for ($w = 1; $w <= 6; $w++) {
    $startDay = (($w - 1) * 7) + 1;
    if ($startDay > $daysInMonth) {
        break;
    }
    $endDay = min($w * 7, $daysInMonth);
    $weekOptions[$w] = [
        'start' => $startDay,
        'end' => $endDay,
        'label' => sprintf('Semana %d (%02d–%02d)', $w, $startDay, $endDay),
    ];
}

$week = isset($_GET['week']) ? (int) $_GET['week'] : 0;
if ($week <= 0 || !isset($weekOptions[$week])) {
    $week = 0;
}

$rangeStartDay = $week > 0 ? (int) $weekOptions[$week]['start'] : 1;
$rangeEndDay = $week > 0 ? (int) $weekOptions[$week]['end'] : $daysInMonth;

$rangeStart = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $rangeStartDay));
$rangeEnd = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $rangeEndDay));
$rangeEndExclusive = $rangeEnd->modify('+1 day');

$rangeLabel = $rangeStart->format('d/m/Y') . ' · ' . $rangeEnd->format('d/m/Y');

$months = [
    1 => 'Janeiro',
    2 => 'Fevereiro',
    3 => 'Março',
    4 => 'Abril',
    5 => 'Maio',
    6 => 'Junho',
    7 => 'Julho',
    8 => 'Agosto',
    9 => 'Setembro',
    10 => 'Outubro',
    11 => 'Novembro',
    12 => 'Dezembro',
];

$summary = [
    'orders_count' => 0,
    'avg_order_amount' => '0.00',
    'cancelled_count' => 0,
    'with_return_count' => 0,
    'delivered_count' => 0,
    'in_progress_count' => 0,
    'total_payable' => '0.00',
    'total_paid' => '0.00',
    'total_open' => '0.00',
];

$days = [];
$cursor = $rangeStart;
while ($cursor < $rangeEndExclusive) {
    $key = $cursor->format('Y-m-d');
    $days[$key] = [
        'label' => $cursor->format('d/m'),
        'orders' => 0,
        'delivered_value' => '0.00',
        'cancelled' => 0,
        'paid' => '0.00',
    ];
    $cursor = $cursor->modify('+1 day');
}

$salesByProduct = [];
$salesByUser = [];
$paymentsByMethod = [];

try {
    $pdo = gelo_pdo();

    $where = 'o.created_at >= :start AND o.created_at < :end';
    $params = [
        'start' => $rangeStart->format('Y-m-d H:i:s'),
        'end' => $rangeEndExclusive->format('Y-m-d H:i:s'),
    ];
    if (!$canViewAll) {
        $where .= ' AND o.user_id = :user_id';
        $params['user_id'] = $userId;
    }

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

    $stmt = $pdo->prepare('
        SELECT
            COUNT(*) AS orders_count,
            AVG(CASE WHEN o.status <> \'cancelled\' THEN o.total_amount ELSE NULL END) AS avg_order_amount,
            SUM(CASE WHEN o.status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN COALESCE(ret.returned_amount, 0) > 0 THEN 1 ELSE 0 END) AS with_return_count,
            SUM(CASE WHEN o.status = \'delivered\' THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN o.status IN (\'requested\', \'separated\') THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN o.status = \'delivered\' THEN GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) ELSE 0 END) AS total_payable,
            SUM(CASE WHEN o.status = \'delivered\' THEN COALESCE(pay.paid_amount, 0) ELSE 0 END) AS total_paid,
            SUM(CASE WHEN o.status = \'delivered\' THEN GREATEST(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0), 0) ELSE 0 END) AS total_open
        FROM withdrawal_orders o
        ' . $returnsJoin . '
        ' . $paymentsJoin . '
        WHERE ' . $where . '
    ');
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $summary['orders_count'] = (int) ($row['orders_count'] ?? 0);
        $summary['avg_order_amount'] = (string) ($row['avg_order_amount'] ?? '0.00');
        $summary['cancelled_count'] = (int) ($row['cancelled_count'] ?? 0);
        $summary['with_return_count'] = (int) ($row['with_return_count'] ?? 0);
        $summary['delivered_count'] = (int) ($row['delivered_count'] ?? 0);
        $summary['in_progress_count'] = (int) ($row['in_progress_count'] ?? 0);
        $summary['total_payable'] = (string) ($row['total_payable'] ?? '0.00');
        $summary['total_paid'] = (string) ($row['total_paid'] ?? '0.00');
        $summary['total_open'] = (string) ($row['total_open'] ?? '0.00');
    }

    $stmt = $pdo->prepare('
        SELECT
            DATE(o.created_at) AS day,
            COUNT(*) AS orders_count,
            SUM(CASE WHEN o.status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.status = \'delivered\' THEN GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) ELSE 0 END) AS delivered_value
        FROM withdrawal_orders o
        ' . $returnsJoin . '
        WHERE ' . $where . '
        GROUP BY DATE(o.created_at)
        ORDER BY day ASC
    ');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $day = isset($r['day']) ? (string) $r['day'] : '';
        if ($day === '' || !isset($days[$day])) {
            continue;
        }
        $days[$day]['orders'] = (int) ($r['orders_count'] ?? 0);
        $days[$day]['cancelled'] = (int) ($r['cancelled_count'] ?? 0);
        $days[$day]['delivered_value'] = (string) ($r['delivered_value'] ?? '0.00');
    }

    $payWhere = 'p.paid_at >= :start AND p.paid_at < :end';
    $payParams = [
        'start' => $rangeStart->format('Y-m-d H:i:s'),
        'end' => $rangeEndExclusive->format('Y-m-d H:i:s'),
    ];
    if (!$canViewAll) {
        $payWhere .= ' AND o.user_id = :user_id';
        $payParams['user_id'] = $userId;
    }

    $stmt = $pdo->prepare('
        SELECT DATE(p.paid_at) AS day, COALESCE(SUM(p.amount), 0) AS paid_amount
        FROM withdrawal_payments p
        INNER JOIN withdrawal_orders o ON o.id = p.order_id
        WHERE ' . $payWhere . '
        GROUP BY DATE(p.paid_at)
        ORDER BY day ASC
    ');
    $stmt->execute($payParams);
    foreach ($stmt->fetchAll() as $r) {
        $day = isset($r['day']) ? (string) $r['day'] : '';
        if ($day === '' || !isset($days[$day])) {
            continue;
        }
        $days[$day]['paid'] = (string) ($r['paid_amount'] ?? '0.00');
    }

    $stmt = $pdo->prepare('
        SELECT p.method, COALESCE(SUM(p.amount), 0) AS total_amount, COUNT(*) AS c
        FROM withdrawal_payments p
        INNER JOIN withdrawal_orders o ON o.id = p.order_id
        WHERE ' . $payWhere . '
        GROUP BY p.method
        ORDER BY total_amount DESC
    ');
    $stmt->execute($payParams);
    $paymentsByMethod = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT
            oi.product_id,
            oi.product_title,
            SUM(oi.quantity) AS gross_qty,
            SUM(oi.line_total) AS gross_total,
            COALESCE(SUM(rrp.returned_qty), 0) AS returned_qty,
            COALESCE(SUM(rrp.returned_total), 0) AS returned_total,
            GREATEST(SUM(oi.quantity) - COALESCE(SUM(rrp.returned_qty), 0), 0) AS net_qty,
            GREATEST(SUM(oi.line_total) - COALESCE(SUM(rrp.returned_total), 0), 0) AS net_total
        FROM withdrawal_orders o
        INNER JOIN withdrawal_order_items oi ON oi.order_id = o.id
        LEFT JOIN (
            SELECT
                r.order_id,
                ri.product_id,
                COALESCE(SUM(ri.quantity), 0) AS returned_qty,
                COALESCE(SUM(ri.line_total), 0) AS returned_total
            FROM withdrawal_returns r
            INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
            GROUP BY r.order_id, ri.product_id
        ) rrp ON rrp.order_id = o.id AND rrp.product_id = oi.product_id
        WHERE o.status = \'delivered\' AND ' . $where . '
        GROUP BY oi.product_id, oi.product_title
        ORDER BY net_total DESC, net_qty DESC
        LIMIT 50
    ');
    $stmt->execute($params);
    $salesByProduct = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT
            u.id,
            u.name,
            u.phone,
            COUNT(o.id) AS orders_count,
            SUM(CASE WHEN COALESCE(ret.returned_amount, 0) > 0 THEN 1 ELSE 0 END) AS orders_with_return,
            AVG(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0)) AS avg_order_value,
            SUM(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0)) AS payable_total,
            SUM(COALESCE(pay.paid_amount, 0)) AS paid_total,
            SUM(GREATEST(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0), 0)) AS open_total
        FROM withdrawal_orders o
        INNER JOIN users u ON u.id = o.user_id
        ' . $returnsJoin . '
        ' . $paymentsJoin . '
        WHERE o.status = \'delivered\' AND ' . $where . '
        GROUP BY u.id, u.name, u.phone
        ORDER BY payable_total DESC, orders_count DESC
        LIMIT 200
    ');
    $stmt->execute($params);
    $salesByUser = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar analítico. Verifique o banco e as migrações.';
}

$payable = (float) $summary['total_payable'];
$paid = (float) $summary['total_paid'];
$paidPct = $payable > 0 ? max(0.0, min(100.0, ($paid / $payable) * 100.0)) : 0.0;

$maxOrders = 0;
$maxDeliveredValue = 0.0;
$maxPaidValue = 0.0;
foreach ($days as $it) {
    $maxOrders = max($maxOrders, (int) ($it['orders'] ?? 0));
    $maxDeliveredValue = max($maxDeliveredValue, (float) ($it['delivered_value'] ?? 0));
    $maxPaidValue = max($maxPaidValue, (float) ($it['paid'] ?? 0));
}
$maxValue = max($maxDeliveredValue, $maxPaidValue);

// Gráficos (Chart.js)
$chartLabels = [];
$chartOrders = [];
$chartDelivered = [];
$chartPaid = [];
foreach ($days as $it) {
    $chartLabels[] = (string) ($it['label'] ?? '');
    $chartOrders[] = (int) ($it['orders'] ?? 0);
    $chartDelivered[] = (float) ($it['delivered_value'] ?? 0);
    $chartPaid[] = (float) ($it['paid'] ?? 0);
}

// Detalhamento por usuário: busca/ordenação no front (JS), sem reload
$filteredSalesByUser = $salesByUser;
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200 overflow-x-hidden">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto w-full max-w-7xl px-4 py-5 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">Analítico</h1>
                    <span class="badge badge-outline"><?= gelo_e($rangeLabel) ?></span>
                    <?php if (!$canViewAll): ?>
                        <span class="badge badge-ghost">Meus dados</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm opacity-70 mt-1">KPIs e gráficos para entender pedidos, valores, devoluções e pagamentos.</p>
            </div>
        </div>

        <form class="mt-6 card bg-base-100 shadow-xl ring-1 ring-base-300/60" method="get" action="<?= gelo_e(GELO_BASE_URL . '/analytics.php') ?>">
            <div class="card-body p-4 sm:p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="grid gap-3 sm:grid-cols-3 lg:max-w-3xl w-full">
                        <label class="form-control w-full">
                            <div class="label py-0"><span class="label-text text-xs opacity-70">Ano</span></div>
                            <select class="select select-bordered select-sm w-full" name="year" required>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= (int) $y ?>" <?= ((int) $y === (int) $year) ? 'selected' : '' ?>><?= (int) $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="form-control w-full">
                            <div class="label py-0"><span class="label-text text-xs opacity-70">Mês</span></div>
                            <select class="select select-bordered select-sm w-full" name="month" required>
                                <?php foreach ($months as $m => $label): ?>
                                    <option value="<?= (int) $m ?>" <?= ((int) $m === (int) $month) ? 'selected' : '' ?>><?= gelo_e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="form-control w-full">
                            <div class="label py-0"><span class="label-text text-xs opacity-70">Semana (opcional)</span></div>
                            <select class="select select-bordered select-sm w-full" name="week">
                                <option value="0" <?= $week === 0 ? 'selected' : '' ?>>Todas</option>
                                <?php foreach ($weekOptions as $w => $meta): ?>
                                    <option value="<?= (int) $w ?>" <?= ((int) $w === (int) $week) ? 'selected' : '' ?>><?= gelo_e((string) $meta['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button class="btn btn-primary btn-sm" type="submit">Aplicar</button>
                        <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/analytics.php') ?>">Mês atual</a>
                    </div>
                </div>
            </div>
        </form>

        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4">
                <span><?= gelo_e($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="mt-6 grid gap-4 lg:grid-cols-12 items-stretch">
            <div class="lg:col-span-8">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold">Informações dos pedidos</div>
                        <div class="text-xs opacity-70 mt-1">KPIs do período selecionado.</div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Total de pedidos</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= (int) $summary['orders_count'] ?></div>
                                <div class="mt-2 text-xs opacity-60">Criados no período.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-primary/15 text-primary rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-primary/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5l5 5v11a2 2 0 01-2 2z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Entregues</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= (int) $summary['delivered_count'] ?></div>
                                <div class="mt-2 text-xs opacity-60">Pedidos concluídos.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-success/15 text-success rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-success/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Em andamento</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= (int) $summary['in_progress_count'] ?></div>
                                <div class="mt-2 text-xs opacity-60">Solicitados + separados.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-info/15 text-info rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-info/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Cancelados</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= (int) $summary['cancelled_count'] ?></div>
                                <div class="mt-2 text-xs opacity-60">Pedidos cancelados.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-error/15 text-error rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-error/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Com devolução</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= (int) $summary['with_return_count'] ?></div>
                                <div class="mt-2 text-xs opacity-60">Pedidos com itens devolvidos.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-warning/15 text-warning rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-warning/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a4 4 0 014 4v7m0 0l-3-3m3 3l3-3M21 14H11a4 4 0 01-4-4V3m0 0l3 3M7 3L4 6"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Ticket médio</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= gelo_e(gelo_format_money($summary['avg_order_amount'])) ?></div>
                                <div class="mt-2 text-xs opacity-60">Média de pedidos não cancelados.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-secondary/15 text-secondary rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-secondary/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v.623c-1.03.258-1.75 1.13-1.75 2.127 0 1.138.91 1.88 2.07 2.108l.31.064c.87.18 1.12.48 1.12.878 0 .467-.49.85-1.25.85-.67 0-1.1-.26-1.36-.62a.75.75 0 10-1.22.88c.4.55 1.04.93 1.8 1.08v.49a.75.75 0 001.5 0v-.47c1.11-.22 1.88-1.03 1.88-2.1 0-1.09-.72-1.78-2.08-2.07l-.32-.07c-.78-.17-1.1-.42-1.1-.88 0-.5.46-.86 1.09-.86.52 0 .88.2 1.09.45a.75.75 0 101.14-.98c-.33-.39-.82-.68-1.4-.83V6.75z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-4">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold">Financeiro</div>
                        <div class="text-xs opacity-70 mt-1">Base: pedidos entregues (líquido).</div>
                    </div>
                    <span class="badge badge-outline badge-sm"><?= (int) round($paidPct) ?>%</span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">A pagar</div>
                                <div class="mt-1 text-xl font-semibold leading-none"><?= gelo_e(gelo_format_money($summary['total_payable'])) ?></div>
                                <div class="mt-2 text-xs opacity-60">Total líquido entregue.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-primary/15 text-primary rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-primary/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v.623c-1.03.258-1.75 1.13-1.75 2.127 0 1.138.91 1.88 2.07 2.108l.31.064c.87.18 1.12.48 1.12.878 0 .467-.49.85-1.25.85-.67 0-1.1-.26-1.36-.62a.75.75 0 10-1.22.88c.4.55 1.04.93 1.8 1.08v.49a.75.75 0 001.5 0v-.47c1.11-.22 1.88-1.03 1.88-2.1 0-1.09-.72-1.78-2.08-2.07l-.32-.07c-.78-.17-1.1-.42-1.1-.88 0-.5.46-.86 1.09-.86.52 0 .88.2 1.09.45a.75.75 0 101.14-.98c-.33-.39-.82-.68-1.4-.83V6.75z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Pago</div>
                                <div class="mt-1 text-xl font-semibold leading-none"><?= gelo_e(gelo_format_money($summary['total_paid'])) ?></div>
                                <div class="mt-2 text-xs opacity-60">Recebimentos no período.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-success/15 text-success rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-success/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Em aberto</div>
                                <div class="mt-1 text-xl font-semibold leading-none"><?= gelo_e(gelo_format_money($summary['total_open'])) ?></div>
                                <div class="mt-2 text-xs opacity-60">Falta receber.</div>
                            </div>
                            <div class="shrink-0">
                                <div class="bg-warning/15 text-warning rounded-2xl w-10 h-10 flex items-center justify-center ring-1 ring-warning/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-box bg-base-100 shadow-sm ring-1 ring-base-300/60 p-4 sm:col-span-2 md:col-span-3">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="text-xs uppercase tracking-wide opacity-60">Quitação</div>
                                <div class="mt-1 text-2xl font-semibold leading-none"><?= (int) round($paidPct) ?>%</div>
                                <div class="mt-2 text-xs opacity-60">
                                    <?= gelo_e(gelo_format_money($summary['total_paid'])) ?> de <?= gelo_e(gelo_format_money($summary['total_payable'])) ?>
                                </div>
                            </div>
                            <div class="radial-progress text-success bg-base-200 shrink-0" style="--value: <?= (int) round($paidPct) ?>; --size: 3.25rem; --thickness: 0.35rem;">
                                <span class="text-xs font-semibold text-base-content"><?= (int) round($paidPct) ?>%</span>
                            </div>
                        </div>
                        <progress class="progress progress-success w-full h-2 mt-3" value="<?= (int) round($paidPct) ?>" max="100"></progress>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-4 items-start">
            <?php $showFullDayLabel = $week > 0; ?>

            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                <div class="card-body p-4 sm:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">Pedidos por dia</h2>
                            <p class="text-sm opacity-70 mt-1">Quantidade de pedidos criados no período.</p>
                        </div>
                        <div class="text-xs opacity-70">máx.: <?= (int) $maxOrders ?></div>
                    </div>

                    <div class="mt-4 rounded-box border border-base-200 bg-base-100 p-4">
                        <div class="h-8 sm:h-10 lg:h-11">
                            <canvas id="ordersByDayChart" class="w-full h-full"></canvas>
                        </div>
                        <div class="mt-3 text-xs opacity-70">Dica: toque em um ponto para ver detalhes.</div>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                <div class="card-body p-4 sm:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">Valores por dia</h2>
                            <p class="text-sm opacity-70 mt-1">Entregue líquido (base: pedidos entregues) vs. recebido (base: pagamentos).</p>
                        </div>
                        <div class="text-xs opacity-70 sm:text-right">
                            <div>máx.: <?= gelo_e(gelo_format_money($maxValue)) ?></div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-box border border-base-200 bg-base-100 p-4 overflow-hidden">
                        <div class="flex flex-wrap items-center gap-2 text-xs opacity-80">
                            <span class="badge badge-success badge-outline">Entregue</span>
                            <span class="badge badge-info badge-outline">Recebido</span>
                        </div>

                        <div class="mt-4 h-8 sm:h-10 lg:h-11">
                            <canvas id="valuesByDayChart" class="w-full h-full"></canvas>
                        </div>
                        <div class="mt-3 text-xs opacity-70">Dica: o “Recebido” pode ocorrer em dias diferentes do “Entregue”.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 lg:col-span-2">
                <div class="card-body p-4 sm:p-8">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold">Vendas por produto</h2>
                        <span class="badge badge-outline"><?= count($salesByProduct) ?> itens</span>
                    </div>
                    <p class="text-sm opacity-70 mt-1">Quantidade e valor líquido (total − devoluções) para pedidos entregues.</p>

                    <div class="mt-4 sm:hidden overflow-hidden rounded-box border border-base-200 bg-base-100 divide-y divide-base-200">
                        <?php if (empty($salesByProduct)): ?>
                            <div class="p-6 text-center opacity-70">Sem dados no período.</div>
                        <?php else: ?>
                            <?php foreach ($salesByProduct as $p): ?>
                                <?php
                                    $returnedQty = (int) ($p['returned_qty'] ?? 0);
                                    $netQty = (int) ($p['net_qty'] ?? 0);
                                    $netTotal = $p['net_total'] ?? 0;
                                ?>
                                <div class="p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="font-medium truncate"><?= gelo_e((string) ($p['product_title'] ?? '')) ?></div>
                                            <div class="text-xs opacity-70 mt-1">
                                                Líquida: <span class="font-medium"><?= (int) $netQty ?></span>
                                                <?php if ($returnedQty > 0): ?>
                                                    · Devolvida: <span class="font-medium"><?= (int) $returnedQty ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <div class="font-semibold"><?= gelo_e(gelo_format_money($netTotal)) ?></div>
                                            <div class="text-xs opacity-70">Valor líquido</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto mt-4 hidden sm:block w-full max-w-full overscroll-x-contain touch-pan-x">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th class="text-right">Qtd</th>
                                    <th class="text-right">Devolvida</th>
                                    <th class="text-right">Líquida</th>
                                    <th class="text-right">Valor líquido</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($salesByProduct)): ?>
                                    <tr>
                                        <td colspan="5" class="py-8 text-center opacity-70">Sem dados no período.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($salesByProduct as $p): ?>
                                        <tr>
                                            <td class="font-medium"><?= gelo_e((string) ($p['product_title'] ?? '')) ?></td>
                                            <td class="text-right"><?= (int) ($p['gross_qty'] ?? 0) ?></td>
                                            <td class="text-right"><?= (int) ($p['returned_qty'] ?? 0) ?></td>
                                            <td class="text-right font-medium"><?= (int) ($p['net_qty'] ?? 0) ?></td>
                                            <td class="text-right font-medium"><?= gelo_e(gelo_format_money($p['net_total'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                <div class="card-body p-4 sm:p-8">
                    <h2 class="text-lg font-semibold">Recebimentos (por tipo)</h2>
                    <p class="text-sm opacity-70 mt-1">Soma de pagamentos no período selecionado.</p>

                    <div class="overflow-x-auto mt-4 w-full max-w-full overscroll-x-contain touch-pan-x">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th class="text-right">Qtd</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paymentsByMethod)): ?>
                                    <tr>
                                        <td colspan="3" class="py-6 text-center opacity-70">Sem pagamentos no período.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paymentsByMethod as $pm): ?>
                                        <tr>
                                            <td class="text-sm opacity-80">
                                                <span class="badge badge-outline"><?= gelo_e(gelo_withdrawal_payment_method_label(isset($pm['method']) ? (string) $pm['method'] : null)) ?></span>
                                            </td>
                                            <td class="text-right"><?= (int) ($pm['c'] ?? 0) ?></td>
                                            <td class="text-right font-medium"><?= gelo_e(gelo_format_money($pm['total_amount'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 text-xs opacity-70">
                        Observação: pedidos entregues geram saldo; pagamentos podem ocorrer em outro período.
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-4 sm:p-8">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">Detalhamento por usuário</h2>
                    <?php if (!$canViewAll): ?>
                        <span class="badge badge-ghost">Visão própria</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm opacity-70 mt-1">Pago, a pagar, em aberto, ticket médio e devoluções por cliente (pedidos entregues).</p>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="grid gap-3 sm:grid-cols-2 w-full sm:max-w-3xl">
                        <label class="form-control w-full">
                            <div class="label py-0"><span class="label-text text-xs opacity-70">Buscar usuário</span></div>
                            <input id="userSearchInput" class="input input-bordered input-sm w-full" placeholder="Nome ou telefone" autocomplete="off" />
                        </label>

                        <label class="form-control w-full">
                            <div class="label py-0"><span class="label-text text-xs opacity-70">Ordenar por</span></div>
                            <select id="userSortSelect" class="select select-bordered select-sm w-full">
                                <option value="open_desc" selected>Em aberto (maior)</option>
                                <option value="open_asc">Em aberto (menor)</option>
                                <option value="paid_pct_desc">Quitação (maior)</option>
                                <option value="payable_desc">A pagar (maior)</option>
                                <option value="paid_desc">Pago (maior)</option>
                                <option value="orders_desc">Pedidos (maior)</option>
                                <option value="name_asc">Nome (A–Z)</option>
                            </select>
                        </label>
                    </div>

                    <div class="flex gap-2">
                        <button id="userClearBtn" class="btn btn-ghost btn-sm" type="button">Limpar</button>
                    </div>
                </div>

                <div id="userListMeta" class="mt-3 text-xs opacity-70">
                    Exibindo <span class="font-medium"><?= count($filteredSalesByUser) ?></span> usuário(s).
                </div>

                <div class="overflow-x-auto mt-4 w-full max-w-full overscroll-x-contain touch-pan-x">
                    <table class="table table-zebra table-sm" id="usersTable">
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th class="text-right">Pedidos</th>
                                <th class="text-right">Com devolução</th>
                                <th class="text-right">Ticket médio</th>
                                <th class="text-right">A pagar</th>
                                <th class="text-right">Pago</th>
                                <th class="text-right">Quitação</th>
                                <th class="text-right">Em aberto</th>
                            </tr>
                        </thead>
                        <tbody id="usersTbody">
                            <?php if (empty($filteredSalesByUser)): ?>
                                <tr id="usersEmptyRow">
                                    <td colspan="8" class="py-8 text-center opacity-70">Sem pedidos entregues no período.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filteredSalesByUser as $u): ?>
                                    <?php
                                        $uName = (string) ($u['name'] ?? '');
                                        $uPhone = (string) ($u['phone'] ?? '');
                                        $uPayable = (float) ($u['payable_total'] ?? 0);
                                        $uPaid = (float) ($u['paid_total'] ?? 0);
                                        $uOpen = (float) ($u['open_total'] ?? 0);
                                        $uOrders = (int) ($u['orders_count'] ?? 0);
                                        $uReturns = (int) ($u['orders_with_return'] ?? 0);
                                        $uPaidPct = $uPayable > 0 ? max(0.0, min(100.0, ($uPaid / $uPayable) * 100.0)) : 0.0;
                                    ?>
                                    <tr
                                        data-name="<?= gelo_e(mb_strtolower($uName)) ?>"
                                        data-phone="<?= gelo_e(mb_strtolower($uPhone)) ?>"
                                        data-open="<?= gelo_e((string) $uOpen) ?>"
                                        data-payable="<?= gelo_e((string) $uPayable) ?>"
                                        data-paid="<?= gelo_e((string) $uPaid) ?>"
                                        data-paid-pct="<?= gelo_e((string) $uPaidPct) ?>"
                                        data-orders="<?= (int) $uOrders ?>"
                                    >
                                        <td>
                                            <div class="font-medium"><?= gelo_e($uName) ?></div>
                                            <div class="text-xs opacity-70"><?= gelo_e(gelo_format_phone($uPhone)) ?></div>
                                        </td>
                                        <td class="text-right"><?= (int) $uOrders ?></td>
                                        <td class="text-right"><?= (int) $uReturns ?></td>
                                        <td class="text-right"><?= gelo_e(gelo_format_money($u['avg_order_value'] ?? 0)) ?></td>
                                        <td class="text-right font-medium"><?= gelo_e(gelo_format_money($uPayable)) ?></td>
                                        <td class="text-right"><?= gelo_e(gelo_format_money($uPaid)) ?></td>
                                        <td class="text-right"><span class="badge badge-outline badge-sm"><?= (int) round($uPaidPct) ?>%</span></td>
                                        <td class="text-right font-medium"><?= gelo_e(gelo_format_money($uOpen)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const labels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const orders = <?= json_encode($chartOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const delivered = <?= json_encode($chartDelivered, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const paid = <?= json_encode($chartPaid, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function brl(v) {
                try {
                    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
                } catch (e) {
                    return 'R$ ' + (v || 0).toFixed(2);
                }
            }

            const ordersCanvas = document.getElementById('ordersByDayChart');
            if (ordersCanvas) {
                // força altura do container do canvas
                if (ordersCanvas.parentElement) {
                    ordersCanvas.parentElement.style.height = '270px';
                }
                // reforço (caso o container não exista/tenha regras conflitantes)
                ordersCanvas.style.height = '270';

                new Chart(ordersCanvas, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Pedidos',
                            data: orders,
                            borderWidth: 0,
                            backgroundColor: 'rgba(0,0,0,0.25)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.parsed.y} pedido(s)`
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { maxRotation: 0, autoSkip: true }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { precision: 0 }
                            }
                        }
                    }
                });
            }

            const valuesCanvas = document.getElementById('valuesByDayChart');
            if (valuesCanvas) {
                // força altura do container do canvas
                if (valuesCanvas.parentElement) {
                    valuesCanvas.parentElement.style.height = '270px';
                }
                // reforço (caso o container não exista/tenha regras conflitantes)
                valuesCanvas.style.height = '270';
                new Chart(valuesCanvas, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'Entregue (líquido)',
                                data: delivered,
                                borderWidth: 0,
                                backgroundColor: 'rgba(0, 128, 0, 0.30)'
                            },
                            {
                                label: 'Recebido',
                                data: paid,
                                borderWidth: 0,
                                backgroundColor: 'rgba(0, 123, 255, 0.30)'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.dataset.label}: ${brl(ctx.parsed.y)}`
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { maxRotation: 0, autoSkip: true }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: (v) => brl(v)
                                }
                            }
                        }
                    }
                });
            }

            // Detalhamento por usuário (JS): busca + ordenação sem reload
            const searchInput = document.getElementById('userSearchInput');
            const sortSelect = document.getElementById('userSortSelect');
            const clearBtn = document.getElementById('userClearBtn');
            const tbody = document.getElementById('usersTbody');
            const meta = document.getElementById('userListMeta');
            const emptyRow = document.getElementById('usersEmptyRow');

            function setMetaCount(n) {
                if (!meta) return;
                const span = meta.querySelector('span');
                if (span) span.textContent = String(n);
            }

            function normalize(s) {
                return String(s || '').toLowerCase().trim();
            }

            function getVisibleRows(allRows, q) {
                if (!q) return allRows;
                return allRows.filter((tr) => {
                    const name = normalize(tr.getAttribute('data-name'));
                    const phone = normalize(tr.getAttribute('data-phone'));
                    return name.indexOf(q) !== -1 || phone.indexOf(q) !== -1;
                });
            }

            function compareRows(a, b, sortKey) {
                const num = (tr, attr) => {
                    const v = parseFloat(tr.getAttribute(attr) || '0');
                    return Number.isFinite(v) ? v : 0;
                };
                const int = (tr, attr) => {
                    const v = parseInt(tr.getAttribute(attr) || '0', 10);
                    return Number.isFinite(v) ? v : 0;
                };
                const name = (tr) => String(tr.getAttribute('data-name') || '');

                switch (sortKey) {
                    case 'open_asc':
                        return num(a, 'data-open') - num(b, 'data-open');
                    case 'open_desc':
                        return num(b, 'data-open') - num(a, 'data-open');
                    case 'payable_desc':
                        return num(b, 'data-payable') - num(a, 'data-payable');
                    case 'paid_desc':
                        return num(b, 'data-paid') - num(a, 'data-paid');
                    case 'paid_pct_desc':
                        return num(b, 'data-paid-pct') - num(a, 'data-paid-pct');
                    case 'orders_desc':
                        return int(b, 'data-orders') - int(a, 'data-orders');
                    case 'name_asc':
                        return name(a).localeCompare(name(b), 'pt-BR');
                    default:
                        return num(b, 'data-open') - num(a, 'data-open');
                }
            }

            function renderUserRows() {
                if (!tbody) return;
                if (emptyRow) emptyRow.style.display = 'none';

                const allRows = Array.from(tbody.querySelectorAll('tr')).filter((tr) => tr !== emptyRow);
                if (allRows.length === 0) {
                    setMetaCount(0);
                    return;
                }

                const q = normalize(searchInput ? searchInput.value : '');
                const sortKey = sortSelect ? String(sortSelect.value || 'open_desc') : 'open_desc';

                const visible = getVisibleRows(allRows, q);
                visible.sort((a, b) => compareRows(a, b, sortKey));

                // reordena e aplica visibilidade
                const frag = document.createDocumentFragment();
                visible.forEach((tr) => {
                    tr.style.display = '';
                    frag.appendChild(tr);
                });
                allRows.forEach((tr) => {
                    if (visible.indexOf(tr) === -1) tr.style.display = 'none';
                });
                tbody.appendChild(frag);

                setMetaCount(visible.length);
                if (emptyRow) {
                    emptyRow.style.display = visible.length === 0 ? '' : 'none';
                }
            }

            if (searchInput) {
                searchInput.addEventListener('input', renderUserRows);
            }
            if (sortSelect) {
                sortSelect.addEventListener('change', renderUserRows);
            }
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    if (searchInput) searchInput.value = '';
                    if (sortSelect) sortSelect.value = 'open_desc';
                    renderUserRows();
                });
            }
            renderUserRows();
        })();
    </script>
</body>
</html>
