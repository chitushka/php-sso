<?php

namespace app\services;

use yii\db\Connection;

final class DatabaseInvariantService
{
    private const REPAIR_LOCK = 1397968721;

    private Connection $db;
    private CryptoService $crypto;
    private int $retiringSeconds;

    public function __construct(Connection $db, CryptoService $crypto, int $retiringSeconds = 86400)
    {
        $this->db = $db;
        $this->crypto = $crypto;
        $this->retiringSeconds = max(60, $retiringSeconds);
    }

    public function diagnose(): array
    {
        $counts = [
            'users.local_without_password' => $this->count(
                "SELECT COUNT(*) FROM users WHERE source = 'local' AND NULLIF(btrim(password_hash), '') IS NULL"
            ),
            'users.local_with_ldap' => $this->count(
                "SELECT COUNT(*) FROM users WHERE source = 'local' AND (ldap_provider_id IS NOT NULL OR ldap_dn IS NOT NULL)"
            ),
            'users.ldap_invalid' => $this->count(
                "SELECT COUNT(*) FROM users WHERE source = 'ldap' AND (ldap_provider_id IS NULL OR NULLIF(btrim(ldap_dn), '') IS NULL)"
            ),
            'users.ldap_with_password' => $this->count(
                "SELECT COUNT(*) FROM users WHERE source = 'ldap' AND password_hash IS NOT NULL"
            ),
            'users.federated_with_auth_material' => $this->count(
                "SELECT COUNT(*) FROM users WHERE source = 'federated' AND (password_hash IS NOT NULL OR ldap_provider_id IS NOT NULL OR ldap_dn IS NOT NULL)"
            ),
            'users.federated_without_link' => $this->count(
                "SELECT COUNT(*) FROM users u WHERE u.source = 'federated' AND NOT EXISTS (SELECT 1 FROM federated_identities f WHERE f.user_id = u.id)"
            ),
            'oauth_clients.secret_mismatch' => $this->count(
                "SELECT COUNT(*) FROM oauth_clients WHERE (type = 'confidential' AND NULLIF(btrim(client_secret_hash), '') IS NULL) OR (type = 'public' AND client_secret_hash IS NOT NULL)"
            ),
            'signing_keys.unsupported_algorithm' => $this->count(
                "SELECT COUNT(*) FROM oidc_signing_keys WHERE alg <> 'RS256'"
            ),
            'signing_keys.lifecycle_mismatch' => $this->count(
                "SELECT COUNT(*) FROM oidc_signing_keys WHERE (status = 'active' AND expires_at IS NOT NULL) OR (status IN ('retiring', 'retired') AND expires_at IS NULL)"
            ),
        ];
        $activeKeys = $this->count("SELECT COUNT(*) FROM oidc_signing_keys WHERE status = 'active'");
        $counts['signing_keys.active_count'] = $activeKeys;

        $violations = 0;
        foreach ($counts as $name => $count) {
            if ($name === 'signing_keys.active_count') {
                $violations += $count === 1 ? 0 : abs($count - 1);
                continue;
            }
            $violations += $count;
        }

        return ['valid' => $violations === 0, 'violations' => $violations, 'counts' => $counts];
    }

