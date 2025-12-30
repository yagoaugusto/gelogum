<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
gelo_require_auth();
require_once __DIR__ . '/../API/config/database.php';

$pageTitle = 'Alterar senha · GELO';
$activePage = 'dashboard';

$success = gelo_flash_get('success');
$error = gelo_flash_get('error');
?>
<!doctype html>
<html lang="pt-BR" data-theme="corporate">
<head>
    <?php require __DIR__ . '/app/includes/head.php'; ?>
</head>
<body class="min-h-screen bg-base-200">
    <?php require __DIR__ . '/app/includes/nav.php'; ?>

    <main class="mx-auto max-w-2xl p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Alterar senha</h1>
            <p class="text-sm opacity-70 mt-1">Para sua segurança, confirme sua senha atual.</p>
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

        <div class="card bg-base-100 shadow-xl ring-1 ring-base-300/60 mt-6">
            <div class="card-body p-6 sm:p-8">
                <form class="space-y-4" method="post" action="<?= gelo_e(GELO_BASE_URL . '/app/actions/password_change.php') ?>">
                    <input type="hidden" name="_csrf" value="<?= gelo_e(gelo_csrf_token()) ?>">

                    <label class="form-control w-full">
                        <div class="label"><span class="label-text">Senha atual</span></div>
                        <input class="input input-bordered w-full" type="password" name="current_password" autocomplete="current-password" required />
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="form-control w-full">
                            <div class="label"><span class="label-text">Nova senha</span></div>
                            <input class="input input-bordered w-full" type="password" name="new_password" minlength="8" autocomplete="new-password" required />
                        </label>

                        <label class="form-control w-full">
                            <div class="label"><span class="label-text">Confirmar nova senha</span></div>
                            <input class="input input-bordered w-full" type="password" name="new_password_confirm" minlength="8" autocomplete="new-password" required />
                        </label>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <a class="btn btn-ghost" href="<?= gelo_e(GELO_BASE_URL . '/dashboard.php') ?>">Cancelar</a>
                        <button class="btn btn-primary" type="submit">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>

