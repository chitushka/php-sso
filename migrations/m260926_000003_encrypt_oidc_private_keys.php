<?php

use app\services\CryptoService;
use yii\db\Migration;

class m260926_000003_encrypt_oidc_private_keys extends Migration
{
    public function safeUp()
    {
        $crypto = new CryptoService();
        $rows = $this->db->createCommand(
            "SELECT id, private_key_pem FROM oidc_signing_keys WHERE private_key_pem NOT LIKE 'enc:v1:%' FOR UPDATE"
        )->queryAll();

        foreach ($rows as $row) {
            $stored = (string)$row['private_key_pem'];
            $plain = $stored;
            if (strpos($plain, '-----BEGIN PRIVATE KEY-----') !== 0) {
                throw new RuntimeException('invalid plaintext OIDC private key');
            }

            $this->db->createCommand()->update(
                'oidc_signing_keys',
                ['private_key_pem' => $crypto->encrypt($plain)],
                ['id' => $row['id'], 'private_key_pem' => $stored]
            )->execute();
        }

        return true;
    }

    public function safeDown()
    {
        // Расшифровывать ключи при rollback небезопасно; формат столбца не менялся.
        return true;
    }
}
