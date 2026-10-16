<?php

use yii\db\Migration;

class m261016_000013_harden_mfa_recovery_codes extends Migration
{
    public function safeUp()
    {
        // Старые 40-битные SHA-256 коды нельзя безопасно преобразовать без исходного значения.
        $this->delete('mfa_recovery_codes');
        $this->execute(<<<'SQL'
ALTER TABLE mfa_recovery_codes
    ADD CONSTRAINT chk_mfa_recovery_codes_argon2id
    CHECK (code_hash LIKE '$argon2id$%')
SQL);

        return true;
    }

    public function safeDown()
    {
        $this->execute(
            'ALTER TABLE mfa_recovery_codes DROP CONSTRAINT chk_mfa_recovery_codes_argon2id'
        );

        return true;
    }
}