    public function repair(): array
    {
        $before = $this->diagnose();
        $changes = [];
        $transaction = $this->db->beginTransaction();
        try {
            $this->db->createCommand(
                'SELECT pg_advisory_xact_lock(:lock_id)',
                [':lock_id' => self::REPAIR_LOCK]
            )->queryScalar();

            $disabledHash = PasswordHasher::hash(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
            $changes['users_disabled'] = $this->db->createCommand(
                "UPDATE users u
                 SET source = 'local', password_hash = :hash, ldap_provider_id = NULL, ldap_dn = NULL,
                     status = CASE WHEN status IN ('active', 'pending') THEN 'blocked' ELSE status END,
                     updated_at = now()
                 WHERE (source = 'local' AND NULLIF(btrim(password_hash), '') IS NULL)
                    OR (source = 'ldap' AND (ldap_provider_id IS NULL OR NULLIF(btrim(ldap_dn), '') IS NULL))
                    OR (source = 'federated' AND NOT EXISTS (
                        SELECT 1 FROM federated_identities f WHERE f.user_id = u.id
                    ))",
                [':hash' => $disabledHash]
            )->execute();
            $changes['local_ldap_fields_cleared'] = $this->db->createCommand(
                "UPDATE users SET ldap_provider_id = NULL, ldap_dn = NULL, updated_at = now()
                 WHERE source = 'local' AND (ldap_provider_id IS NOT NULL OR ldap_dn IS NOT NULL)"
            )->execute();
            $changes['external_passwords_cleared'] = $this->db->createCommand(
                "UPDATE users SET password_hash = NULL, updated_at = now()
                 WHERE source IN ('ldap', 'federated') AND password_hash IS NOT NULL"
            )->execute();
            $changes['federated_ldap_fields_cleared'] = $this->db->createCommand(
                "UPDATE users SET ldap_provider_id = NULL, ldap_dn = NULL, updated_at = now()
                 WHERE source = 'federated' AND (ldap_provider_id IS NOT NULL OR ldap_dn IS NOT NULL)"
            )->execute();

            $changes['public_client_secrets_cleared'] = $this->db->createCommand(
                "UPDATE oauth_clients SET client_secret_hash = NULL, updated_at = now()
                 WHERE type = 'public' AND client_secret_hash IS NOT NULL"
            )->execute();
            $clientHash = PasswordHasher::hash(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
            $changes['confidential_clients_disabled'] = $this->db->createCommand(
                "UPDATE oauth_clients SET client_secret_hash = :hash, enabled = false, updated_at = now()
                 WHERE type = 'confidential' AND NULLIF(btrim(client_secret_hash), '') IS NULL",
                [':hash' => $clientHash]
            )->execute();

            $changes['unsupported_signing_keys_removed'] = $this->db->createCommand(
                "DELETE FROM oidc_signing_keys WHERE alg <> 'RS256'"
            )->execute();
            $changes['signing_key_expirations_normalized'] = $this->db->createCommand(
                "UPDATE oidc_signing_keys
                 SET expires_at = CASE WHEN status = 'active' THEN NULL ELSE COALESCE(expires_at, now()) END
                 WHERE (status = 'active' AND expires_at IS NOT NULL)
                    OR (status IN ('retiring', 'retired') AND expires_at IS NULL)"
            )->execute();

            $activeIds = $this->db->createCommand(
                "SELECT id FROM oidc_signing_keys WHERE status = 'active' ORDER BY created_at DESC, id DESC"
            )->queryColumn();
            if (count($activeIds) > 1) {
                $changes['extra_active_signing_keys_retired'] = $this->db->createCommand(
                    "UPDATE oidc_signing_keys
                     SET status = 'retiring', expires_at = now() + make_interval(secs => :seconds)
                     WHERE status = 'active' AND id <> :keep_id",
                    [':seconds' => $this->retiringSeconds, ':keep_id' => $activeIds[0]]
                )->execute();
            } else {
                $changes['extra_active_signing_keys_retired'] = 0;
            }
            if ($activeIds === []) {
                $this->createActiveSigningKey();
                $changes['active_signing_keys_created'] = 1;
            } else {
                $changes['active_signing_keys_created'] = 0;
            }

            $after = $this->diagnose();
            if (!$after['valid']) {
                throw new \RuntimeException('database invariant repair did not reach a valid state');
            }
            $transaction->commit();

            return ['before' => $before, 'after' => $after, 'changes' => $changes];
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function createActiveSigningKey(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false || !openssl_pkey_export($resource, $private)) {
            throw new \RuntimeException('failed to generate replacement OIDC signing key');
        }
        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || empty($details['key'])) {
            throw new \RuntimeException('failed to read replacement OIDC signing key');
        }

        $this->db->createCommand()->insert('oidc_signing_keys', [
            'kid' => (string)$this->db->createCommand('SELECT gen_random_uuid()')->queryScalar(),
            'alg' => 'RS256',
            'private_key_pem' => $this->crypto->encrypt($private),
            'public_key_pem' => $details['key'],
            'status' => 'active',
        ])->execute();
    }

    private function count(string $sql): int
    {
        return (int)$this->db->createCommand($sql)->queryScalar();
    }
}
