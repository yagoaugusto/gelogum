<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_permissions(['withdrawals.access', 'withdrawals.view_all', 'withdrawals.create_for_client']);
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Novo pedido · Retirada (Admin)';
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
$selectedUserId = isset($old['user_id']) ? (int) $old['user_id'] : 0;

$products = [];
$users = [];
try {
    $pdo = gelo_pdo();
    $products = $pdo->query('SELECT id, title, unit_price FROM products WHERE is_active = 1 ORDER BY title ASC')->fetchAll();
    $users = $pdo->query('SELECT id, name, phone FROM users WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll();
} catch (Throwable $e) {
    $error = $error ?? 'Erro ao carregar dados. Verifique o banco e as migrações.';
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
                <h1 class="text-2xl font-semibold tracking-tight">Novo pedido (para cliente)</h1>
                <p class="text-sm opacity-70 mt-1">Selecione o cliente, os produtos e as quantidades.</p>
            </div>
            <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/withdrawals.php') ?>">Voltar</a>
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

        <form class="mt-6 grid gap-6 lg:grid-cols-3" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/withdrawal_create.php') ?>" id="withdrawalForm">
            <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">
            <input type="hidden" name="_source" value="admin">

            <div class="lg:col-span-2 space-y-6">
                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body p-6 sm:p-8">
	                        <h2 class="text-lg font-semibold">Cliente</h2>
	                        <p class="text-sm opacity-70 mt-1">O pedido será registrado no nome do cliente selecionado.</p>
	                        <label class="form-control w-full mt-4">
	                            <div class="label"><span class="label-text">Usuário</span></div>
	                            <div class="relative hidden" id="userSearchWrap">
	                                <input
	                                    class="input input-bordered w-full pl-10 pr-10"
	                                    type="text"
	                                    id="userSearchInput"
	                                    placeholder="Buscar por nome ou telefone…"
	                                    autocomplete="off"
	                                />
	                                <div class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 opacity-60 z-10">⌕</div>
	                                <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 opacity-60 z-10">▾</div>
	                                <div class="absolute z-20 mt-1 w-full overflow-hidden rounded-box bg-base-100 shadow-xl ring-1 ring-base-300/60 hidden" id="userSearchDropdown">
	                                    <ul class="max-h-72 overflow-auto p-1" id="userSearchList"></ul>
	                                </div>
	                            </div>
	                            <select class="select select-bordered w-full" name="user_id" id="userSelect" required>
	                                <option value="">Selecione…</option>
	                                <?php foreach ($users as $u): ?>
	                                    <?php
                                        $uid = (int) ($u['id'] ?? 0);
                                        $label = trim((string) ($u['name'] ?? ''));
                                        $phone = (string) ($u['phone'] ?? '');
                                        $isSelected = $selectedUserId > 0 && $selectedUserId === $uid;
                                    ?>
                                    <option value="<?= $uid ?>" data-name="<?= gelo_e($label) ?>" data-phone="<?= gelo_e(gelo_format_phone($phone)) ?>" <?= $isSelected ? 'selected' : '' ?>>
                                        <?= gelo_e($label) ?> · <?= gelo_e(gelo_format_phone($phone)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body p-6 sm:p-8">
		                        <div class="grid grid-cols-12 gap-3">
		                            <label class="form-control col-span-12 md:col-span-7">
		                                <div class="label"><span class="label-text">Produto</span></div>
		                                <div class="relative hidden" id="productSearchWrap">
		                                    <input
		                                        class="input input-bordered w-full pl-10 pr-10"
		                                        type="text"
		                                        id="productSearchInput"
		                                        placeholder="Buscar produto…"
		                                        autocomplete="off"
		                                    />
		                                    <div class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 opacity-60 z-10">⌕</div>
		                                    <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 opacity-60 z-10">▾</div>
		                                    <div class="absolute z-20 mt-1 w-full overflow-hidden rounded-box bg-base-100 shadow-xl ring-1 ring-base-300/60 hidden" id="productSearchDropdown">
		                                        <ul class="max-h-72 overflow-auto p-1" id="productSearchList"></ul>
		                                    </div>
		                                </div>
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

		                            <label class="form-control col-span-6 md:col-span-2">
		                                <div class="label"><span class="label-text">Qtd</span></div>
		                                <input class="input input-bordered w-full" id="qtyInput" type="number" min="1" value="1" inputmode="numeric" />
		                            </label>

		                            <label class="form-control col-span-6 md:col-span-3">
		                                <div class="label"><span class="label-text">&nbsp;</span></div>
		                                <button class="btn btn-primary w-full" type="button" id="addItemButton">Adicionar</button>
		                            </label>
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
                            <div class="flex items-start justify-between gap-4">
                                <span class="opacity-70">Cliente</span>
                                <span class="text-right">
                                    <div class="font-medium" id="summaryUserName">—</div>
                                    <div class="text-xs opacity-70" id="summaryUserPhone"></div>
                                </span>
                            </div>

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
                    Ao criar o pedido, ele ficará como <span class="font-medium">Solicitado</span> → <span class="font-medium">Separado</span> → <span class="font-medium">Entregue</span>.
                </div>
            </div>
        </form>
    </main>

    <script>
      window.__GELO_INITIAL_ITEMS = <?= $initialItemsJson ?: '[]' ?>;

	      const items = new Map();
	      const userSelectEl = document.getElementById('userSelect');
	      const userSearchWrap = document.getElementById('userSearchWrap');
	      const userSearchInput = document.getElementById('userSearchInput');
	      const userSearchDropdown = document.getElementById('userSearchDropdown');
	      const userSearchList = document.getElementById('userSearchList');
	      const summaryUserName = document.getElementById('summaryUserName');
	      const summaryUserPhone = document.getElementById('summaryUserPhone');
	      const selectEl = document.getElementById('productSelect');
	      const productSearchWrap = document.getElementById('productSearchWrap');
	      const productSearchInput = document.getElementById('productSearchInput');
	      const productSearchDropdown = document.getElementById('productSearchDropdown');
	      const productSearchList = document.getElementById('productSearchList');
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

	      const digitsOnly = (value) => String(value || '').replace(/\D+/g, '');

	      const setupSearchSelect = (config) => {
	        const {
	          select,
	          wrap,
	          input,
	          dropdown,
	          list,
	          emptyText,
	          getOptionMeta,
	          getSelectedLabel,
	          onSelected,
	        } = config;

	        if (!select || !wrap || !input || !dropdown || !list) {
	          return { sync: () => {}, close: () => {}, open: () => {} };
	        }

	        const options = Array.from(select.options)
	          .filter((opt) => String(opt.value || '').trim() !== '')
	          .map((opt) => {
	            const meta = getOptionMeta(opt);
	            return {
	              value: String(opt.value),
	              label: String(opt.textContent || '').trim(),
	              search: String(meta.search || '').toLowerCase(),
	              meta,
	            };
	          });

	        const close = () => dropdown.classList.add('hidden');
	        const open = () => dropdown.classList.remove('hidden');

	        let blurTimer = null;

	        const sync = () => {
	          const selected = select.options[select.selectedIndex];
	          if (!selected || !selected.value) {
	            input.value = '';
	            return;
	          }
	          if (typeof getSelectedLabel === 'function') {
	            input.value = String(getSelectedLabel(selected) || '').trim();
	            return;
	          }
	          input.value = String(selected.textContent || '').trim();
	        };

	        const render = (filter) => {
	          const q = String(filter || '').trim().toLowerCase();
	          let filtered = options;
	          if (q) {
	            filtered = options.filter((o) => o.search.includes(q));
	          }
	          filtered = filtered.slice(0, 80);

	          if (filtered.length === 0) {
	            list.innerHTML = `<li><span class="text-sm opacity-70 pointer-events-none">${escapeHtml(emptyText || 'Nenhum resultado.')}</span></li>`;
	            return;
	          }

	          list.innerHTML = filtered
	            .map((o) => {
	              const line1 = escapeHtml(o.meta.primary || o.meta.line1 || o.label);
	              const line2 = escapeHtml(o.meta.secondary || o.meta.line2 || '');
	              const right = escapeHtml(o.meta.right || '');
	              const isSelected = String(select.value || '') === String(o.value || '');

	              const line2Html = line2 ? `<div class="text-xs opacity-70 truncate">${line2}</div>` : '';
	              const rightHtml = right ? `<span class="badge badge-ghost">${right}</span>` : '';
	              const base = isSelected ? 'bg-base-200/60' : '';
	              return `
	                <li>
	                  <button
	                    type="button"
	                    class="w-full rounded-btn px-3 py-2 text-left hover:bg-base-200 focus:bg-base-200 focus:outline-none flex items-center justify-between gap-3 ${base}"
	                    data-value="${escapeHtml(o.value)}"
	                  >
	                    <div class="min-w-0">
	                      <div class="text-sm font-medium truncate">${line1}</div>
	                      ${line2Html}
	                    </div>
	                    <div class="shrink-0">${rightHtml}</div>
	                  </button>
	                </li>
	              `;
	            })
	            .join('');
	        };

	        const selectFirstVisible = () => {
	          const btn = list.querySelector('button[data-value]');
	          if (!btn) return false;
	          const value = btn.getAttribute('data-value') || '';
	          if (!value) return false;
	          select.value = value;
	          select.dispatchEvent(new Event('change', { bubbles: true }));
	          sync();
	          close();
	          if (typeof onSelected === 'function') onSelected(value);
	          return true;
	        };

	        input.addEventListener('focus', () => {
	          clearTimeout(blurTimer);
	          open();
	          render('');
	          requestAnimationFrame(() => {
	            try {
	              input.select();
	            } catch {}
	          });
	        });

	        input.addEventListener('input', () => {
	          open();
	          render(input.value);
	        });

	        input.addEventListener('keydown', (e) => {
	          if (e.key === 'Escape') {
	            e.preventDefault();
	            close();
	            sync();
	            return;
	          }
	          if (e.key === 'Enter') {
	            e.preventDefault();
	            if (!dropdown.classList.contains('hidden')) {
	              if (selectFirstVisible()) return;
	            }
	          }
	        });

	        input.addEventListener('blur', () => {
	          blurTimer = setTimeout(() => {
	            close();
	            sync();
	          }, 120);
	        });

	        list.addEventListener('click', (e) => {
	          const target = e.target instanceof Element ? e.target.closest('button[data-value]') : null;
	          if (!target) return;
	          e.preventDefault();
	          clearTimeout(blurTimer);

	          const value = target.getAttribute('data-value') || '';
	          select.value = value;
	          select.dispatchEvent(new Event('change', { bubbles: true }));
	          sync();
	          close();
	          if (typeof onSelected === 'function') onSelected(value);
	        });

	        document.addEventListener('click', (e) => {
	          const t = e.target;
	          if (!(t instanceof Node)) return;
	          if (wrap.contains(t)) return;
	          close();
	          sync();
	        });

	        wrap.classList.remove('hidden');
	        select.classList.add('hidden');
	        sync();

	        return { sync, close, open, render };
	      };

	      const updateUserSummary = () => {
	        const option = userSelectEl.options[userSelectEl.selectedIndex];
	        const name = option?.dataset?.name || '';
	        const phone = option?.dataset?.phone || '';
        summaryUserName.textContent = name ? name : '—';
        summaryUserPhone.textContent = phone || '';
      };

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
	        productSearch.sync();
	        qtyEl.value = '1';
	        render();
	        productSearchInput.focus();
	      };

	      const userSearch = setupSearchSelect({
	        select: userSelectEl,
	        wrap: userSearchWrap,
	        input: userSearchInput,
	        dropdown: userSearchDropdown,
	        list: userSearchList,
	        emptyText: 'Nenhum cliente encontrado.',
	        getOptionMeta: (opt) => {
	          const name = String(opt.dataset?.name || '').trim();
	          const phone = String(opt.dataset?.phone || '').trim();
	          const phoneDigits = digitsOnly(phone);
	          const label = String(opt.textContent || '').trim();
	          return {
	            primary: name || label,
	            right: phone || '',
	            search: `${label} ${name} ${phone} ${phoneDigits}`,
	          };
	        },
	        getSelectedLabel: (opt) => String(opt.dataset?.name || opt.textContent || '').trim(),
	      });

	      const productSearch = setupSearchSelect({
	        select: selectEl,
	        wrap: productSearchWrap,
	        input: productSearchInput,
	        dropdown: productSearchDropdown,
	        list: productSearchList,
	        emptyText: 'Nenhum produto encontrado.',
	        getOptionMeta: (opt) => {
	          const title = String(opt.dataset?.title || '').trim();
	          const price = String(opt.dataset?.price || '').trim();
	          const label = String(opt.textContent || '').trim();
	          const cents = parseToCents(price);
	          const priceLabel = price ? formatMoney(cents) : '';
	          return {
	            primary: title || label,
	            right: priceLabel,
	            search: `${label} ${title} ${price} ${priceLabel}`,
	          };
	        },
	        getSelectedLabel: (opt) => String(opt.dataset?.title || opt.textContent || '').trim(),
	        onSelected: () => {
	          qtyEl.focus();
	          requestAnimationFrame(() => {
	            try {
	              qtyEl.select();
	            } catch {}
	          });
	        },
	      });

	      userSelectEl.addEventListener('change', updateUserSummary);
	      updateUserSummary();

      addButton.addEventListener('click', addItem);
      qtyEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          addItem();
        }
      });

      form.addEventListener('submit', (e) => {
        if (!userSelectEl.value) {
          e.preventDefault();
          clientErrorText.textContent = 'Selecione um cliente.';
          clientError.classList.remove('hidden');
          clientError.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
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
