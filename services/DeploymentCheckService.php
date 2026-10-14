<?php

namespace app\services;

use yii\db\Connection;

class DeploymentCheckService
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

        return array_merge($results, $this->checkLdapSecurity(), $this->checkSeedData(), $this->checkEncryptedSecrets());
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
            $isProduction ? 'SSO_ENV is prod' : 'SSO_ENV is not prod; this is a non-production check'
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

        $expectedIssuer = rtrim(trim((string)($this->environment['SSO_DEPLOYMENT_EXPECTED_ISSUER'] ?? '')), '/');
        if ($expectedIssuer === '') {
            $results[] = $this->result(self::WARN, 'config.issuer_match', 'SSO_DEPLOYMENT_EXPECTED_ISSUER is not set');
        } else {
            $results[] = $this->result(
                hash_equals($expectedIssuer, $issuer) ? self::PASS : self::FAIL,
                'config.issuer_match',
                hash_equals($expectedIssuer, $issuer) ? 'issuer matches the deployment expectation' : 'issuer differs from the deployment expectation'
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
            'revoked_access_tokens' => ['jti', 'expires_at', 'revoked_at'],
            'rate_limit_buckets' => ['bucket_key', 'tokens', 'updated_at', 'expires_at', 'last_allowed'],
            'metric_counters' => ['metric_key', 'metric_value', 'updated_at'],
            'mfa_recovery_codes' => ['id', 'user_id', 'code_hash', 'used_at'],
            'groups' => ['id', 'code'],
            'group_roles' => ['group_id', 'role_id'],
            'user_groups' => ['user_id', 'group_id', 'source'],
            'ldap_group_mappings' => ['id', 'provider_id', 'ldap_group', 'group_id'],
            'identity_providers' => ['id', 'code', 'client_secret', 'authorize_url', 'token_url'],
            'federated_identities' => ['provider_id', 'external_subject', 'user_id'],
            'broker_login_states' => ['state_hash', 'browser_hash', 'provider_id', 'expires_at', 'consumed_at'],
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

        $constraints = [
            'chk_users_status',
            'chk_users_source',
            'fk_refresh_tokens_client',
            'fk_user_consents_client',
            'fk_audit_logs_actor',
            'chk_identity_providers_type',
            'chk_user_groups_source',
            'chk_one_time_tokens_purpose',
            'chk_oauth_codes_challenge_method',
            'chk_login_attempts_nonnegative',
            'chk_rate_limit_tokens_nonnegative',
            'chk_metric_counters_nonnegative',
            'ux_mfa_recovery_codes_user_code',
        ];
        $indexes = [
            'idx_sessions_cleanup',
            'idx_oauth_codes_cleanup',
            'idx_refresh_tokens_cleanup',
            'idx_one_time_tokens_cleanup',
            'idx_login_attempts_cleanup',
            'idx_audit_logs_cleanup',
            'idx_broker_login_states_cleanup',
            'idx_rate_limit_buckets_expiry',
            'idx_users_admin_list',
            'idx_oauth_clients_admin_list',
            'idx_ldap_providers_admin_list',
            'idx_ldap_group_mappings_admin_list',
            'idx_audit_logs_admin_list',
            'idx_audit_logs_actor_list',
            'idx_audit_logs_action_list',
        ];
        $results[] = $this->schemaObjectResult('constraint', 'database.constraints', $constraints);
        $results[] = $this->schemaObjectResult('index', 'database.indexes', $indexes);

        return $results;
    }

    private function schemaObjectResult(string $type, string $check, array $expected): array
    {
        $sql = $type === 'constraint'
            ? 'SELECT c.conname FROM pg_constraint c '
                . 'JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = current_schema()'
            : "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace "
                . "WHERE n.nspname = current_schema() AND c.relkind = 'i'";
        $actual = $this->db->createCommand($sql)->queryColumn();
        $missing = array_values(array_diff($expected, $actual));

        return $this->result(
            $missing === [] ? self::PASS : self::FAIL,
            $check,
            $missing === []
                ? 'required database ' . $type . 's are present'
                : 'missing database ' . $type . 's: ' . implode(', ', $missing)
        );
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

    private function checkLdapSecurity(): array
    {
        $enabled = (int)$this->db->createCommand('SELECT COUNT(*) FROM ldap_providers WHERE enabled = true')->queryScalar();
        if ($enabled === 0) {
            return [$this->result(self::PASS, 'config.ldap_security', 'no enabled LDAP providers')];
        }

        $allowlist = trim((string)($this->environment['SSO_LDAP_ALLOWED_HOSTS'] ?? ''));
        $allowInsecure = filter_var(
            $this->environment['SSO_LDAP_ALLOW_INSECURE'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $insecure = (int)$this->db->createCommand(
            'SELECT COUNT(*) FROM ldap_providers WHERE enabled = true AND use_tls = false AND start_tls = false'
        )->queryScalar();
        $valid = $allowlist !== '' && !$allowInsecure && $insecure === 0;

        return [$this->result(
            $valid ? self::PASS : self::FAIL,
            'config.ldap_security',
            $valid
                ? 'enabled LDAP providers use TLS and an explicit host allowlist'
                : 'enabled LDAP providers require TLS, SSO_LDAP_ALLOWED_HOSTS and SSO_LDAP_ALLOW_INSECURE=false'
        )];
    }

    private function checkEncryptedSecrets(): array
    {
        $sources = [
            ['ldap_providers', 'id', 'bind_password'],
            ['identity_providers', 'id', 'client_secret'],
            ['users', 'id', 'mfa_secret'],
            ['oidc_signing_keys', 'id', 'private_key_pem'],
        ];
        $checked = 0;

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
            }
        }

        return [$this->result(
            self::PASS,
            'secrets.decrypt',
            $checked === 0 ? 'no encrypted secrets are stored' : 'all ' . $checked . ' stored secrets are decryptable'
        )];
    }

    private function result(string $status, string $check, string $message): array
    {
        return ['status' => $status, 'check' => $check, 'message' => $message];
    }
}
