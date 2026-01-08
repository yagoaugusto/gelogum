<?php
declare(strict_types=1);

/** @var string|null $activePage */
$activePage = $activePage ?? null;

$user = gelo_current_user();
$displayName = is_array($user) ? (string) ($user['name'] ?? '') : '';
$phone = is_array($user) ? (string) ($user['phone'] ?? '') : '';
$role = is_array($user) ? (string) ($user['role'] ?? '') : '';
$canWithdrawals = gelo_has_permission('withdrawals.access');
$canPayments = $canWithdrawals && gelo_has_permission('withdrawals.pay');
$canAnalytics = gelo_has_permission('analytics.access');
$canProducts = gelo_has_permission('products.access');
$canUserProductPrices = gelo_has_permission('products.user_prices');
$canDepots = gelo_has_permission('deposits.access');
$canUsers = gelo_has_permission('users.access');
$canGroups = $canUsers && gelo_has_permission('users.groups');
$canWppAlerts = gelo_has_permission('whatsapp_alerts.manage');

$initial = 'U';
$trimmedName = trim($displayName);
if ($trimmedName !== '') {
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $initial = mb_strtoupper(mb_substr($trimmedName, 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $initial = strtoupper(substr($trimmedName, 0, 1));
    }
}

if (!function_exists('gelo_nav_link')) {
    function gelo_nav_link(string $href, string $label, bool $active): string
    {
        $classes = $active ? 'active font-medium' : '';
        return '<a class="' . $classes . '" href="' . gelo_e($href) . '">' . gelo_e($label) . '</a>';
    }
}
?>
<div class="navbar bg-base-100/80 backdrop-blur supports-[backdrop-filter]:bg-base-100/60 border-b border-base-200 sticky top-0 z-10">
    <div class="navbar-start">
        <div class="dropdown lg:hidden">
            <label tabindex="0" class="btn btn-ghost btn-circle" aria-label="Menu">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </label>
		            <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow-xl bg-base-100 rounded-box w-56 ring-1 ring-base-200">
		                <li><?= gelo_nav_link(GELO_BASE_URL . '/dashboard.php', 'Dashboard', $activePage === 'dashboard') ?></li>
                        <?php if ($canWithdrawals): ?>
			                    <li><?= gelo_nav_link(GELO_BASE_URL . '/withdrawals.php', 'Retiradas', $activePage === 'withdrawals') ?></li>
                        <?php endif; ?>
                        <?php if ($canPayments): ?>
                            <li><?= gelo_nav_link(GELO_BASE_URL . '/payments.php', 'Pagamentos', $activePage === 'payments') ?></li>
                        <?php endif; ?>
                        <?php if ($canAnalytics): ?>
		                    <li><?= gelo_nav_link(GELO_BASE_URL . '/analytics.php', 'Analítico', $activePage === 'analytics') ?></li>
                        <?php endif; ?>
                        <?php if ($canProducts): ?>
			                    <li><?= gelo_nav_link(GELO_BASE_URL . '/products.php', 'Produtos', $activePage === 'products') ?></li>
                        <?php endif; ?>
                        <?php if ($canUserProductPrices): ?>
                            <li><?= gelo_nav_link(GELO_BASE_URL . '/user_product_prices.php', 'Preços por usuário', $activePage === 'user_product_prices') ?></li>
                        <?php endif; ?>
                        <?php if ($canDepots): ?>
		                    <li><?= gelo_nav_link(GELO_BASE_URL . '/depots.php', 'Depósitos', $activePage === 'depots') ?></li>
                        <?php endif; ?>
                        <?php if ($canUsers): ?>
		                    <li><?= gelo_nav_link(GELO_BASE_URL . '/users.php', 'Usuários', $activePage === 'users') ?></li>
                        <?php endif; ?>
                        <?php if ($canGroups): ?>
		                    <li><?= gelo_nav_link(GELO_BASE_URL . '/groups.php', 'Grupos', $activePage === 'groups') ?></li>
                        <?php endif; ?>
		                <?php if ($canWppAlerts): ?>
		                    <li><?= gelo_nav_link(GELO_BASE_URL . '/wpp_alerts.php', 'Alertas WPP', $activePage === 'wpp_alerts') ?></li>
		                <?php endif; ?>
		            </ul>
        </div>
        <a class="btn btn-ghost px-2" href="<?= gelo_e(GELO_BASE_URL . '/dashboard.php') ?>" aria-label="GELO">
            <img src="<?= gelo_e(GELO_BASE_URL . '/logo.png') ?>" alt="GELO" class="h-8 w-auto" />
        </a>
    </div>

    <div class="navbar-center hidden lg:flex">
		        <ul class="menu menu-horizontal px-1">
		            <li><?= gelo_nav_link(GELO_BASE_URL . '/dashboard.php', 'Dashboard', $activePage === 'dashboard') ?></li>
                    <?php if ($canWithdrawals): ?>
			                <li><?= gelo_nav_link(GELO_BASE_URL . '/withdrawals.php', 'Retiradas', $activePage === 'withdrawals') ?></li>
                    <?php endif; ?>
                    <?php if ($canPayments): ?>
                        <li><?= gelo_nav_link(GELO_BASE_URL . '/payments.php', 'Pagamentos', $activePage === 'payments') ?></li>
                    <?php endif; ?>
                    <?php if ($canAnalytics): ?>
		                <li><?= gelo_nav_link(GELO_BASE_URL . '/analytics.php', 'Analítico', $activePage === 'analytics') ?></li>
                    <?php endif; ?>
                    <?php if ($canProducts): ?>
			                <li><?= gelo_nav_link(GELO_BASE_URL . '/products.php', 'Produtos', $activePage === 'products') ?></li>
                    <?php endif; ?>
                    <?php if ($canUserProductPrices): ?>
                        <li><?= gelo_nav_link(GELO_BASE_URL . '/user_product_prices.php', 'Preços por usuário', $activePage === 'user_product_prices') ?></li>
                    <?php endif; ?>
                    <?php if ($canDepots): ?>
		                <li><?= gelo_nav_link(GELO_BASE_URL . '/depots.php', 'Depósitos', $activePage === 'depots') ?></li>
                    <?php endif; ?>
                    <?php if ($canUsers): ?>
		                <li><?= gelo_nav_link(GELO_BASE_URL . '/users.php', 'Usuários', $activePage === 'users') ?></li>
                    <?php endif; ?>
                    <?php if ($canGroups): ?>
		                <li><?= gelo_nav_link(GELO_BASE_URL . '/groups.php', 'Grupos', $activePage === 'groups') ?></li>
                    <?php endif; ?>
		            <?php if ($canWppAlerts): ?>
		                <li><?= gelo_nav_link(GELO_BASE_URL . '/wpp_alerts.php', 'Alertas WPP', $activePage === 'wpp_alerts') ?></li>
		            <?php endif; ?>
		        </ul>
    </div>

    <div class="navbar-end gap-3">
        <div class="hidden md:flex flex-col items-end leading-tight">
            <span class="text-sm font-medium"><?= gelo_e($displayName) ?></span>
            <span class="text-xs opacity-70"><?= gelo_e(gelo_format_phone($phone)) ?></span>
        </div>

        <div class="dropdown dropdown-end">
            <label tabindex="0" class="btn btn-ghost btn-circle avatar placeholder">
                <div class="bg-primary/15 text-primary rounded-full w-10 ring-1 ring-primary/20">
                    <span class="font-semibold"><?= gelo_e($initial) ?></span>
                </div>
            </label>
            <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow-xl bg-base-100 rounded-box w-56 ring-1 ring-base-200">
                <li class="menu-title">
                    <span><?= $role !== '' ? gelo_e($role) : 'Conta' ?></span>
                </li>
                <li><a href="<?= gelo_e(GELO_BASE_URL . '/password.php') ?>">Alterar senha</a></li>
                <li><a href="<?= gelo_e(GELO_BASE_URL . '/logout.php') ?>">Sair</a></li>
            </ul>
        </div>
    </div>
</div>
