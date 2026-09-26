<?php

namespace app\services;

use yii\db\Connection;

class SecretKeyRotationService
{
    private const SOURCES = [
        ['ldap_providers', 'id', 'bind_password'],
        ['identity_providers', 'id', 'client_secret'],
        ['users', 'id', 'mfa_secret'],
        ['oidc_signing_keys', 'id', 'private_key_pem'],
    ];

    /** @var Connection */
    private $db;

    /** @var CryptoService */
    private $oldCrypto;

    /** @var CryptoService */
    private $newCrypto;

    public function __construct(Connection $db, CryptoService $oldCrypto, CryptoService $newCrypto)
    {
        $this->db = $db;
        $this->oldCrypto = $oldCrypto;
        $this->newCrypto = $newCrypto;
    }

    public function rotate(bool $apply): array
    {
        $transaction = $this->db->beginTransaction();
        $sources = [];
        $rotateTotal = 0;
        $alreadyRotatedTotal = 0;

        try {
            foreach (self::SOURCES as [$table, $idColumn, $secretColumn]) {
                $source = $table . '.' . $secretColumn;
                $rows = $this->db->createCommand(
                    "SELECT {$idColumn} AS id, {$secretColumn} AS secret
                     FROM {$table}
                     WHERE {$secretColumn} <> ''
                     ORDER BY {$idColumn}
                     FOR UPDATE"
                )->queryAll();
                $sources[$source] = ['rotate' => 0, 'already_rotated' => 0];

                foreach ($rows as $row) {
                    $stored = (string)$row['secret'];
                    $plain = $this->decryptWithOldKey($stored);
                    if ($plain === null) {
                        if ($this->decryptWithNewKey($stored) !== null) {
                            $sources[$source]['already_rotated']++;
                            $alreadyRotatedTotal++;
                            continue;
                        }

                        throw new \RuntimeException(
                            'cannot decrypt ' . $source . ' for id ' . (string)$row['id'] . ' with old or new key'
                        );
                    }

                    $sources[$source]['rotate']++;
                    $rotateTotal++;
                    if (!$apply) {
                        continue;
                    }

                    $rotated = $this->newCrypto->encrypt($plain);
                    if (strpos($rotated, 'enc:v1:') !== 0 || !hash_equals($plain, $this->newCrypto->decrypt($rotated))) {
                        throw new \RuntimeException('rotation verification failed for ' . $source . ' id ' . (string)$row['id']);
                    }

                    $updated = $this->db->createCommand()->update(
                        $table,
                        [$secretColumn => $rotated],
                        [$idColumn => $row['id'], $secretColumn => $stored]
                    )->execute();
                    if ($updated !== 1) {
                        throw new \RuntimeException('concurrent secret update detected for ' . $source . ' id ' . (string)$row['id']);
                    }
                }
            }

            if ($apply) {
                $transaction->commit();
            } else {
                $transaction->rollBack();
            }
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        return [
            'applied' => $apply,
            'rotate_total' => $rotateTotal,
            'already_rotated_total' => $alreadyRotatedTotal,
            'sources' => $sources,
        ];
    }

    private function decryptWithOldKey(string $stored): ?string
    {
        try {
            return $this->oldCrypto->decrypt($stored);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function decryptWithNewKey(string $stored): ?string
    {
        try {
            return $this->newCrypto->decrypt($stored);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
