<?php
declare(strict_types=1);

require_once __DIR__ . '/../../API/config/database.php';

// Config local opcional (UltraMsg, etc.)
if (!function_exists('gelo_config')) {
    function gelo_config(string $key, $default = null)
    {
        static $config = null;
        if (!is_array($config)) {
            $config = [];

            $ultraPath = __DIR__ . '/config/ultramsg.php';
            if (is_file($ultraPath)) {
                $loaded = require $ultraPath;
                if (is_array($loaded)) {
                    $config['ultramsg'] = $loaded;
                }
            }
        }

        $parts = explode('.', $key);
        $value = $config;
        foreach ($parts as $p) {
            if (!is_array($value) || !array_key_exists($p, $value)) {
                return $default;
            }
            $value = $value[$p];
        }

        return $value;
    }
}

function gelo_base_url(): string
{
    static $baseUrl = null;
    if (is_string($baseUrl)) {
        return $baseUrl;
    }

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    $webRoot = realpath(__DIR__ . '/..');

    if ($docRoot === '' || $webRoot === false) {
        $baseUrl = '';
        return $baseUrl;
    }

    $docRootReal = realpath($docRoot);
    if ($docRootReal === false) {
        $baseUrl = '';
        return $baseUrl;
    }

    $docRootReal = rtrim($docRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $webRoot = rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (strncmp($webRoot, $docRootReal, strlen($docRootReal)) !== 0) {
        $baseUrl = '';
        return $baseUrl;
    }

    $relative = substr($webRoot, strlen($docRootReal) - 1);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $baseUrl = rtrim($relative, '/');

    return $baseUrl;
}

define('GELO_BASE_URL', gelo_base_url());

function gelo_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

gelo_start_session();

function gelo_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function gelo_flash_set(string $key, string $value): void
{
    $_SESSION['_flash'][$key] = $value;
}

function gelo_flash_get(string $key): ?string
{
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $value = (string) $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function gelo_csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function gelo_csrf_validate(?string $token): bool
{
    if (!is_string($token)) {
        return false;
    }

    $sessionToken = $_SESSION['_csrf'] ?? '';
    return is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function gelo_is_logged_in(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function gelo_require_auth(): void
{
    if (gelo_is_logged_in()) {
        return;
    }

    gelo_flash_set('error', 'Faça login para continuar.');
    gelo_redirect(GELO_BASE_URL . '/login.php');
}

function gelo_current_user(): ?array
{
    return gelo_is_logged_in() ? $_SESSION['user'] : null;
}

function gelo_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function gelo_digits(string $value): string
{
    $digits = preg_replace('/\\D+/', '', $value);
    return is_string($digits) ? $digits : '';
}

function gelo_phone_normalize_e164(?string $input, string $defaultCountryCode = '55'): ?string
{
    if (!is_string($input)) {
        return null;
    }

    $raw = trim($input);
    if ($raw === '') {
        return null;
    }

    // Mantém chatIds quando o destino já vem resolvido (ex: 1415...@c.us).
    if (strpos($raw, '@c.us') !== false || strpos($raw, '@g.us') !== false) {
        return $raw;
    }

    $explicitIntl = false;
    if (isset($raw[0]) && $raw[0] === '+') {
        $explicitIntl = true;
    }
    if (strncmp($raw, '00', 2) === 0) {
        $explicitIntl = true;
    }

    $digits = gelo_digits($raw);
    if ($digits === '') {
        return null;
    }

    // 00CCNN... => CCNN...
    if (strncmp($raw, '00', 2) === 0 && strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
    }

    // Default Brasil: quando o usuário não informou DDI (10/11 dígitos), assume DDD + número.
    if (!$explicitIntl && (strlen($digits) === 10 || strlen($digits) === 11)) {
        return '+' . $defaultCountryCode . $digits;
    }

    // Permite Brasil já com 55 sem "+".
    if (!$explicitIntl && strncmp($digits, $defaultCountryCode, strlen($defaultCountryCode)) === 0 && (strlen($digits) === 12 || strlen($digits) === 13)) {
        return '+' . $digits;
    }

    // E.164: 8..15 dígitos (sem o '+').
    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return null;
    }

    return '+' . $digits;
}

function gelo_format_phone(string $digits): string
{
    $raw = trim($digits);
    if ($raw === '') {
        return '';
    }

    // Mantém chatIds (caso algum local exiba esses destinos).
    if (strpos($raw, '@c.us') !== false || strpos($raw, '@g.us') !== false) {
        return $raw;
    }

    $hasPlus = isset($raw[0]) && $raw[0] === '+';
    $d = gelo_digits($raw);
    if ($d === '') {
        return $raw;
    }

    // Brasil: com DDI 55 (+55DDDN...).
    if (strncmp($d, '55', 2) === 0 && (strlen($d) === 12 || strlen($d) === 13)) {
        $local = substr($d, 2);
        if (strlen($local) === 11) {
            return sprintf('(+55) (%s) %s-%s', substr($local, 0, 2), substr($local, 2, 5), substr($local, 7, 4));
        }
        if (strlen($local) === 10) {
            return sprintf('(+55) (%s) %s-%s', substr($local, 0, 2), substr($local, 2, 4), substr($local, 6, 4));
        }
        return ($hasPlus ? '+' : '') . $d;
    }

    // Brasil: sem DDI (DDDN...).
    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7, 4));
    }
    if (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6, 4));
    }

    // Internacional: preserva o '+' se existia; senão, mantém só dígitos.
    return ($hasPlus ? '+' : '') . $d;
}

