<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (gelo_is_logged_in()) {
    gelo_redirect(GELO_BASE_URL . '/dashboard.php');
}

$pageTitle = 'Entrar · GELO';
$error = gelo_flash_get('error');
$oldPhone = gelo_flash_get('old_phone') ?? '';
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-gradient-to-br from-base-200 via-base-100 to-base-200">
    <div class="min-h-screen grid lg:grid-cols-2">
        <div class="hidden lg:flex items-center justify-center p-12 bg-gradient-to-br from-primary/10 via-base-100 to-base-200">
            <div class="max-w-md w-full">
                <img
                    src="<?= gelo_e(GELO_BASE_URL . '/logo.png') ?>"
                    alt="Logo"
                    class="w-full h-auto object-contain drop-shadow-sm"
                    decoding="async"
                />
                <div class="mt-8">
                    <h1 class="text-3xl font-semibold leading-tight">Controle, produção e entregas em um só lugar.</h1>
                    <p class="mt-3 text-sm opacity-75">Layout clean, rápido e pronto para evoluir com módulos de estoque, vendas e logística.</p>
                </div>
                <div class="mt-8 flex flex-wrap gap-2">
                    <span class="badge badge-outline">Produção</span>
                    <span class="badge badge-outline">Estoque</span>
                    <span class="badge badge-outline">Vendas</span>
                    <span class="badge badge-outline">Entregas</span>
                    <span class="badge badge-outline">Relatórios</span>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div class="mb-8 lg:hidden">
                    <img
                        src="<?= gelo_e(GELO_BASE_URL . '/logo.png') ?>"
                        alt="Logo"
                        class="w-full h-auto object-contain drop-shadow-sm"
                        decoding="async"
                    />
                </div>

                <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60">
                    <div class="card-body p-6 sm:p-8">
                        <h2 class="card-title text-2xl">Entrar</h2>
                        <p class="text-sm opacity-70 -mt-1">Use seu telefone e senha para acessar.</p>

                        <?php if (is_string($error) && $error !== ''): ?>
                            <div class="alert alert-error mt-4">
                                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>

                        <form class="mt-6 space-y-4" method="post" action="<?= htmlspecialchars(GELO_BASE_URL . '/app/actions/login.php', ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(gelo_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                            <label class="form-control w-full">
                                <div class="label">
                                    <span class="label-text">Telefone</span>
                                </div>
                                <input
                                    class="input input-bordered w-full"
                                    type="tel"
                                    name="phone"
                                    autocomplete="tel"
                                    placeholder="+5511999999999"
                                    value="<?= gelo_e(gelo_format_phone($oldPhone)) ?>"
                                    required
                                />
                                <div class="label py-0"><span class="label-text-alt opacity-70">Brasil: pode digitar (11) 99999-9999. Internacional: use +DDI (ex: +1...).</span></div>
                            </label>

                            <label class="form-control w-full">
                                <div class="label">
                                    <span class="label-text">Senha</span>
                                    <button type="button" class="label-text-alt link link-hover opacity-80" id="togglePassword">mostrar</button>
                                </div>
                                <input
                                    class="input input-bordered w-full"
                                    id="password"
                                    type="password"
                                    name="password"
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                    required
                                />
                            </label>

                            <button class="btn btn-primary w-full" type="submit">Acessar</button>
                        </form>

                        <div class="divider my-6"></div>
                        <div class="text-xs opacity-70">
                            Acesso restrito. Se você não tem credenciais, contate o administrador.
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-xs opacity-70 text-center">
                    © <?= date('Y') ?> GELO
                </div>
            </div>
        </div>
    </div>

    <script>
      const button = document.getElementById('togglePassword');
      const input = document.getElementById('password');
      if (button && input) {
        button.addEventListener('click', () => {
          const isPassword = input.getAttribute('type') === 'password';
          input.setAttribute('type', isPassword ? 'text' : 'password');
          button.textContent = isPassword ? 'ocultar' : 'mostrar';
        });
      }
    </script>
</body>
</html>
