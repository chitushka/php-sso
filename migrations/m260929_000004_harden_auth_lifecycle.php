<?php

use yii\db\Migration;

class m260929_000004_harden_auth_lifecycle extends Migration
{
    public function safeUp()
    {
        $this->createTable('revoked_access_tokens', [
            'jti' => $this->string()->notNull(),
            'expires_at' => $this->timestamp()->notNull(),
            'revoked_at' => $this->timestamp()->notNull()->defaultExpression('now()'),
            'PRIMARY KEY ([[jti]])',
        ]);
        $this->createIndex('idx_revoked_access_tokens_expires_at', 'revoked_access_tokens', 'expires_at');

        return true;
    }

    public function safeDown()
    {
        $this->dropTable('revoked_access_tokens');
        return true;
    }
}