function gelo_parse_money(string $input): ?string
{
    $value = trim($input);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/[^0-9,\\.]/', '', $value);
    if (!is_string($value) || $value === '') {
        return null;
    }

    $lastComma = strrpos($value, ',');
    $lastDot = strrpos($value, '.');

    $decimalSep = null;
    if ($lastComma !== false && $lastDot !== false) {
        $decimalSep = ($lastComma > $lastDot) ? ',' : '.';
    } elseif ($lastComma !== false) {
        $decimalSep = ',';
    } elseif ($lastDot !== false) {
        $decimalSep = '.';
    }

    if ($decimalSep === null) {
        $digits = preg_replace('/\\D+/', '', $value);
        if (!is_string($digits) || $digits === '') {
            return null;
        }
        return $digits . '.00';
    }

    $pos = strrpos($value, $decimalSep);
    if ($pos === false) {
        return null;
    }

    $whole = substr($value, 0, $pos);
    $fraction = substr($value, $pos + 1);

    $wholeDigits = preg_replace('/\\D+/', '', $whole);
    $fractionDigits = preg_replace('/\\D+/', '', $fraction);
    if (!is_string($wholeDigits) || $wholeDigits === '') {
        $wholeDigits = '0';
    }
    if (!is_string($fractionDigits)) {
        $fractionDigits = '';
    }

    $fractionDigits = substr($fractionDigits . '00', 0, 2);
    return $wholeDigits . '.' . $fractionDigits;
}

function gelo_format_money($value): string
{
    $amount = is_numeric($value) ? (float) $value : 0.0;
    return 'R$ ' . number_format($amount, 2, ',', '.');
}

function gelo_has_any_role(array $roles): bool
{
    $user = gelo_current_user();
    $role = is_array($user) ? (string) ($user['role'] ?? '') : '';
    return in_array($role, $roles, true);
}

function gelo_permissions_catalog(): array
{
    return [
        'Retiradas' => [
            [
                'key' => 'withdrawals.access',
                'label' => 'Acessar retiradas',
                'description' => 'Permite acessar as telas de retiradas.',
            ],
            [
                'key' => 'withdrawals.view_all',
                'label' => 'Ver todos os pedidos',
                'description' => 'Permite ver pedidos de outros clientes.',
            ],
            [
                'key' => 'withdrawals.view_financial',
                'label' => 'Ver valores financeiros',
                'description' => 'Permite ver colunas de valores (Total e Pagamentos) nas listagens de retiradas.',
            ],
            [
                'key' => 'withdrawals.create_for_client',
                'label' => 'Criar pedido (para cliente)',
                'description' => 'Permite criar pedidos em nome de qualquer cliente.',
            ],
            [
                'key' => 'withdrawals.cancel',
                'label' => 'Cancelar pedido',
                'description' => 'Permite cancelar pedidos antes de terem saída.',
            ],
            [
                'key' => 'withdrawals.deliver',
                'label' => 'Marcar saída',
                'description' => 'Permite marcar pedidos como saída.',
            ],
            [
                'key' => 'withdrawals.return',
                'label' => 'Registrar retorno',
                'description' => 'Permite registrar retorno de itens após a saída.',
            ],
            [
                'key' => 'withdrawals.pay',
                'label' => 'Registrar pagamento',
                'description' => 'Permite registrar pagamentos de pedidos com saída.',
            ],
        ],
        'Cadastros' => [
            [
                'key' => 'products.access',
                'label' => 'Produtos',
                'description' => 'Permite acessar e gerenciar produtos.',
            ],
            [
                'key' => 'products.user_prices',
                'label' => 'Preços por usuário',
                'description' => 'Permite definir preços de produtos específicos por usuário (override).',
            ],
            [
                'key' => 'deposits.access',
                'label' => 'Depósitos',
                'description' => 'Permite acessar e gerenciar depósitos.',
            ],
        ],
        'Usuários' => [
            [
                'key' => 'users.access',
                'label' => 'Usuários',
                'description' => 'Permite acessar e gerenciar usuários.',
            ],
            [
                'key' => 'users.groups',
                'label' => 'Grupos e permissões',
                'description' => 'Permite criar grupos e definir permissões.',
            ],
        ],
        'Relatórios' => [
            [
                'key' => 'analytics.access',
                'label' => 'Analítico',
                'description' => 'Permite acessar o painel analítico.',
            ],
        ],
        'Configurações' => [
            [
                'key' => 'whatsapp_alerts.manage',
                'label' => 'Alertas WhatsApp',
                'description' => 'Permite gerenciar quem recebe alertas e resumos via WhatsApp.',
            ],
        ],
    ];
}

