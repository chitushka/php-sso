<?php

namespace app\services;

use app\models\User;
use Yii;

class AdminInvariantService
{
    private const LOCK_ID = 1397968722;

    public function lock(): void
    {
        Yii::$app->db->createCommand(
            'SELECT pg_advisory_xact_lock(:lock_id)',
            [':lock_id' => self::LOCK_ID]
        )->queryScalar();
    }

    public function userIsActiveAdmin(string $userId): bool
    {
        return (bool)Yii::$app->db->createCommand(
            "SELECT EXISTS(
                SELECT 1
                FROM users u
                WHERE u.id = :user_id
                  AND u.status = :active
                  AND (
                      EXISTS (
                          SELECT 1
                          FROM user_roles ur
                          JOIN roles r ON r.id = ur.role_id
                          WHERE ur.user_id = u.id AND r.code = 'admin'
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM user_groups ug
                          JOIN group_roles gr ON gr.group_id = ug.group_id
                          JOIN roles r ON r.id = gr.role_id
                          WHERE ug.user_id = u.id AND r.code = 'admin'
                      )
                  )
            )",
            [':user_id' => $userId, ':active' => User::STATUS_ACTIVE]
        )->queryScalar();
    }

    public function hasActiveAdmin(): bool
    {
        return (bool)Yii::$app->db->createCommand(
            "SELECT EXISTS(
                SELECT 1
                FROM users u
                WHERE u.status = :active
                  AND (
                      EXISTS (
                          SELECT 1
                          FROM user_roles ur
                          JOIN roles r ON r.id = ur.role_id
                          WHERE ur.user_id = u.id AND r.code = 'admin'
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM user_groups ug
                          JOIN group_roles gr ON gr.group_id = ug.group_id
                          JOIN roles r ON r.id = gr.role_id
                          WHERE ug.user_id = u.id AND r.code = 'admin'
                      )
                  )
            )",
            [':active' => User::STATUS_ACTIVE]
        )->queryScalar();
    }
}
