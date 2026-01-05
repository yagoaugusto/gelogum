<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permission('withdrawals.access');
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Novo pedido · Retirada';
$activePage = 'withdrawals';

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');
$oldJson = gelo_flash_get('old_withdrawal');
$old = [];
if (is_string($oldJson) && $oldJson !== '') {
    $decoded = json_decode($oldJson, true);
    if (is_array($decoded)) {
        $old = $decoded;
    }
}

$comment = isset($old['comment']) ? (string) $old['comment'] : '';
$initialItems = isset($old['items']) && is_array($old['items']) ? $old['items'] : [];
$initialItemsJson = json_encode($initialItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$products = [];
try {
    $pdo = gelo_pdo();
    $sessionUser = gelo_current_user();
    $currentUserId = is_array($sessionUser) ? (int) ($sessionUser['id'] ?? 0) : 0;
    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('
            SELECT
                p.id,
                p.title,
                COALESCE(upp.unit_price, p.unit_price) AS unit_price
            FROM products p
            LEFT JOIN user_product_prices upp
                ON upp.product_id = p.id AND upp.user_id = :user_id
            WHERE p.is_active = 1
            ORDER BY p.title ASC
        ');
        $stmt->execute(['user_id' => $currentUserId]);
        $products = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query('SELECT id, title, unit_price FROM products WHERE is_active = 1 ORDER BY title ASC');
        $products = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar produtos. Verifique o banco e as migrações.';
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
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Novo pedido de retirada</h1>
                <p class="text-sm opacity-70 mt-1">Selecione os produtos e informe as quantidades.</p>
            </div>
            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php') ?>">Voltar</a>
        </div>

        <?php if (is_string($success) && $success !== ''): ?>
            <div class="alert alert-success mt-4">
                <span><?= gelo_e($success) ?></span>
            </div>
        <?php endif; ?>
        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error mt-4" id="serverError">
                <span><?= gelo_e($error) ?></span>
            </div>
        <?php endif; ?>

        <form class="mt-6 grid gap-6 lg:grid-cols-3" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/withdrawal_create.php') ?>" id="withdrawalForm">
            <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
            <input type="hidden" name="_source" value="self">

            <div class="lg:col-span-2 space-y-6">
                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body p-6 sm:p-8">
                        <div class="flex items-end gap-3 flex-wrap">
                            <label class="form-control w-full sm:flex-1 min-w-[220px]">
                                <div class="label"><span class="label-text">Produto</span></div>
                                <select class="select select-bordered w-full" id="productSelect">
                                    <option value="">Selecione…</option>
                                    <?php foreach ($products as $p): ?>
                                        <option
                                            value="<?= (int) $p['id'] ?>"
                                            data-title="<?= gelo_e((string) $p['title']) ?>"
                                            data-price="<?= gelo_e((string) $p['unit_price']) ?>"
                                        >
                                            <?= gelo_e((string) $p['title']) ?> · <?= gelo_e(gelo_format_money($p['unit_price'] ?? 0)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="form-control w-32">
                                <div class="label"><span class="label-text">Qtd</span></div>
                                <input class="input input-bordered w-full" id="qtyInput" type="number" min="1" value="1" inputmode="numeric" />
                            </label>

                            <button class="btn btn-primary" type="button" id="addItemButton">Adicionar</button>
                        </div>

                        <div class="mt-5 overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Produto</th>
                                        <th class="text-right">Preço</th>
                                        <th class="text-right">Qtd</th>
                                        <th class="text-right">Subtotal</th>
                                        <th class="text-right">Remover</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTbody">
                                    <tr id="emptyRow">
                                        <td colspan="5" class="py-8 text-center opacity-70">Nenhum item adicionado.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-error mt-4 hidden" id="clientError">
                            <span id="clientErrorText">Selecione pelo menos um produto.</span>
                        </div>
                    </div>
                </div>

                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body p-6 sm:p-8">
                        <h2 class="text-lg font-semibold">Comentário</h2>
                        <p class="text-sm opacity-70 mt-1">Opcional. Ex.: observações para separação.</p>
                        <textarea class="textarea textarea-bordered w-full mt-4" name="comment" rows="3" placeholder="Digite aqui…"><?= gelo_e($comment) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body p-6 sm:p-8">
                        <h2 class="text-lg font-semibold">Resumo</h2>
                        <div class="mt-4 space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="opacity-70">Total de itens</span>
                                <span class="font-medium" id="totalItems">0</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="opacity-70">Valor total</span>
                                <span class="font-semibold text-base" id="totalAmount">R$ 0,00</span>
                            </div>
                        </div>
                        <div class="divider my-4"></div>
                        <button class="btn btn-primary w-full" type="submit">Criar pedido</button>
                        <a class="btn btn-ghost w-full" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php') ?>">Cancelar</a>
                    </div>
                </div>

                <div class="text-xs opacity-70">
                    Ao criar o pedido, ele ficará como <span class="font-medium">Solicitado</span> → <span class="font-medium">Saída</span>.
                </div>
            </div>
        </form>
    </main>

    <script>
      window.__GELO_INITIAL_ITEMS = <?= $initialItemsJson ?: '[]' ?>;

      const items = new Map();
      const selectEl = document.getElementById('productSelect');
      const qtyEl = document.getElementById('qtyInput');
      const addButton = document.getElementById('addItemButton');
      const tbody = document.getElementById('itemsTbody');
      const emptyRow = document.getElementById('emptyRow');
      const totalItemsEl = document.getElementById('totalItems');
      const totalAmountEl = document.getElementById('totalAmount');
      const clientError = document.getElementById('clientError');
      const clientErrorText = document.getElementById('clientErrorText');
      const form = document.getElementById('withdrawalForm');

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

      const escapeHtml = (value) =>
        String(value || "")
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");

      const render = () => {
        tbody.querySelectorAll('tr[data-item]').forEach((tr) => tr.remove());
        let totalItems = 0;
        let totalCents = 0;

        items.forEach((item) => {
          totalItems += item.quantity;
          totalCents += item.priceCents * item.quantity;
        });

        totalItemsEl.textContent = String(totalItems);
        totalAmountEl.textContent = formatMoney(totalCents);

        if (items.size === 0) {
          emptyRow.classList.remove('hidden');
          return;
        }
        emptyRow.classList.add('hidden');

        items.forEach((item) => {
          const tr = document.createElement('tr');
          tr.dataset.item = String(item.id);

          const subtotalCents = item.priceCents * item.quantity;

          tr.innerHTML = `
            <td>
              <div class="font-medium">${escapeHtml(item.title)}</div>
              <input type="hidden" name="product_id[]" value="${item.id}" />
              <input type="hidden" name="quantity[]" value="${item.quantity}" />
            </td>
            <td class="text-right">${formatMoney(item.priceCents)}</td>
            <td class="text-right">
              <input class="input input-bordered input-sm w-20 text-right" type="number" min="1" value="${item.quantity}" data-role="qty" />
            </td>
            <td class="text-right font-medium">${formatMoney(subtotalCents)}</td>
            <td class="text-right">
              <button type="button" class="btn btn-ghost btn-sm" data-role="remove">Remover</button>
            </td>
          `;

          tbody.appendChild(tr);

          const qtyInput = tr.querySelector('input[data-role="qty"]');
          qtyInput.addEventListener('input', () => {
            const next = Math.max(1, parseInt(qtyInput.value || '1', 10));
            items.set(item.id, { ...item, quantity: Number.isFinite(next) ? next : 1 });
            render();
          });

          tr.querySelector('button[data-role="remove"]').addEventListener('click', () => {
            items.delete(item.id);
            render();
          });
        });
      };

      const addItem = () => {
        clientError.classList.add('hidden');

        const option = selectEl.options[selectEl.selectedIndex];
        const id = parseInt(selectEl.value || '0', 10);
        const quantity = parseInt(qtyEl.value || '1', 10);

        if (!id) return;
        if (!Number.isFinite(quantity) || quantity <= 0) return;

        const title = option?.dataset?.title || option?.textContent || `Produto #${id}`;
        const price = option?.dataset?.price || '0';
        const priceCents = parseToCents(price);

        const prev = items.get(id);
        const nextQty = (prev?.quantity || 0) + quantity;
        items.set(id, { id, title, priceCents, quantity: nextQty });

        selectEl.value = '';
        qtyEl.value = '1';
        render();
      };

      addButton.addEventListener('click', addItem);
      qtyEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          addItem();
        }
      });

      form.addEventListener('submit', (e) => {
        if (items.size > 0) return;
        e.preventDefault();
        clientErrorText.textContent = 'Selecione pelo menos um produto.';
        clientError.classList.remove('hidden');
        clientError.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });

      (window.__GELO_INITIAL_ITEMS || []).forEach((row) => {
        const id = parseInt(String(row?.product_id || '0'), 10);
        const quantity = parseInt(String(row?.quantity || '0'), 10);
        if (!id || !quantity) return;
        const option = Array.from(selectEl.options).find((o) => parseInt(o.value || '0', 10) === id);
        if (!option) return;
        const title = option?.dataset?.title || option?.textContent || `Produto #${id}`;
        const price = option?.dataset?.price || '0';
        const priceCents = parseToCents(price);
        items.set(id, { id, title, priceCents, quantity });
      });

      render();
    </script>
</body>
</html>
