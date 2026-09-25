<?php

namespace app\services;

use yii\db\Connection;

class CutoverCheckService
{
    public const PASS = 'PASS';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    /** @var Connection */
    private $db;

    /** @var array */
    private $environment;

    /** @var CryptoService */
    private $crypto;

    public function __construct(Connection $db, array $environment, CryptoService $crypto)
    {
        $this->db = $db;
        $this->environment = $environment;
        $this->crypto = $crypto;
    }

    public function check(): array
    {
        $results = $this->checkConfiguration();

        try {
            $this->db->createCommand('SELECT 1')->queryScalar();
            $results[] = $this->result(self::PASS, 'database.connection', 'PostgreSQL connection is available');
        } catch (\Throwable $exception) {
            $results[] = $this->result(self::FAIL, 'database.connection', 'PostgreSQL connection failed');
            return $results;
        }

        $schemaResults = $this->checkSchema();
        $results = array_merge($results, $schemaResults);
        if (self::hasFailures($schemaResults)) {
            return $results;
        }

        return array_merge($results, $this->checkSeedData(), $this->checkEncryptedSecrets());
    }

    public static function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['status'] ?? '') === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    private function checkConfiguration(): array
    {
        $results = [];
        $isProduction = trim((string)($this->environment['SSO_ENV'] ?? '')) === 'prod';
        $results[] = $this->result(
            $isProduction ? self::PASS : self::WARN,
            'config.environment',
            $isProduction ? 'SSO_ENV is prod' : 'SSO_ENV is not prod; this is only a rehearsal'
        );

        foreach (['SSO_DATABASE_URL', 'SSO_ISSUER', 'SSO_JWT_SECRET', 'SSO_ENCRYPTION_KEY'] as $name) {
            $value = trim((string)($this->environment[$name] ?? ''));
            $results[] = $this->result(
                $value === '' ? self::FAIL : self::PASS,
                'config.' . strtolower(substr($name, 4)),
                $value === '' ? $name . ' is required' : $name . ' is set'
            );
        }

        foreach (['SSO_JWT_SECRET', 'SSO_ENCRYPTION_KEY'] as $name) {
            $value = (string)($this->environment[$name] ?? '');
            $invalid = strlen($value) < 32 || stripos($value, 'change-me') !== false;
            $results[] = $this->result(
                $invalid ? self::FAIL : self::PASS,
                'config.' . strtolower(substr($name, 4)) . '_strength',
                $invalid ? $name . ' must be at least 32 characters and not a placeholder' : $name . ' is not a placeholder'
            );
        }

        $issuer = rtrim(trim((string)($this->environment['SSO_ISSUER'] ?? '')), '/');
        $validIssuer = filter_var($issuer, FILTER_VALIDATE_URL) !== false
            && in_array((string)parse_url($issuer, PHP_URL_SCHEME), ['http', 'https'], true);
        $results[] = $this->result(
            $validIssuer ? self::PASS : self::FAIL,
            'config.issuer_url',
            $validIssuer ? 'SSO_ISSUER is a valid HTTP(S) URL' : 'SSO_ISSUER must be a valid HTTP(S) URL'
        );
        $httpsIssuer = parse_url($issuer, PHP_URL_SCHEME) === 'https';
        $results[] = $this->result(
            $isProduction && !$httpsIssuer ? self::FAIL : self::PASS,
            'config.issuer_https',
            $isProduction && !$httpsIssuer ? 'production SSO_ISSUER must use HTTPS' : 'issuer scheme is suitable for the environment'
        );

        $expectedIssuer = rtrim(trim((string)($this->environment['SSO_CUTOVER_EXPECTED_ISSUER'] ?? '')), '/');
        if ($expectedIssuer === '') {
            $results[] = $this->result(self::WARN, 'config.issuer_match', 'SSO_CUTOVER_EXPECTED_ISSUER is not set; Go issuer was not compared');
        } else {
            $results[] = $this->result(
                hash_equals($expectedIssuer, $issuer) ? self::PASS : self::FAIL,
                'config.issuer_match',
                hash_equals($expectedIssuer, $issuer) ? 'PHP issuer matches the expected Go issuer' : 'PHP issuer differs from the expected Go issuer'
            );
        }

        foreach (['SSO_ACCESS_TOKEN_TTL', 'SSO_SESSION_TTL', 'SSO_REFRESH_TOKEN_TTL'] as $name) {
            $value = trim((string)($this->environment[$name] ?? ''));
            $valid = ctype_digit($value) && (int)$value > 0;
            $results[] = $this->result(
                $valid ? self::PASS : self::FAIL,
                'config.' . strtolower(substr($name, 4)),
                $valid ? $name . ' is positive' : $name . ' must be a positive integer in seconds'
            );
        }

        $cacheDriver = trim((string)($this->environment['SSO_CACHE_DRIVER'] ?? 'file'));
        $results[] = $this->result(
            $cacheDriver === 'db' ? self::PASS : self::WARN,
            'config.shared_cache',
            $cacheDriver === 'db' ? 'shared DB cache is enabled' : 'file cache is suitable only for a single PHP instance'
        );

        $trustedProxies = trim((string)($this->environment['SSO_TRUSTED_PROXIES'] ?? ''));
        $results[] = $this->result(
            $trustedProxies !== '' ? self::PASS : self::WARN,
            'config.trusted_proxies',
            $trustedProxies !== '' ? 'trusted proxy list is configured' : 'trusted proxy list is empty'
        );

        $cookieSecure = filter_var(
            $this->environment['SSO_COOKIE_SECURE'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $results[] = $this->result(
            $httpsIssuer && !$cookieSecure ? self::FAIL : self::PASS,
            'config.secure_cookie',
            $httpsIssuer && !$cookieSecure ? 'SSO_COOKIE_SECURE must be true for an HTTPS issuer' : 'cookie security matches the issuer scheme'
        );

        return $results;
    }

    private function checkSchema(): array
    {
        $tables = [
            'ldap_providers' => ['id', 'bind_password', 'enabled', 'created_at'],
            'users' => ['id', 'password_hash', 'mfa_secret', 'tokens_invalid_before', 'ldap_provider_id'],
            'sessions' => ['id', 'user_id', 'token_hash', 'expires_at', 'revoked_at'],
            'audit_logs' => ['id', 'actor_user_id', 'action', 'target_type', 'target_id'],
            'oauth_clients' => ['id', 'client_id', 'client_secret_hash', 'redirect_uris', 'backchannel_logout_uri'],
            'oauth_authorization_codes' => ['id', 'code_hash', 'code_challenge', 'nonce', 'used_at'],
            'oidc_signing_keys' => ['id', 'kid', 'private_key_pem', 'status', 'expires_at'],
            'refresh_tokens' => ['id', 'token_hash', 'family_id', 'rotated_at', 'revoked_at'],
            'login_attempts' => ['username', 'ip', 'attempts', 'locked_until'],
            'roles' => ['id', 'code'],
            'permissions' => ['id', 'code', 'resource', 'action'],
            'role_permissions' => ['role_id', 'permission_id'],
            'user_roles' => ['user_id', 'role_id'],
            'user_consents' => ['user_id', 'client_id', 'scopes'],
            'one_time_tokens' => ['id', 'user_id', 'purpose', 'token_hash'],
            'mfa_recovery_codes' => ['id', 'user_id', 'code_hash', 'used_at'],
            'groups' => ['id', 'code'],
            'group_roles' => ['group_id', 'role_id'],
            'user_groups' => ['user_id', 'group_id', 'source'],
            'ldap_group_mappings' => ['id', 'provider_id', 'ldap_group', 'group_id'],
            'identity_providers' => ['id', 'code', 'client_secret', 'authorize_url', 'token_url'],
            'federated_identities' => ['provider_id', 'external_subject', 'user_id'],
            'cache' => ['id', 'expire', 'data'],
        ];

        $missing = [];
        foreach ($tables as $table => $columns) {
            $schema = $this->db->schema->getTableSchema($table, true);
            if ($schema === null) {
                $missing[] = $table;
                continue;
            }
            foreach ($columns as $column) {
                if (!isset($schema->columns[$column])) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        $results = [$this->result(
            empty($missing) ? self::PASS : self::FAIL,
            'database.schema',
            empty($missing) ? 'required SSO tables and columns are present' : 'missing schema objects: ' . implode(', ', $missing)
        )];

        $pgcrypto = (bool)$this->db->createCommand(
            "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'pgcrypto')"
        )->queryScalar();
        $results[] = $this->result(
            $pgcrypto ? self::PASS : self::FAIL,
            'database.pgcrypto',
            $pgcrypto ? 'pgcrypto extension is installed' : 'pgcrypto extension is missing'
        );

        return $results;
    }

    private function checkSeedData(): array
    {
        $roles = (int)$this->db->createCommand(
            "SELECT COUNT(*) FROM roles WHERE code IN ('admin', 'user')"
        )->queryScalar();
        $permissions = (int)$this->db->createCommand('SELECT COUNT(*) FROM permissions')->queryScalar();

        return [
            $this->result(
                $roles === 2 ? self::PASS : self::FAIL,
                'database.seed_roles',
                $roles === 2 ? 'admin and user roles are present' : 'admin/user seed roles are incomplete'
            ),
            $this->result(
                $permissions > 0 ? self::PASS : self::FAIL,
                'database.seed_permissions',
                $permissions > 0 ? 'permission seed data is present' : 'permission seed data is missing'
            ),
        ];
    }

    private function checkEncryptedSecrets(): array
    {
        $sources = [
            ['ldap_providers', 'id', 'bind_password'],
            ['identity_providers', 'id', 'client_secret'],
            ['users', 'id', 'mfa_secret'],
        ];
        $checked = 0;
        $legacyPhp = 0;
        $plaintext = 0;

        foreach ($sources as [$table, $idColumn, $secretColumn]) {
            $rows = $this->db->createCommand(
                "SELECT {$idColumn} AS id, {$secretColumn} AS secret FROM {$table} WHERE {$secretColumn} <> ''"
            )->queryAll();
            foreach ($rows as $row) {
                $checked++;
                $secret = (string)$row['secret'];
                try {
                    $this->crypto->decrypt($secret);
                } catch (\Throwable $exception) {
                    return [$this->result(
                        self::FAIL,
                        'secrets.decrypt',
                        'cannot decrypt ' . $table . ' secret for id ' . (string)$row['id']
                    )];
                }
                if (strpos($secret, 'v1:') === 0) {
                    $legacyPhp++;
                } elseif (strpos($secret, 'enc:v1:') !== 0) {
                    $plaintext++;
                }
            }
        }

        $results = [$this->result(
            self::PASS,
            'secrets.decrypt',
            $checked === 0 ? 'no encrypted secrets are stored' : 'all ' . $checked . ' stored secrets are decryptable'
        )];
        if ($legacyPhp > 0) {
            $results[] = $this->result(
                self::FAIL,
                'secrets.legacy_php_format',
                $legacyPhp . ' secrets use the PHP-only v1 format and block safe Go rollback'
            );
        }
        if ($plaintext > 0) {
            $results[] = $this->result(
                self::WARN,
                'secrets.plaintext',
                $plaintext . ' legacy secrets are stored as plaintext'
            );
        }

        return $results;
    }

    private function result(string $status, string $check, string $message): array
    {
        return ['status' => $status, 'check' => $check, 'message' => $message];
    }
}
