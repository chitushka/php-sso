<?php

namespace app\services;

use yii\db\Connection;

class SecretRewrapService
{
    private const SOURCES = [
        ['ldap_providers', 'id', 'bind_password'],
        ['identity_providers', 'id', 'client_secret'],
        ['users', 'id', 'mfa_secret'],
    ];

    /** @var Connection */
    private $db;

    /** @var CryptoService */
    private $crypto;

    public function __construct(Connection $db, CryptoService $crypto)
    {
        $this->db = $db;
        $this->crypto = $crypto;
    }

    public function rewrap(bool $apply): array
    {
        $transaction = $this->db->beginTransaction();
        $counts = [];
        $total = 0;

        try {
            foreach (self::SOURCES as [$table, $idColumn, $secretColumn]) {
                $source = $table . '.' . $secretColumn;
                $rows = $this->db->createCommand(
                    "SELECT {$idColumn} AS id, {$secretColumn} AS secret
                     FROM {$table}
                     WHERE {$secretColumn} LIKE 'v1:%'
                     ORDER BY {$idColumn}
                     FOR UPDATE"
                )->queryAll();
                $counts[$source] = count($rows);
                $total += count($rows);

                foreach ($rows as $row) {
                    $legacy = (string)$row['secret'];
                    try {
                        $plain = $this->crypto->decrypt($legacy);
                    } catch (\Throwable $exception) {
                        throw new \RuntimeException(
                            'cannot decrypt ' . $source . ' for id ' . (string)$row['id'],
                            0,
                            $exception
                        );
                    }

                    if (!$apply) {
                        continue;
                    }

                    $rewrapped = $this->crypto->encrypt($plain);
                    if (strpos($rewrapped, 'enc:v1:') !== 0 || !hash_equals($plain, $this->crypto->decrypt($rewrapped))) {
                        throw new \RuntimeException('rewrap verification failed for ' . $source . ' id ' . (string)$row['id']);
                    }

                    $updated = $this->db->createCommand()->update(
                        $table,
                        [$secretColumn => $rewrapped],
                        [$idColumn => $row['id'], $secretColumn => $legacy]
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

        return ['applied' => $apply, 'total' => $total, 'sources' => $counts];
    }
}
