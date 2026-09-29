<?php

$url = getenv('SSO_DATABASE_URL');

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
    if (getenv('SSO_ENV') === 'prod' && !in_array($sslMode, ['require', 'verify-ca', 'verify-full'], true)) {
        throw new RuntimeException('production PostgreSQL connection must require TLS');
    }
    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode}";
    foreach (['sslrootcert', 'sslcert', 'sslkey'] as $option) {
        if (!empty($query[$option])) {
            $dsn .= ';' . $option . '=' . rawurldecode((string)$query[$option]);
        }
    }

    $username = rawurldecode((string)($parts['user'] ?? 'sso'));
    $password = rawurldecode((string)($parts['pass'] ?? 'sso'));
    if (getenv('SSO_ENV') === 'prod'
        && ($password === '' || in_array(strtolower($password), ['sso', 'postgres', 'change-me'], true))) {
        throw new RuntimeException('production PostgreSQL password must not be a default credential');
    }

    return [
        'class' => yii\db\Connection::class,
        'dsn' => $dsn,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8',
        'enableSchemaCache' => getenv('SSO_ENV') === 'prod',
    ];
}

if (getenv('SSO_ENV') === 'prod') {
    throw new RuntimeException('SSO_DATABASE_URL is required in production');
}

return [
    'class' => yii\db\Connection::class,
    'dsn' => 'pgsql:host=' . (getenv('SSO_DB_HOST') ?: 'postgres') . ';port=' . (getenv('SSO_DB_PORT') ?: '5432') . ';dbname=' . (getenv('SSO_DB_NAME') ?: 'sso'),
    'username' => getenv('SSO_DB_USER') ?: 'sso',
    'password' => getenv('SSO_DB_PASSWORD') ?: 'sso',
    'charset' => 'utf8',
];
