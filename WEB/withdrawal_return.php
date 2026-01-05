<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.return']);
require_once __DIR__ . '/../API/config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    gelo_flash_set('error', 'Pedido inválido.');
    gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
}

$pageTitle = 'Retorno · Retirada #' . $id;
$activePage = 'withdrawals';

$error = gelo_flash_get('error');
$success = gelo_flash_get('success');

$canViewAll = gelo_has_permission('withdrawals.view_all');
$sessionUser = gelo_current_user();
$sessionUserId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;

$order = null;
$items = [];
$paymentCount = 0;
$returnedAmount = '0.00';

try {
    $pdo = gelo_pdo();

    $stmt = $pdo->prepare('
        SELECT
            o.id,
            o.user_id,
            o.status,
            o.total_amount,
            o.total_items,
            o.created_at,
            o.delivered_at,
            u.name AS user_name,
            u.phone AS user_phone
        FROM withdrawal_orders o
        INNER JOIN users u ON u.id = o.user_id
        WHERE o.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $order = $stmt->fetch();
    if (!is_array($order)) {
        gelo_flash_set('error', 'Pedido não encontrado.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    if (!$canViewAll && (int) ($order['user_id'] ?? 0) !== $sessionUserId) {
        gelo_flash_set('error', 'Você não tem permissão para acessar este pedido.');
        gelo_redirect(GELO_BASE_URL . '/withdrawals.php');
    }

    if ((string) ($order['status'] ?? '') !== 'saida') {
        gelo_flash_set('error', 'Retorno só é permitido após o pedido ter saída.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM withdrawal_payments WHERE order_id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    $paymentCount = is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    if ($paymentCount > 0) {
        gelo_flash_set('error', 'Não é possível registrar retorno após iniciar pagamentos.');
        gelo_redirect(GELO_BASE_URL . '/withdrawal.php?id=' . $id);
    }

	    $stmt = $pdo->prepare('
	        SELECT
	            oi.product_id,
	            oi.product_title,
	            oi.unit_price,
	            oi.quantity,
	            COALESCE(ret.returned_qty, 0) AS returned_qty
	        FROM withdrawal_order_items oi
	        LEFT JOIN (
	            SELECT
	                ri.product_id,
	                SUM(ri.quantity) AS returned_qty,
	                SUM(ri.line_total) AS returned_total
	            FROM withdrawal_returns r
	            INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
	            WHERE r.order_id = :order_id_returns
	            GROUP BY ri.product_id
	        ) ret ON ret.product_id = oi.product_id
	        WHERE oi.order_id = :order_id
	        ORDER BY oi.id ASC
	    ');
	    $stmt->execute(['order_id' => $id, 'order_id_returns' => $id]);
	    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(ri.line_total), 0) AS returned_amount
        FROM withdrawal_returns r
        INNER JOIN withdrawal_return_items ri ON ri.return_id = r.id
        WHERE r.order_id = :id
    ');
    $stmt->execute(['id' => $id]);
    $ret = $stmt->fetch();
    if (is_array($ret) && isset($ret['returned_amount'])) {
        $returnedAmount = (string) $ret['returned_amount'];
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar pedido. Verifique o banco e as migrações.';
}

$orderTotal = is_array($order) ? (string) ($order['total_amount'] ?? '0.00') : '0.00';
$netTotal = bcsub($orderTotal, $returnedAmount, 2);
if (bccomp($netTotal, '0.00', 2) < 0) {
    $netTotal = '0.00';
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
                <h1 class="text-2xl font-semibold tracking-tight">Registrar retorno</h1>
                <p class="text-sm opacity-70 mt-1">Pedido #<?= (int) $id ?> · <?= gelo_e((string) ($order['user_name'] ?? '')) ?></p>
            </div>
            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal.php?id=' . (int) $id) ?>">Voltar</a>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4">
                <span><?= gelo_e($success) ?></span>
            </div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4">
                <span><?= gelo_e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (is_array($order)): ?>
            <form class="mt-6 grid gap-6 lg:grid-cols-3" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/withdrawal_return_create.php') ?>" id="returnForm">
                <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">

                <div class="lg:col-span-2 space-y-6">
                    <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                        <div class="card-body p-6 sm:p-8">
                            <h2 class="text-lg font-semibold">Itens do pedido</h2>
                            <p class="text-sm opacity-70 mt-1">Informe as quantidades devolvidas agora.</p>

                            <div class="overflow-x-auto mt-4">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Produto</th>
                                            <th class="text-right">Preço</th>
                                            <th class="text-right">Qtd</th>
                                            <th class="text-right">Já devolvida</th>
                                            <th class="text-right">Disponível</th>
                                            <th class="text-right">Devolver</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $it): ?>
                                            <?php
                                                $pid = (int) ($it['product_id'] ?? 0);
                                                $orderedQty = (int) ($it['quantity'] ?? 0);
                                                $alreadyReturned = (int) ($it['returned_qty'] ?? 0);
                                                $available = max(0, $orderedQty - $alreadyReturned);
                                                $unitPrice = (string) ($it['unit_price'] ?? '0.00');
                                            ?>
                                            <tr>
                                                <td class="font-medium"><?= gelo_e((string) ($it['product_title'] ?? '')) ?></td>
                                                <td class="text-right"><?= gelo_e(gelo_format_money($unitPrice)) ?></td>
                                                <td class="text-right"><?= $orderedQty ?></td>
                                                <td class="text-right"><?= $alreadyReturned ?></td>
                                                <td class="text-right font-medium"><?= $available ?></td>
                                                <td class="text-right">
                                                    <input type="hidden" name="product_id[]" value="<?= $pid ?>">
                                                    <input
                                                        class="input input-bordered input-sm w-24 text-right <?= $available === 0 ? 'opacity-50' : '' ?>"
                                                        type="number"
                                                        name="return_qty[]"
                                                        min="0"
                                                        max="<?= $available ?>"
                                                        value="0"
                                                        inputmode="numeric"
                                                        data-role="return-qty"
                                                        data-price="<?= gelo_e($unitPrice) ?>"
                                                        <?= $available === 0 ? 'readonly' : '' ?>
                                                    />
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="divider my-5"></div>

                            <label class="form-control w-full">
                                <div class="label"><span class="label-text">Justificativa</span></div>
                                <textarea class="textarea textarea-bordered w-full" name="reason" rows="3" minlength="5" placeholder="Ex.: item danificado, erro de separação…" required></textarea>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                        <div class="card-body p-6 sm:p-8">
                            <h2 class="text-lg font-semibold">Resumo do retorno</h2>
                            <div class="mt-4 space-y-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Itens devolvidos</span>
                                    <span class="font-medium" id="returnTotalItems">0</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="opacity-70">Valor devolvido</span>
                                    <span class="font-semibold text-base" id="returnTotalAmount">R$ 0,00</span>
                                </div>
                            </div>

                            <div class="divider my-4"></div>

                            <div class="text-xs opacity-70 space-y-1">
                                <div>Valor do pedido: <span class="font-medium"><?= gelo_e(gelo_format_money($orderTotal)) ?></span></div>
                                <div>Saldo atual: <span class="font-medium"><?= gelo_e(gelo_format_money($netTotal)) ?></span></div>
                                <div class="mt-2">
                                    Retorno só é permitido antes de iniciar pagamentos.
                                </div>
                            </div>

                            <button class="btn btn-primary w-full mt-6" type="submit">Registrar retorno</button>
                            <a class="btn btn-ghost w-full" href="<?= gelo_e(GELO_BASE_URL . '/withdrawal.php?id=' . (int) $id) ?>">Cancelar</a>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <script>
      const parseToCents = (decimal) => {
        const v = String(decimal || '0').trim().replace(',', '.');
        const m = v.match(/^(-)?(\d+)(?:\.(\d{1,2}))?$/);
        if (!m) return 0;
        const sign = m[1] ? -1 : 1;
        const whole = parseInt(m[2] || '0', 10);
        const frac = (m[3] || '0').padEnd(2, '0').slice(0, 2);
        return sign * (whole * 100 + parseInt(frac, 10));
      };

      const formatMoney = (cents) => {
        const abs = Math.abs(cents);
        const whole = Math.floor(abs / 100);
        const frac = String(abs % 100).padStart(2, '0');
        const wholeFormatted = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        const sign = cents < 0 ? '-' : '';
        return `${sign}R$ ${wholeFormatted},${frac}`;
      };

      const totalItemsEl = document.getElementById('returnTotalItems');
      const totalAmountEl = document.getElementById('returnTotalAmount');

      const recompute = () => {
        let totalItems = 0;
        let totalCents = 0;

        document.querySelectorAll('input[data-role="return-qty"]').forEach((input) => {
          const qty = parseInt(input.value || '0', 10);
          if (!Number.isFinite(qty) || qty <= 0) return;
          const price = input.dataset.price || '0';
          const priceCents = parseToCents(price);
          totalItems += qty;
          totalCents += qty * priceCents;
        });

        if (totalItemsEl) totalItemsEl.textContent = String(totalItems);
        if (totalAmountEl) totalAmountEl.textContent = formatMoney(totalCents);
      };

      document.querySelectorAll('input[data-role="return-qty"]').forEach((input) => {
        input.addEventListener('input', () => {
          const max = parseInt(input.getAttribute('max') || '0', 10);
          let v = parseInt(input.value || '0', 10);
          if (!Number.isFinite(v) || v < 0) v = 0;
          if (Number.isFinite(max) && v > max) v = max;
          input.value = String(v);
          recompute();
        });
      });

      recompute();
    </script>
</body>
</html>
