<?php

use yii\db\Migration;

final class m261014_000011_add_audit_principals extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            'audit_logs',
            'actor_type',
            $this->string(16)->notNull()->defaultValue('system')
        );
        $this->addColumn('audit_logs', 'actor_id', $this->string(255)->null());
        $this->execute(<<<'SQL'
UPDATE audit_logs
SET actor_type = CASE WHEN actor_user_id IS NULL THEN 'system' ELSE 'user' END;
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_actor
CHECK (
    (actor_type = 'system' AND actor_user_id IS NULL AND actor_id IS NULL)
    OR (actor_type = 'user' AND actor_id IS NULL)
    OR (actor_type = 'client' AND actor_user_id IS NULL AND actor_id IS NOT NULL)
);
SQL);
        $this->createIndex(
            'idx_audit_logs_principal',
            'audit_logs',
            ['actor_type', 'actor_id', 'created_at']
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_audit_logs_principal', 'audit_logs');
        $this->dropColumn('audit_logs', 'actor_id');
        $this->dropColumn('audit_logs', 'actor_type');
    }
}
