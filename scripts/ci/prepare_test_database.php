<?php

if ((string)getenv('SSO_ENV') !== 'test') {
    fwrite(STDERR, "Test database preparation is allowed only with SSO_ENV=test\n");
    exit(1);
}

$host = (string)(getenv('SSO_DB_HOST') ?: 'postgres');
$port = (int)(getenv('SSO_DB_PORT') ?: 5432);
$user = (string)(getenv('SSO_DB_USER') ?: 'sso');
$password = (string)(getenv('SSO_DB_PASSWORD') ?: 'sso');
$database = (string)(getenv('SSO_DB_NAME') ?: 'sso_test');
if (preg_match('/^[a-zA-Z0-9_]+$/D', $database) !== 1 || $database === 'postgres') {
    fwrite(STDERR, "Unsafe test database name\n");
    exit(1);
}

$pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$quoted = '"' . str_replace('"', '""', $database) . '"';
$pdo->exec(
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
    . "WHERE datname = " . $pdo->quote($database) . " AND pid <> pg_backend_pid()"
);
$pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
$pdo->exec("CREATE DATABASE {$quoted}");

echo "Fresh isolated test database is ready\n";
