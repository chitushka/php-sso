<?php

use yii\db\Migration;

class m261004_000006_normalize_user_email extends Migration
{
    public function safeUp()
    {
        $duplicates = $this->db->createCommand(
            "SELECT lower(btrim(email)) AS normalized_email
             FROM users
             GROUP BY lower(btrim(email))
             HAVING COUNT(*) > 1
             LIMIT 1"
        )->queryScalar();
        if ($duplicates !== false) {
            throw new RuntimeException(
                'cannot enforce unique user email: duplicate normalized email ' . (string)$duplicates
            );
        }

        $this->execute('UPDATE users SET email = lower(btrim(email)) WHERE email <> lower(btrim(email))');
        $this->execute('CREATE UNIQUE INDEX ux_users_email_normalized ON users (lower(email))');
    }

    public function safeDown()
    {
        $this->dropIndex('ux_users_email_normalized', 'users');
    }
}
