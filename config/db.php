<?php

$url = getenv('SSO_DATABASE_URL');

if ($url) {
    $parts = parse_url($url);
    $host = $parts['host'] ?? 'postgres';
    $port = $parts['port'] ?? 5432;
    $database = isset($parts['path']) ? ltrim($parts['path'], '/') : 'sso';

    return [
        'class' => yii\db\Connection::class,
        'dsn' => "pgsql:host={$host};port={$port};dbname={$database}",
        'username' => $parts['user'] ?? 'sso',
        'password' => $parts['pass'] ?? 'sso',
        'charset' => 'utf8',
        'enableSchemaCache' => getenv('SSO_ENV') === 'prod',
    ];
}

return [
    'class' => yii\db\Connection::class,
    'dsn' => 'pgsql:host=' . (getenv('SSO_DB_HOST') ?: 'postgres') . ';port=' . (getenv('SSO_DB_PORT') ?: '5432') . ';dbname=' . (getenv('SSO_DB_NAME') ?: 'sso'),
    'username' => getenv('SSO_DB_USER') ?: 'sso',
    'password' => getenv('SSO_DB_PASSWORD') ?: 'sso',
    'charset' => 'utf8',
];
