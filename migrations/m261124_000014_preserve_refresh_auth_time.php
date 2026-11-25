<?php

use yii\db\Migration;

class m261124_000014_preserve_refresh_auth_time extends Migration
{
    public function safeUp()
    {
        $this->addColumn('refresh_tokens', 'auth_time', $this->bigInteger());
        $this->execute(
            'ALTER TABLE refresh_tokens ADD CONSTRAINT chk_refresh_tokens_auth_time '
            . 'CHECK (auth_time IS NULL OR auth_time >= 0)'
        );
        // Исходное auth_time старых OIDC grants восстановить нельзя; повторный вход безопаснее подмены времени.
        $this->execute(
            "UPDATE refresh_tokens SET revoked_at = now() WHERE revoked_at IS NULL "
            . "AND 'openid' = ANY(regexp_split_to_array(btrim(scope), '\s+'))"
        );
        return true;
    }

    public function safeDown()
    {
        $this->dropColumn('refresh_tokens', 'auth_time');
        return true;
    }
}
