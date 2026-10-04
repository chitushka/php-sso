<?php

use app\components\Environment;

$url = Environment::value('SSO_DATABASE_URL');
$environment = strtolower((string)Environment::value('SSO_ENV', 'development'));

if ($url) {
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('SSO_DATABASE_URL is invalid');
    }
    $host = $parts['host'] ?? 'postgres';
    $port = $parts['port'] ?? 5432;
    $database = isset($parts['path']) ? ltrim($parts['path'], '/') : 'sso';

    parse_str((string)($parts['query'] ?? ''), $query);
    $sslMode = (string)($query['sslmode'] ?? 'prefer');
    $allowedSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
    if (!in_array($sslMode, $allowedSslModes, true)) {
        throw new RuntimeException('SSO_DATABASE_URL contains an invalid sslmode');
    }
    if ($environment === 'prod' && $sslMode !== 'verify-full') {
        throw new RuntimeException('production PostgreSQL connection must use sslmode=verify-full');
    }
    if ($environment === 'prod' && empty($query['sslrootcert'])) {
        throw new RuntimeException('production PostgreSQL connection requires sslrootcert');
    }
    if (
        $environment === 'prod'
        && !is_readable(rawurldecode((string)$query['sslrootcert']))
    ) {
        throw new RuntimeException('production PostgreSQL sslrootcert is not readable');
    }
    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode}";
    foreach (['sslrootcert', 'sslcert', 'sslkey'] as $option) {
        if (!empty($query[$option])) {
            $dsn .= ';' . $option . '=' . rawurldecode((string)$query[$option]);
        }
    }

    $username = rawurldecode((string)($parts['user'] ?? 'sso'));
    $password = rawurldecode((string)($parts['pass'] ?? 'sso'));
    if (
        $environment === 'prod'
        && ($password === '' || in_array(strtolower($password), ['sso', 'postgres', 'change-me'], true))
    ) {
        throw new RuntimeException('production PostgreSQL password must not be a default credential');
    }

    return [
        'class' => yii\db\Connection::class,
        'dsn' => $dsn,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8',
        'enableSchemaCache' => $environment === 'prod',
    ];
}

if ($environment === 'prod') {
    throw new RuntimeException('SSO_DATABASE_URL is required in production');
}

return [
    'class' => yii\db\Connection::class,
    'dsn' => 'pgsql:host=' . (getenv('SSO_DB_HOST') ?: 'postgres') . ';port=' . (getenv('SSO_DB_PORT') ?: '5432') . ';dbname=' . (getenv('SSO_DB_NAME') ?: 'sso'),
    'username' => getenv('SSO_DB_USER') ?: 'sso',
    'password' => getenv('SSO_DB_PASSWORD') ?: 'sso',
    'charset' => 'utf8',
];
