<?php

$root = dirname(__DIR__, 2);
$database = 'sso_ci_' . bin2hex(random_bytes(5));
$host = getenv('SSO_DB_HOST') ?: 'postgres';
$port = getenv('SSO_DB_PORT') ?: '5432';
$user = getenv('SSO_DB_USER') ?: 'sso';
$password = getenv('SSO_DB_PASSWORD') ?: 'sso';
$admin = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$admin->exec('CREATE DATABASE "' . $database . '"');
$url = "postgres://{$user}:{$password}@{$host}:{$port}/{$database}?sslmode=disable";

$run = static function (array $arguments) use ($root, $url): void {
    $environment = array_merge(getenv(), [
        'SSO_ENV' => 'test',
        'SSO_DATABASE_URL' => $url,
        'SSO_ISSUER' => 'http://127.0.0.1:8081',
        'SSO_API_AUDIENCE' => 'http://127.0.0.1:8081/api',
    ]);
    $command = array_merge([PHP_BINARY, $root . '/yii'], $arguments);
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start migration command');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(implode(' ', $arguments) . " failed\n" . $stdout . $stderr);
    }
};

try {
    // Проверяем upgrade со снимка схемы непосредственно перед последней миграцией.
    $run(['migrate/to', 'm261004_000006_normalize_user_email', '--interactive=0']);
    $run(['migrate', '--interactive=0']);
    $db = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $column = $db->query(
        "SELECT count(*) FROM information_schema.columns
         WHERE table_schema='public' AND table_name='oauth_clients' AND column_name='allow_loopback_redirects'"
    )->fetchColumn();
    if ((int)$column !== 1) {
        throw new RuntimeException('up migration did not produce the expected schema');
    }
    $db = null;

    // Rollback проверяется только для явно обратимой последней миграции.
    $run(['migrate/down', '1', '--interactive=0']);
    $run(['migrate', '--interactive=0']);
    echo "Clean upgrade snapshot and reversible migration checks passed\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS "' . $database . '" WITH (FORCE)');
}
