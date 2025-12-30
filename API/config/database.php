<?php
declare(strict_types=1);

function gelo_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $value = trim((string) $value);
    return $value === '' ? $default : $value;
}

function gelo_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = gelo_env('GELO_DB_HOST', '127.0.0.1');
    $port = gelo_env('GELO_DB_PORT', '3306');
    $dbName = gelo_env('GELO_DB_NAME', 'gelo');
    $username = gelo_env('GELO_DB_USER', 'root');
    $password = gelo_env('GELO_DB_PASS', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

