<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_auth();

$user = gelo_current_user();
$userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
$canWithdrawals = gelo_has_permission('withdrawals.access');

$myWithdrawalCounts = [
    'requested' => 0,
    'separated' => 0,
    'delivered' => 0,
    'cancelled' => 0,
];

$openAmount = '0.00';
$openOrders = 0;
$deliveredLast7 = 0;
$cancelledLast30 = 0;
$recentOrders = [];
$chartDays = [];

if ($canWithdrawals && $userId > 0) {
    try {
        $pdo = gelo_pdo();

        $stmt = $pdo->prepare('
            SELECT status, COUNT(*) AS c
            FROM withdrawal_orders
            WHERE user_id = :id
            GROUP BY status
        ');
        $stmt->execute(['id' => $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $status = isset($row['status']) ? (string) $row['status'] : '';
            if (!array_key_exists($status, $myWithdrawalCounts)) {
                continue;
            }
            $myWithdrawalCounts[$status] = (int) ($row['c'] ?? 0);
        }

        // Financeiro: valor em aberto (somente pedidos entregues)
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(GREATEST(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0), 0)), 0) AS open_amount,
                COALESCE(SUM(CASE WHEN GREATEST(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0), 0) > 0 THEN 1 ELSE 0 END), 0) AS open_orders
            FROM withdrawal_orders o
            LEFT JOIN (
                SELECT r.order_id, COALESCE(SUM(ri.line_total), 0) AS returned_amount
                FROM withdrawal_returns r
                INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
                GROUP BY r.order_id
            ) ret ON ret.order_id = o.id
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(amount), 0) AS paid_amount
                FROM withdrawal_payments
                GROUP BY order_id
            ) pay ON pay.order_id = o.id
            WHERE o.user_id = :id AND o.status = 'delivered'
        ");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $openAmount = (string) ($row['open_amount'] ?? '0.00');
            $openOrders = (int) ($row['open_orders'] ?? 0);
        }

        // Contadores por período
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM withdrawal_orders WHERE user_id = :id AND status = 'delivered' AND delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        $deliveredLast7 = is_array($row) ? (int) ($row['c'] ?? 0) : 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM withdrawal_orders WHERE user_id = :id AND status = 'cancelled' AND cancelled_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        $cancelledLast30 = is_array($row) ? (int) ($row['c'] ?? 0) : 0;

        // Pedidos recentes (para atividade) + situação de pagamento
        $stmt = $pdo->prepare('
            SELECT
                o.id,
                o.status,
                o.total_items,
                o.total_amount,
                o.created_at,
                COALESCE(ret.returned_amount, 0) AS returned_amount,
                COALESCE(pay.paid_amount, 0) AS paid_amount
            FROM withdrawal_orders o
            LEFT JOIN (
                SELECT r.order_id, COALESCE(SUM(ri.line_total), 0) AS returned_amount
                FROM withdrawal_returns r
                INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
                GROUP BY r.order_id
            ) ret ON ret.order_id = o.id
            LEFT JOIN (
                SELECT order_id, COALESCE(SUM(amount), 0) AS paid_amount
                FROM withdrawal_payments
                GROUP BY order_id
            ) pay ON pay.order_id = o.id
            WHERE o.user_id = :id
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 6
        ');
        $stmt->execute(['id' => $userId]);
        $recentOrders = $stmt->fetchAll();

        // Gráfico (R$): valor entregue líquido (total - devoluções) por dia (últimos 7 dias)
        $chartDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $chartDays[$date] = [
                'label' => date('d/m', strtotime($date)),
                'value' => '0.00',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT
                DATE(o.delivered_at) AS d,
                COALESCE(SUM(GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0)), 0) AS v
            FROM withdrawal_orders o
            LEFT JOIN (
                SELECT r.order_id, COALESCE(SUM(ri.line_total), 0) AS returned_amount
                FROM withdrawal_returns r
                INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
                GROUP BY r.order_id
            ) ret ON ret.order_id = o.id
            WHERE o.user_id = :id
              AND o.status = 'delivered'
              AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(o.delivered_at)
        ");
        $stmt->execute(['id' => $userId]);
        foreach ($stmt->fetchAll() as $r) {
            $d = isset($r['d']) ? (string) $r['d'] : '';
            if ($d !== '' && isset($chartDays[$d])) {
                $chartDays[$d]['value'] = (string) ($r['v'] ?? '0.00');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$pageTitle = 'Dashboard · GELO';
$activePage = 'dashboard';
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate" class="overflow-x-hidden">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200 overflow-x-hidden">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto max-w-6xl p-4 sm:p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Olá, <?= gelo_e((string) ($user['name'] ?? 'usuário')) ?>.</h1>
                <p class="text-sm opacity-70 mt-1">Acompanhe seus pedidos, status e valores.</p>
            </div>
            <?php if (!empty($user['role'])): ?>
                <div class="badge badge-primary badge-outline self-start sm:self-end max-w-full">
                    <span class="min-w-0 truncate"><?= gelo_e((string) $user['role']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <?php if ($canWithdrawals): ?>
                    <?php
                        $inProgress = (int) $myWithdrawalCounts['requested'] + (int) $myWithdrawalCounts['separated'];
                        $isOpen = bccomp((string) $openAmount, '0.00', 2) === 1;

                        $statusLabelMap = [
                            'requested' => ['Solicitado', 'badge-warning badge-outline'],
                            'separated' => ['Separado', 'badge-info badge-outline'],
                            'delivered' => ['Entregue', 'badge-success badge-outline'],
                            'cancelled' => ['Cancelado', 'badge-ghost'],
                        ];

                        $chartMax = 0.0;
                        $chartTotal = 0.0;
                        $chartBestValue = -1.0;
                        $chartBestLabel = '—';
                        $chartJsLabels = [];
                        $chartJsValues = [];

                        foreach ($chartDays as $it) {
                            $v = isset($it['value']) ? (float) $it['value'] : 0.0;
                            $label = isset($it['label']) ? (string) $it['label'] : '';

                            $chartJsLabels[] = $label;
                            $chartJsValues[] = round($v, 2);
                            $chartTotal += $v;

                            if ($v > $chartMax) {
                                $chartMax = $v;
                            }
                            if ($v > $chartBestValue) {
                                $chartBestValue = $v;
                                $chartBestLabel = $label;
                            }
                        }

                        $chartAvg = count($chartJsValues) > 0 ? ($chartTotal / count($chartJsValues)) : 0.0;
                        $chartTotalStr = number_format($chartTotal, 2, '.', '');
                        $chartAvgStr = number_format($chartAvg, 2, '.', '');
                        $chartBestStr = number_format(max(0.0, $chartBestValue), 2, '.', '');
                    ?>

                    <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                        <div class="card-body p-5 sm:p-6">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 class="text-lg font-semibold">Seu resumo</h2>
                                    <p class="text-sm opacity-70 -mt-1">Pedidos, pendências e valor em aberto.</p>
                                </div>
                                <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?mine=1') ?>">Ver tudo</a>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                <div class="rounded-box border border-base-200 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-xs uppercase tracking-wide opacity-60">Em aberto</div>
                                            <div class="mt-1 text-xl sm:text-2xl font-semibold text-primary"><?= gelo_e(gelo_format_money($openAmount)) ?></div>
                                        </div>
                                        <div class="rounded-full bg-base-200 p-2 <?= $isOpen ? 'text-warning' : 'text-success' ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <span class="badge <?= $isOpen ? 'badge-warning badge-outline' : 'badge-success badge-outline' ?>"><?= $isOpen ? 'Pendente' : 'Ok' ?></span>
                                        <span class="text-xs opacity-70"><?= (int) $openOrders ?> pedido(s)</span>
                                    </div>
                                </div>

                                <div class="rounded-box border border-base-200 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-xs uppercase tracking-wide opacity-60">Em andamento</div>
                                            <div class="mt-1 text-xl sm:text-2xl font-semibold"><?= (int) $inProgress ?></div>
                                        </div>
                                        <div class="rounded-full bg-base-200 p-2 text-info">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6l4 2m5-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs opacity-70">Solicitados + separados</div>
                                </div>

                                <div class="rounded-box border border-base-200 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-xs uppercase tracking-wide opacity-60">Últimos 7 dias</div>
                                            <div class="mt-1 text-xl sm:text-2xl font-semibold"><?= (int) $deliveredLast7 ?></div>
                                        </div>
                                        <div class="rounded-full bg-base-200 p-2 text-success">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs opacity-70">Entregues (<?= (int) $cancelledLast30 ?> cancelados em 30 dias)</div>
                                </div>
                            </div>

                            <div class="mt-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                    <div class="min-w-0">
                                        <h3 class="font-semibold">R$ entregues (últimos 7 dias)</h3>
                                        <span class="text-xs opacity-70">Total líquido (total − devoluções)</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2 sm:justify-end">
	                                        <span class="badge badge-outline max-w-full">
                                                <span class="min-w-0 truncate">Total 7d: <?= gelo_e(gelo_format_money($chartTotalStr)) ?></span>
                                            </span>
	                                        <span class="badge badge-outline max-w-full">
                                                <span class="min-w-0 truncate">Média/dia: <?= gelo_e(gelo_format_money($chartAvgStr)) ?></span>
                                            </span>
	                                        <span class="badge badge-outline max-w-full">
                                                <span class="min-w-0 truncate">Melhor dia: <?= gelo_e($chartBestLabel) ?> · <?= gelo_e(gelo_format_money($chartBestStr)) ?></span>
                                            </span>
	                                    </div>
	                                </div>

	                                <div class="mt-3 rounded-box border border-base-200 bg-base-100 p-4">
	                                    <div class="h-56 sm:h-64 overflow-hidden">
	                                        <div id="deliveries7dFallback" class="flex h-full items-end gap-2 w-full px-1 pb-1">
	                                            <?php foreach ($chartDays as $d => $it): ?>
	                                                <?php
	                                                    $value = (string) ($it['value'] ?? '0.00');
	                                                    $floatVal = (float) $value;

                                                    $barPx = 0;
                                                    if ($chartMax > 0) {
                                                        $barPx = (int) round(($floatVal / $chartMax) * 120);
                                                    }
                                                    $barPx = max(4, min(128, $barPx));
                                                ?>
                                                <div class="flex flex-col items-center justify-end gap-2 flex-1 min-w-0">
	                                                    <div class="text-[10px] opacity-70 truncate"><?= gelo_e(gelo_format_money($value)) ?></div>
	                                                    <div class="w-full h-32 rounded-box bg-base-200 overflow-hidden flex items-end" title="<?= gelo_e($it['label'] ?? '') ?> · <?= gelo_e(gelo_format_money($value)) ?>">
	                                                        <div class="w-full bg-primary" style="height: <?= (int) $barPx ?>px"></div>
	                                                    </div>
	                                                    <div class="text-[11px] opacity-70 truncate"><?= gelo_e((string) ($it['label'] ?? '')) ?></div>
	                                                </div>
	                                            <?php endforeach; ?>
	                                        </div>

	                                        <canvas
	                                            id="deliveries7dChart"
	                                            class="w-full h-full hidden"
	                                            aria-label="Gráfico de valores entregues nos últimos 7 dias"
	                                            role="img"
	                                        ></canvas>
	                                    </div>

                                    <div class="mt-3 text-xs opacity-70">Dica: passe o mouse/toque nos pontos para ver valores.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <span>Você não tem acesso a Retiradas.</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="space-y-6">
                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body">
                        <h2 class="text-lg font-semibold">Ações rápidas</h2>
                        <p class="text-sm opacity-70 -mt-1">Atalhos para suas retiradas.</p>

                        <?php if (!$canWithdrawals): ?>
                            <div class="alert alert-warning mt-4">
                                <span>Você não tem acesso a Retiradas.</span>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 grid gap-3">
                                <a class="btn btn-primary w-full" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal_new.php') ?>">Novo pedido</a>

                                <a class="btn btn-outline justify-between" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?status=requested&mine=1') ?>">
                                    <span>Solicitados</span>
                                    <span class="badge badge-warning badge-outline"><?= (int) $myWithdrawalCounts['requested'] ?></span>
                                </a>
                                <a class="btn btn-outline justify-between" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?status=separated&mine=1') ?>">
                                    <span>Separados</span>
                                    <span class="badge badge-info badge-outline"><?= (int) $myWithdrawalCounts['separated'] ?></span>
                                </a>
                                <a class="btn btn-outline justify-between" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?status=delivered&mine=1') ?>">
                                    <span>Entregues</span>
                                    <span class="badge badge-success badge-outline"><?= (int) $myWithdrawalCounts['delivered'] ?></span>
                                </a>
                                <a class="btn btn-outline justify-between" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?status=cancelled&mine=1') ?>">
                                    <span>Cancelados</span>
                                    <span class="badge badge-ghost"><?= (int) $myWithdrawalCounts['cancelled'] ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php if ($canWithdrawals): ?>
            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold">Pedidos recentes</h2>
                        <a class="btn btn-ghost btn-sm" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php?mine=1') ?>">Ver tudo</a>
                    </div>
                    <p class="text-sm opacity-70 -mt-1">Seus últimos pedidos, com status e valor.</p>

                    <div class="mt-4 rounded-box border border-base-200 bg-base-100">
                        <?php if (empty($recentOrders)): ?>
                            <div class="px-4 py-8 text-center opacity-70">Nenhum pedido encontrado.</div>
                        <?php else: ?>
                            <?php foreach ($recentOrders as $idx => $o): ?>
                                <?php
                                    $rid = (int) ($o['id'] ?? 0);
                                    $rst = (string) ($o['status'] ?? '');
                                    [$rstLabel, $rstBadge] = $statusLabelMap[$rst] ?? ['—', 'badge-ghost'];
                                    $rCreatedAt = isset($o['created_at']) ? (string) $o['created_at'] : '';
                                    $rCreatedLabel = $rCreatedAt !== '' ? date('d/m/Y H:i', strtotime($rCreatedAt)) : '—';
                                    $rTotal = (string) ($o['total_amount'] ?? '0.00');
                                    $rItems = (int) ($o['total_items'] ?? 0);
                                    $rReturned = (string) ($o['returned_amount'] ?? '0.00');
                                    $rPaid = (string) ($o['paid_amount'] ?? '0.00');

                                    $net = max(0.0, round(((float) $rTotal) - ((float) $rReturned), 2));
                                    $balance = max(0.0, round($net - ((float) $rPaid), 2));

                                    $payLabel = 'Aguardando entrega';
                                    $payBadge = 'badge-ghost';
                                    if ($rst === 'cancelled') {
                                        $payLabel = 'Não aplicável';
                                        $payBadge = 'badge-ghost';
                                    } elseif ($rst === 'delivered') {
                                        if ($net <= 0.0) {
                                            $payLabel = 'Sem saldo';
                                            $payBadge = 'badge-ghost';
                                        } elseif ($balance <= 0.0) {
                                            $payLabel = 'Pago';
                                            $payBadge = 'badge-success badge-outline';
                                        } elseif ((float) $rPaid > 0.0) {
                                            $payLabel = 'Parcial';
                                            $payBadge = 'badge-warning badge-outline';
                                        } else {
                                            $payLabel = 'Em aberto';
                                            $payBadge = 'badge-warning badge-outline';
                                        }
                                    }
                                ?>

                                <a class="flex flex-col gap-3 px-4 py-4 hover:bg-base-200/60 sm:flex-row sm:items-center sm:justify-between" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal.php?id=' . (int) $rid) ?>">
                                    <div class="min-w-0">
                                        <div class="font-medium">#<?= (int) $rid ?> · <?= gelo_e(gelo_format_money($rTotal)) ?></div>
                                        <div class="text-xs opacity-70"><?= gelo_e($rCreatedLabel) ?> · <?= (int) $rItems ?> item(ns)</div>
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div class="text-right min-w-0">
                                            <div class="text-[10px] uppercase tracking-wide opacity-60">Status</div>
                                            <span class="badge <?= gelo_e($rstBadge) ?> max-w-full">
                                                <span class="min-w-0 truncate"><?= gelo_e($rstLabel) ?></span>
                                            </span>
                                        </div>
                                        <div class="text-right min-w-0">
                                            <div class="text-[10px] uppercase tracking-wide opacity-60">Pagamento</div>
                                            <span class="badge <?= gelo_e($payBadge) ?> max-w-full">
                                                <span class="min-w-0 truncate"><?= gelo_e($payLabel) ?></span>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                                <?php if ($idx < count($recentOrders) - 1): ?>
                                    <div class="divider my-0"></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($canWithdrawals): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
          (() => {
            const labels = <?= json_encode($chartJsLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const values = <?= json_encode($chartJsValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const supportsOklch =
              typeof CSS !== "undefined" &&
              typeof CSS.supports === "function" &&
              CSS.supports("color", "oklch(0% 0 0)");

            const formatMoney = (value) => {
              const safe = typeof value === "number" && Number.isFinite(value) ? value : 0;
              return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(safe);
            };

            const hexToRgb = (hex) => {
              const raw = String(hex || "").trim().replace("#", "");
              if (raw.length !== 3 && raw.length !== 6) return null;

              const normalized = raw.length === 3 ? raw.split("").map((c) => c + c).join("") : raw;
              const n = Number.parseInt(normalized, 16);
              if (!Number.isFinite(n)) return null;

              return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
            };

            const themeColor = (varName, fallbackVarName, alpha, fallbackHex) => {
              const rootStyles = getComputedStyle(document.documentElement);
              const varValue = String(rootStyles.getPropertyValue(varName) || "").trim();
              if (supportsOklch && varValue) return `oklch(${varValue} / ${alpha})`;

              const fallback = String(rootStyles.getPropertyValue(fallbackVarName) || "").trim() || fallbackHex;
              if (alpha >= 1) return fallback;

              const rgb = hexToRgb(fallback);
              if (!rgb) return fallback;
              return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
            };

            document.addEventListener("DOMContentLoaded", () => {
              const canvas = document.getElementById("deliveries7dChart");
              const fallback = document.getElementById("deliveries7dFallback");
              if (!canvas || typeof Chart === "undefined") return;

              canvas.classList.remove("hidden");
              if (fallback) fallback.classList.add("hidden");

              requestAnimationFrame(() => {
                const ctx = canvas.getContext("2d");
                if (!ctx) return;

                const primarySolid = themeColor("--p", "--fallback-p", 1, "#4f46e5");
                const primarySoft = themeColor("--p", "--fallback-p", 0.22, "#4f46e5");
                const primaryTransparent = themeColor("--p", "--fallback-p", 0, "#4f46e5");

                const content = themeColor("--bc", "--fallback-bc", 0.78, "#111827");
                const grid = themeColor("--bc", "--fallback-bc", 0.12, "#111827");

                const gradientHeight = Math.max(240, canvas.clientHeight || 0);
                const gradient = ctx.createLinearGradient(0, 0, 0, gradientHeight);
                gradient.addColorStop(0, primarySoft);
                gradient.addColorStop(1, primaryTransparent);

                const maxValue = Math.max(0, ...values.map((n) => (typeof n === "number" ? n : 0)));
                const suggestedMax = maxValue > 0 ? maxValue * 1.15 : 1;

                new Chart(ctx, {
                  type: "line",
                  data: {
                    labels,
                    datasets: [
                      {
                        label: "Total líquido",
                        data: values,
                        borderColor: primarySolid,
                        backgroundColor: gradient,
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: primarySolid,
                        pointBorderColor: primarySolid,
                      },
                    ],
                  },
                  options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: "index", intersect: false },
                    plugins: {
                      legend: { display: false },
                      tooltip: {
                        backgroundColor: "rgba(17, 24, 39, 0.92)",
                        titleColor: "#fff",
                        bodyColor: "#fff",
                        displayColors: false,
                        callbacks: {
                          title: (items) => (items && items[0] ? String(items[0].label || "") : ""),
                          label: (item) => {
                            const parsed = item && item.parsed ? item.parsed : null;
                            const y = parsed && typeof parsed.y === "number" ? parsed.y : 0;
                            return formatMoney(y);
                          },
                        },
                      },
                    },
                    scales: {
                      x: {
                        grid: { display: false },
                        ticks: { color: content, maxRotation: 0, autoSkip: true },
                      },
                      y: {
                        beginAtZero: true,
                        suggestedMax,
                        grid: { color: grid, drawBorder: false },
                        ticks: {
                          color: content,
                          callback: (v) => formatMoney(Number(v)),
                        },
                      },
                    },
                  },
                });
              });
            });
          })();
        </script>
    <?php endif; ?>
</body>
</html>
