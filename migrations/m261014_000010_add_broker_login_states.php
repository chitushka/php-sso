<?php

use yii\db\Migration;

final class m261014_000010_add_broker_login_states extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('broker_login_states', [
            'state_hash' => $this->char(64)->notNull(),
            'browser_hash' => $this->char(64)->notNull(),
            'provider_id' => 'UUID NOT NULL',
            'expires_at' => $this->timestamp()->notNull(),
            'consumed_at' => $this->timestamp()->null(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('now()'),
            'PRIMARY KEY ([[state_hash]])',
        ]);
        $this->addForeignKey(
            'fk_broker_login_states_provider',
            'broker_login_states',
            'provider_id',
            'identity_providers',
            'id',
            'CASCADE'
        );
        $this->createIndex(
            'idx_broker_login_states_cleanup',
            'broker_login_states',
            ['expires_at', 'consumed_at']
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('broker_login_states');
    }
}
