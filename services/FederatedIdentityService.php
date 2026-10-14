<?php

namespace app\services;

use app\components\Rfc3339;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;

final class FederatedIdentityService
{
    public function listForUser(string $userId): ServiceResult
    {
        $userExists = (bool)ApplicationRuntime::db()->createCommand(
            'SELECT 1 FROM users WHERE id = :user_id',
            [':user_id' => $userId]
        )->queryScalar();
        if (!$userExists) {
            return $this->failure('user_not_found', 'user not found', 404);
        }

        $rows = ApplicationRuntime::db()->createCommand(
            'SELECT f.provider_id, f.external_subject, f.email, f.created_at,
                    p.code AS provider_code, p.name AS provider_name
             FROM federated_identities f
             JOIN identity_providers p ON p.id = f.provider_id
             WHERE f.user_id = :user_id
             ORDER BY p.code ASC, f.external_subject ASC',
            [':user_id' => $userId]
        )->queryAll();

        return ServiceResult::success(array_map(static function (array $row): array {
            return [
                'provider_id' => (string)$row['provider_id'],
                'provider_code' => (string)$row['provider_code'],
                'provider_name' => (string)$row['provider_name'],
                'external_subject' => (string)$row['external_subject'],
                'email' => (string)$row['email'],
                'created_at' => Rfc3339::format($row['created_at']),
            ];
        }, $rows));
    }

    public function unlink(
        string $userId,
        string $providerId,
        string $externalSubject,
        ?string $actorUserId
    ): ServiceResult {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $user = ApplicationRuntime::db()->createCommand(
                'SELECT id, source, password_hash FROM users WHERE id = :user_id FOR UPDATE',
                [':user_id' => $userId]
            )->queryOne();
            if (!$user) {
                $transaction->rollBack();
                return $this->failure('user_not_found', 'user not found', 404);
            }

            $linkExists = (bool)ApplicationRuntime::db()->createCommand(
                'SELECT 1 FROM federated_identities
                 WHERE user_id = :user_id AND provider_id = :provider_id
                   AND external_subject = :external_subject',
                [
                    ':user_id' => $userId,
                    ':provider_id' => $providerId,
                    ':external_subject' => $externalSubject,
                ]
            )->queryScalar();
            if (!$linkExists) {
                $transaction->rollBack();
                return $this->failure('identity_link_not_found', 'federated identity link not found', 404);
            }

            $linkCount = (int)ApplicationRuntime::db()->createCommand(
                'SELECT COUNT(*) FROM federated_identities WHERE user_id = :user_id',
                [':user_id' => $userId]
            )->queryScalar();
            if ((string)$user['source'] === 'federated' && empty($user['password_hash']) && $linkCount <= 1) {
                $transaction->rollBack();
                return $this->failure(
                    'last_login_method',
                    'last federated login method cannot be removed',
                    409
                );
            }

            ApplicationRuntime::db()->createCommand()->delete('federated_identities', [
                'user_id' => $userId,
                'provider_id' => $providerId,
                'external_subject' => $externalSubject,
            ])->execute();
            AuditService::record($actorUserId, 'federated_identity_unlinked', 'user', $userId);
            $transaction->commit();

            return ServiceResult::success(['status' => 'unlinked']);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }

            return $this->failure('identity_unlink_failed', 'failed to unlink federated identity', 500);
        }
    }

    private function failure(string $code, string $message, int $status): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, $status));
    }
}
