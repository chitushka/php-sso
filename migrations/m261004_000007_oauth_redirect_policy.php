<?php

use yii\db\Migration;

class m261004_000007_oauth_redirect_policy extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            'oauth_clients',
            'allow_loopback_redirects',
            $this->boolean()->notNull()->defaultValue(false)
        );
    }

    public function safeDown()
    {
        $this->dropColumn('oauth_clients', 'allow_loopback_redirects');
    }
}
