<?php

use yii\db\Migration;

class m260912_000002_add_cache_table extends Migration
{
    public function safeUp()
    {
        // Таблица нужна для shared Yii cache/rate limit при нескольких app-инстансах.
        $this->db->pdo->exec(<<<'SQL'
CREATE TABLE cache (
    id CHAR(128) PRIMARY KEY,
    expire INTEGER,
    data BYTEA
);
SQL);
    }

    public function safeDown()
    {
        $this->db->pdo->exec('DROP TABLE IF EXISTS cache');
    }
}