function gelo_permissions_all_keys(): array
{
    static $keys = null;
    if (is_array($keys)) {
        return $keys;
    }

    $keys = [];
    foreach (gelo_permissions_catalog() as $section) {
        if (!is_array($section)) {
            continue;
        }
        foreach ($section as $perm) {
            if (!is_array($perm) || !isset($perm['key']) || !is_string($perm['key'])) {
                continue;
            }
            $keys[] = $perm['key'];
        }
    }

    $keys = array_values(array_unique($keys));
    sort($keys);
    return $keys;
}

function gelo_is_master(): bool
{
    return gelo_has_any_role(['master']);
}

function gelo_default_permissions_for_role(string $role): array
{
    if (in_array($role, ['master', 'admin'], true)) {
        return gelo_permissions_all_keys();
    }
    if ($role === 'user') {
        return [
            'withdrawals.access',
            'withdrawals.cancel',
        ];
    }
    return [];
}

function gelo_user_permissions_set(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    $user = gelo_current_user();
    $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    $role = is_array($user) ? (string) ($user['role'] ?? '') : '';

    if ($userId <= 0) {
        return $cache;
    }

    if (gelo_is_master()) {
        foreach (gelo_permissions_all_keys() as $key) {
            $cache[$key] = true;
        }
        return $cache;
    }

    try {
        $pdo = gelo_pdo();
        $stmt = $pdo->prepare('
            SELECT
                u.permission_group_id,
                pg.is_active AS group_is_active,
                pgp.permission_key
            FROM users u
            LEFT JOIN permission_groups pg ON pg.id = u.permission_group_id
            LEFT JOIN permission_group_permissions pgp ON pgp.group_id = pg.id
            WHERE u.id = :id
        ');
        $stmt->execute(['id' => $userId]);
        $hasGroup = false;
        $groupActive = false;
        foreach ($stmt->fetchAll() as $row) {
            if (isset($row['permission_group_id']) && $row['permission_group_id'] !== null) {
                $hasGroup = true;
            }
            if ((int) ($row['group_is_active'] ?? 0) === 1) {
                $groupActive = true;
            }
            $key = isset($row['permission_key']) ? (string) $row['permission_key'] : '';
            if ($groupActive && $key !== '') {
                $cache[$key] = true;
            }
        }

        if ($hasGroup || $role === '') {
            return $cache;
        }
    } catch (Throwable $e) {
        // fallback abaixo
    }

    foreach (gelo_default_permissions_for_role($role) as $key) {
        $cache[$key] = true;
    }

    return $cache;
}

function gelo_has_permission(string $permissionKey): bool
{
    if ($permissionKey === '') {
        return false;
    }

    if (gelo_is_master()) {
        return true;
    }

    $set = gelo_user_permissions_set();
    return isset($set[$permissionKey]);
}

function gelo_require_permission(string $permissionKey): void
{
    gelo_require_auth();

    if (gelo_has_permission($permissionKey)) {
        return;
    }

    gelo_flash_set('error', 'Você não tem permissão para acessar esta página.');
    gelo_redirect(GELO_BASE_URL . '/dashboard.php');
}

function gelo_require_permissions(array $permissionKeys): void
{
    gelo_require_auth();

    foreach ($permissionKeys as $key) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (!gelo_has_permission($key)) {
            gelo_flash_set('error', 'Você não tem permissão para acessar esta página.');
            gelo_redirect(GELO_BASE_URL . '/dashboard.php');
        }
    }
}

function gelo_require_roles(array $roles): void
{
    gelo_require_auth();

    if (gelo_has_any_role($roles)) {
        return;
    }

    gelo_flash_set('error', 'Você não tem permissão para acessar esta página.');
    gelo_redirect(GELO_BASE_URL . '/dashboard.php');
}

function gelo_withdrawal_payment_methods(): array
{
    return [
        'pix' => 'PIX',
        'cash' => 'Dinheiro',
        'debit' => 'Débito',
        'credit' => 'Crédito',
    ];
}

function gelo_withdrawal_payment_method_label(?string $method): string
{
    if (!is_string($method)) {
        return '—';
    }

    $method = trim($method);
    $methods = gelo_withdrawal_payment_methods();
    return $methods[$method] ?? '—';
}
