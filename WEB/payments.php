<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('withdrawals.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Pagamentos · GELO';
$activePage = 'payments';
$success = gelo_flash_get('success');
$error = gelo_flash_get('error');

$user = gelo_current_user();
$sessionUserId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
$canViewAll = gelo_has_permission('withdrawals.view_all');
$canPay = gelo_has_permission('withdrawals.pay');

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

$openTotal = '0.00';
$usersOpen = [];
$openOrdersByUser = [];

try {
    $pdo = gelo_pdo();

    if ($canViewAll) {
        $stmt = $pdo->query('
            SELECT
                u.id,
                u.name,
                u.phone,
                COALESCE(SUM(
                    GREATEST(
                        GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0),
                        0
                    )
                ), 0) AS open_total
            FROM users u
            LEFT JOIN withdrawal_orders o ON o.user_id = u.id AND o.status IN (\'saida\', \'delivered\')
            ' . $returnsJoin . '
            ' . $paymentsJoin . '
            GROUP BY u.id, u.name, u.phone
            ORDER BY u.name ASC
            LIMIT 500
        ');
        $usersOpen = $stmt->fetchAll();

        $userIds = [];
        foreach ($usersOpen as $u) {
            if (!is_array($u)) {
                continue;
            }
            $uid = (int) ($u['id'] ?? 0);
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }
        $userIds = array_values(array_unique($userIds));

        if (!empty($userIds)) {
            $placeholders = [];
            $bind = [];
            foreach ($userIds as $idx => $uid) {
                $key = 'u' . $idx;
                $placeholders[] = ':' . $key;
                $bind[$key] = $uid;
            }

            $sql = '
                SELECT
                    o.user_id,
                    o.id,
                    COALESCE(o.delivered_at, o.created_at) AS dt,
                    GREATEST(
                        GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0),
                        0
                    ) AS open_amount
                FROM withdrawal_orders o
                ' . $returnsJoin . '
                ' . $paymentsJoin . '
                WHERE o.user_id IN (' . implode(',', $placeholders) . ')
                  AND o.status IN (\'saida\', \'delivered\')
                HAVING open_amount > 0
                ORDER BY o.user_id ASC, dt ASC, o.id ASC
            ';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $rows = $stmt->fetchAll();

            $capPerUser = 30;
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $uid = (int) ($r['user_id'] ?? 0);
                $oid = (int) ($r['id'] ?? 0);
                if ($uid <= 0 || $oid <= 0) {
                    continue;
                }
                if (!isset($openOrdersByUser[$uid])) {
                    $openOrdersByUser[$uid] = [];
                }
                if (count($openOrdersByUser[$uid]) >= $capPerUser) {
                    continue;
                }
                $openOrdersByUser[$uid][] = [
                    'id' => $oid,
                    'dt' => (string) ($r['dt'] ?? ''),
                    'open' => (string) ($r['open_amount'] ?? '0.00'),
                ];
            }
        }
    } else {
        $stmt = $pdo->prepare('
            SELECT
                COALESCE(SUM(
                    GREATEST(
                        GREATEST(o.total_amount - COALESCE(ret.returned_amount, 0), 0) - COALESCE(pay.paid_amount, 0),
                        0
                    )
                ), 0) AS open_total
            FROM withdrawal_orders o
            ' . $returnsJoin . '
            ' . $paymentsJoin . '
            WHERE o.user_id = :uid
              AND o.status IN (\'saida\', \'delivered\')
        ');
        $stmt->execute(['uid' => $sessionUserId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $openTotal = (string) ($row['open_total'] ?? '0.00');
        }
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar pagamentos. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Pagamentos</h1>
                <p class="text-sm opacity-70 mt-1"><?= $canViewAll ? 'Saldo em aberto por cliente e registro de pagamentos.' : 'Consulte seu histórico e saldo em aberto.' ?></p>
            </div>
            <?php if (!$canViewAll): ?>
                <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payment_history.php') ?>">Ver histórico</a>
            <?php endif; ?>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4"><span><?= gelo_e($success) ?></span></div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4"><span><?= gelo_e($error) ?></span></div>
        <?php endif; ?>

        <?php if ($canViewAll): ?>
            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
                <div class="card-body p-4 sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <label class="input input-bordered flex items-center gap-2 w-full sm:max-w-md">
                            <input id="paymentsUserSearchInput" class="grow" type="text" placeholder="Buscar por nome ou telefone" />
                            <span class="opacity-60 text-sm">⌕</span>
                        </label>

                        <div class="flex items-center gap-3 justify-between sm:justify-end">
                            <div id="paymentsUserListMeta" class="text-xs opacity-70">Mostrando <span>0</span></div>
                            <select id="paymentsUserSortSelect" class="select select-bordered select-sm">
                                <option value="name_asc" selected>Ordenar: Nome</option>
                                <option value="open_desc">Ordenar: Em aberto (maior)</option>
                                <option value="open_asc">Ordenar: Em aberto (menor)</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Telefone</th>
                                    <th class="text-right">Em aberto</th>
                                    <th class="text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($usersOpen)): ?>
                                    <tr>
                                        <td colspan="4" class="py-8 text-center opacity-70">Nenhum usuário encontrado.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($usersOpen as $u): ?>
                                        <?php
                                            $uid = (int) ($u['id'] ?? 0);
                                            $name = (string) ($u['name'] ?? '');
                                            $phone = (string) ($u['phone'] ?? '');
                                            $open = (string) ($u['open_total'] ?? '0.00');
                                            $openOrders = $openOrdersByUser[$uid] ?? [];
                                            $openOrdersJson = json_encode($openOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                        ?>
                                        <tr
                                            data-user-id="<?= (int) $uid ?>"
                                            data-user-name="<?= gelo_e($name) ?>"
                                            data-user-phone="<?= gelo_e($phone) ?>"
                                            data-open="<?= gelo_e($open) ?>"
                                            data-open-orders="<?= gelo_e(is_string($openOrdersJson) ? $openOrdersJson : '[]') ?>"
                                        >
                                            <td class="font-medium"><?= gelo_e($name) ?></td>
                                            <td><?= gelo_e(gelo_format_phone($phone)) ?></td>
                                            <td class="text-right">
                                                <button type="button" class="btn btn-ghost btn-sm payments-open-btn">
                                                    <span class="font-semibold"><?= gelo_e(gelo_format_money($open)) ?></span>
                                                </button>
                                            </td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <a class="btn btn-sm btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payment_history.php?user_id=' . $uid) ?>">Histórico</a>
                                                    <?php if ($canPay && bccomp($open, '0.00', 2) === 1): ?>
                                                        <a class="btn btn-sm btn-primary" href="<?= gelo_e(GELO_BASE_URL . '/payment_new.php?user_id=' . $uid) ?>">Registrar pagamento</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <tr id="paymentsEmptyRow" class="hidden">
                                        <td colspan="4" class="py-8 text-center opacity-70">Nenhum usuário encontrado.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <input type="checkbox" id="paymentsOpenModal" class="modal-toggle" />
            <div class="modal" role="dialog">
                <div class="modal-box">
                    <h3 id="paymentsOpenModalTitle" class="font-semibold text-lg">Em aberto</h3>
                    <div id="paymentsOpenModalBody" class="mt-4 text-sm"></div>
                    <div class="modal-action">
                        <label for="paymentsOpenModal" class="btn">Fechar</label>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
                <div class="card-body p-4 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-wide opacity-60">Em aberto</div>
                            <div class="mt-1 text-2xl font-semibold leading-none"><?= gelo_e(gelo_format_money($openTotal)) ?></div>
                        </div>
                        <a class="btn btn-outline" href="<?= gelo_e(GELO_BASE_URL . '/payment_history.php') ?>">Ver histórico</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($canViewAll): ?>
        <script>
            (function () {
                const searchInput = document.getElementById('paymentsUserSearchInput');
                const sortSelect = document.getElementById('paymentsUserSortSelect');
                const meta = document.getElementById('paymentsUserListMeta');
                const emptyRow = document.getElementById('paymentsEmptyRow');
                const tbody = document.querySelector('table tbody');
                if (!tbody) return;

                const rows = Array.from(tbody.querySelectorAll('tr')).filter((tr) => tr.dataset && tr.dataset.userId);

                function parseOpen(v) {
                    const n = Number(String(v || '0').replace(',', '.'));
                    return Number.isFinite(n) ? n : 0;
                }

                function brl(v) {
                    try {
                        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v || 0);
                    } catch (e) {
                        return 'R$ ' + (v || 0).toFixed(2);
                    }
                }

                function setMetaCount(n) {
                    if (!meta) return;
                    const span = meta.querySelector('span');
                    if (span) span.textContent = String(n);
                }

                function applyFilterAndSort() {
                    const q = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
                    const sort = sortSelect ? String(sortSelect.value || 'name_asc') : 'name_asc';

                    const visible = [];
                    for (const tr of rows) {
                        const name = String(tr.dataset.userName || '').toLowerCase();
                        const phone = String(tr.dataset.userPhone || '').toLowerCase();
                        const match = q === '' || name.includes(q) || phone.includes(q);
                        tr.classList.toggle('hidden', !match);
                        if (match) visible.push(tr);
                    }

                    visible.sort((a, b) => {
                        if (sort === 'open_desc') {
                            return parseOpen(b.dataset.open) - parseOpen(a.dataset.open);
                        }
                        if (sort === 'open_asc') {
                            return parseOpen(a.dataset.open) - parseOpen(b.dataset.open);
                        }
                        return String(a.dataset.userName || '').localeCompare(String(b.dataset.userName || ''), 'pt-BR');
                    });

                    for (const tr of visible) {
                        tbody.appendChild(tr);
                    }

                    setMetaCount(visible.length);
                    if (emptyRow) {
                        emptyRow.classList.toggle('hidden', visible.length !== 0);
                        tbody.appendChild(emptyRow);
                    }
                }

                if (searchInput) searchInput.addEventListener('input', applyFilterAndSort);
                if (sortSelect) sortSelect.addEventListener('change', applyFilterAndSort);

                // Modal "Em aberto"
                const modalToggle = document.getElementById('paymentsOpenModal');
                const modalTitle = document.getElementById('paymentsOpenModalTitle');
                const modalBody = document.getElementById('paymentsOpenModalBody');

                function openModalForRow(tr) {
                    if (!modalToggle || !modalBody || !modalTitle) return;

                    const uid = String(tr.dataset.userId || '');
                    const name = String(tr.dataset.userName || '');
                    const openTotal = parseOpen(tr.dataset.open);
                    let orders = [];
                    try {
                        orders = JSON.parse(String(tr.dataset.openOrders || '[]'));
                        if (!Array.isArray(orders)) orders = [];
                    } catch (e) {
                        orders = [];
                    }

                    modalTitle.textContent = 'Em aberto · ' + (name !== '' ? name : ('Usuário #' + uid));

                    if (!orders.length) {
                        modalBody.innerHTML = '<div class="opacity-70">Nenhum pedido em aberto.</div>';
                        modalToggle.checked = true;
                        return;
                    }

                    const rowsHtml = orders.map((o) => {
                        const oid = Number(o.id || 0);
                        const dt = String(o.dt || '');
                        const open = parseOpen(o.open);
                        let dtLabel = '';
                        try {
                            dtLabel = dt ? new Date(dt.replace(' ', 'T')).toLocaleDateString('pt-BR') : '';
                        } catch (e) {
                            dtLabel = '';
                        }
                        const href = <?= json_encode(GELO_BASE_URL . '/withdrawal.php?id=') ?> + String(oid);
                        return (
                            '<tr>' +
                            '<td class="font-medium">#' + oid + '</td>' +
                            '<td>' + dtLabel + '</td>' +
                            '<td class="text-right font-semibold">' + brl(open) + '</td>' +
                            '<td class="text-right"><a class="btn btn-xs btn-outline" href="' + href + '">Ver</a></td>' +
                            '</tr>'
                        );
                    }).join('');

                    modalBody.innerHTML =
                        '<div class="flex items-center justify-between gap-3">' +
                            '<div class="text-sm opacity-70">Total em aberto</div>' +
                            '<div class="font-semibold">' + brl(openTotal) + '</div>' +
                        '</div>' +
                        '<div class="overflow-x-auto mt-4">' +
                            '<table class="table table-sm">' +
                                '<thead><tr><th>Pedido</th><th>Data</th><th class="text-right">Em aberto</th><th class="text-right">Ações</th></tr></thead>' +
                                '<tbody>' + rowsHtml + '</tbody>' +
                            '</table>' +
                        '</div>';

                    modalToggle.checked = true;
                }

                tbody.addEventListener('click', (ev) => {
                    const btn = ev.target && ev.target.closest ? ev.target.closest('.payments-open-btn') : null;
                    if (!btn) return;
                    const tr = btn.closest('tr');
                    if (!tr) return;
                    openModalForRow(tr);
                });

                applyFilterAndSort();
            })();
        </script>
    <?php endif; ?>
</body>
</html>
