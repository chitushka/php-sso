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
        'SSO_ENCRYPTION_KEY' => 'test-encryption-key-at-least-32-chars',
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
    // Проверяем upgrade с нарушениями, допустимыми старой схемой.
    $run(['migrate/to', 'm261014_000011_add_audit_principals', '--interactive=0']);
    $db = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $db->exec("INSERT INTO users(username,email,status,source) VALUES
        ('drift-local','drift-local@example.test','active','local'),
        ('drift-federated','drift-federated@example.test','active','federated')");
    $db->exec("INSERT INTO oauth_clients(client_id,name,type,client_secret_hash,enabled) VALUES
        ('drift-public','Drift public','public','unexpected-secret',true),
        ('drift-confidential','Drift confidential','confidential',NULL,true)");
    $db->exec("INSERT INTO oidc_signing_keys(kid,alg,private_key_pem,public_key_pem,status) VALUES
        ('drift-key-1','RS256','enc:v1:invalid','invalid','active'),
        ('drift-key-2','RS256','enc:v1:invalid','invalid','active')");
    $db->exec("INSERT INTO mfa_recovery_codes(user_id,code_hash)
        SELECT id, encode(digest('legacy-recovery-code', 'sha256'), 'hex')
        FROM users WHERE username = 'drift-local'");
    $run(['migrate', '--interactive=0']);
    $activeKeys = (int)$db->query("SELECT COUNT(*) FROM oidc_signing_keys WHERE status = 'active'")->fetchColumn();
    $disabledUsers = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE username IN ('drift-local','drift-federated') AND source = 'local' AND status = 'blocked' AND password_hash IS NOT NULL"
    )->fetchColumn();
    $normalizedClients = (int)$db->query(
        "SELECT COUNT(*) FROM oauth_clients WHERE
         (client_id = 'drift-public' AND client_secret_hash IS NULL)
         OR (client_id = 'drift-confidential' AND client_secret_hash IS NOT NULL AND enabled = false)"
    )->fetchColumn();
    $legacyRecoveryCodes = (int)$db->query(
        "SELECT COUNT(*) FROM mfa_recovery_codes WHERE code_hash NOT LIKE '\$argon2id\$%'"
    )->fetchColumn();
    if ($activeKeys !== 1 || $disabledUsers !== 2 || $normalizedClients !== 2 || $legacyRecoveryCodes !== 0) {
        throw new RuntimeException('up migration did not repair security-model drift');
    }

    // Rollback удаляет ограничения, после чего повторный upgrade снова чинит drift.
    $run(['migrate/down', '2', '--interactive=0']);
    $db->exec("UPDATE oidc_signing_keys SET status = 'active', expires_at = NULL");
    $db->exec("INSERT INTO mfa_recovery_codes(user_id,code_hash)
        SELECT id, encode(digest('second-legacy-recovery-code', 'sha256'), 'hex')
        FROM users WHERE username = 'drift-local'");
    if ((int)$db->query("SELECT COUNT(*) FROM oidc_signing_keys WHERE status = 'active'")->fetchColumn() < 2) {
        throw new RuntimeException('down migration did not remove the active-key uniqueness invariant');
    }
    $run(['migrate', '--interactive=0']);
    if ((int)$db->query("SELECT COUNT(*) FROM oidc_signing_keys WHERE status = 'active'")->fetchColumn() !== 1) {
        throw new RuntimeException('re-applied migration did not repair active signing keys');
    }
    if ((int)$db->query('SELECT COUNT(*) FROM mfa_recovery_codes')->fetchColumn() !== 0) {
        throw new RuntimeException('re-applied migration did not invalidate legacy MFA recovery codes');
    }
    $db = null;
    echo "Drift repair, upgrade and reversible migration checks passed\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS "' . $database . '" WITH (FORCE)');
}
