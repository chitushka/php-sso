<?php

namespace tests\Integration;

use app\services\CryptoService;
use app\services\SecretKeyRotationService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Transaction;

class SecretKeyRotationServiceTest extends TestCase
{
    private const OLD_KEY = '0123456789abcdef0123456789abcdef';
    private const NEW_KEY = 'fedcba9876543210fedcba9876543210';
    private const LEGACY_PHP_VECTOR = 'v1:AAECAwQFBgcICQoLOHSm0zmbF8IXmfy1mILq/TOBNy3VS8DZO5h5Eo7qgWpce4BxnxjN3g==';

    /** @var Transaction */
    private $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }

        $this->transaction = Yii::$app->db->beginTransaction();
        Yii::$app->db->createCommand()->delete('federated_identities')->execute();
        Yii::$app->db->createCommand()->update('ldap_providers', ['bind_password' => ''])->execute();
        Yii::$app->db->createCommand()->update('identity_providers', ['client_secret' => ''])->execute();
        Yii::$app->db->createCommand()->update('users', ['mfa_secret' => ''])->execute();
        Yii::$app->db->createCommand()->delete('oidc_signing_keys')->execute();
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive) {
            $this->transaction->rollBack();
        }

        parent::tearDown();
    }

    public function testDryRunClassifiesRowsWithoutWriting(): void
    {
        $ids = $this->insertFixtures();
        $before = $this->storedSecrets($ids);

        $result = $this->service()->rotate(false);

        $this->assertFalse($result['applied']);
        $this->assertSame(3, $result['rotate_total']);
        $this->assertSame(1, $result['already_rotated_total']);
        $this->assertSame($before, $this->storedSecrets($ids));
    }

    public function testApplyRotatesEveryOldFormatAndIsIdempotent(): void
    {
        $ids = $this->insertFixtures();
        $service = $this->service();
        $result = $service->rotate(true);

        $this->assertTrue($result['applied']);
        $this->assertSame(3, $result['rotate_total']);
        $this->assertSame(1, $result['already_rotated_total']);

        $secrets = $this->storedSecrets($ids);
        $newCrypto = new CryptoService(self::NEW_KEY);
        $this->assertSame('rolling-migration-secret', $newCrypto->decrypt($secrets['ldap']));
        $this->assertSame('broker-secret', $newCrypto->decrypt($secrets['identity']));
        $this->assertSame('mfa-secret', $newCrypto->decrypt($secrets['mfa']));
        $this->assertSame('already-new', $newCrypto->decrypt($secrets['already']));

        $second = $service->rotate(true);
        $this->assertSame(0, $second['rotate_total']);
        $this->assertSame(4, $second['already_rotated_total']);
        $this->assertSame($secrets, $this->storedSecrets($ids));
    }

    public function testUnknownThirdKeyRollsBackAllRows(): void
    {
        $ids = $this->insertFixtures();
        $unknown = (new CryptoService(str_repeat('z', 32)))->encrypt('unknown-secret');
        Yii::$app->db->createCommand()->update(
            'ldap_providers',
            ['bind_password' => $unknown],
            ['id' => $ids['ldap']]
        )->execute();
        $before = $this->storedSecrets($ids);

        $this->expectException(\RuntimeException::class);
        try {
            $this->service()->rotate(true);
        } finally {
            $this->assertSame($before, $this->storedSecrets($ids));
        }
    }

    private function service(): SecretKeyRotationService
    {
        return new SecretKeyRotationService(
            Yii::$app->db,
            new CryptoService(self::OLD_KEY),
            new CryptoService(self::NEW_KEY)
        );
    }

    private function insertFixtures(): array
    {
        $oldCrypto = new CryptoService(self::OLD_KEY);
        $newCrypto = new CryptoService(self::NEW_KEY);
        $ldapId = (string)Yii::$app->db->createCommand(
            "INSERT INTO ldap_providers(name,host,bind_dn,bind_password,base_dn,enabled)
             VALUES (:name,'ldap.example.test','cn=admin',:secret,'dc=example,dc=test',false)
             RETURNING id",
            [':name' => 'rotation-' . bin2hex(random_bytes(5)), ':secret' => self::LEGACY_PHP_VECTOR]
        )->queryScalar();
        $identityId = (string)Yii::$app->db->createCommand(
            "INSERT INTO identity_providers(code,name,client_id,client_secret,enabled)
             VALUES (:code,'Rotation Provider','rotation-client',:secret,false)
             RETURNING id",
            [':code' => 'rotation-' . bin2hex(random_bytes(5)), ':secret' => $oldCrypto->encrypt('broker-secret')]
        )->queryScalar();
        $alreadyId = (string)Yii::$app->db->createCommand(
            "INSERT INTO identity_providers(code,name,client_id,client_secret,enabled)
             VALUES (:code,'Already Rotated','rotation-client-new',:secret,false)
             RETURNING id",
            [':code' => 'rotated-' . bin2hex(random_bytes(5)), ':secret' => $newCrypto->encrypt('already-new')]
        )->queryScalar();
        $userId = (string)Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,status,source,mfa_secret)
             VALUES (:username,:email,'active','local','mfa-secret')
             RETURNING id",
            [
                ':username' => 'rotation-' . bin2hex(random_bytes(5)),
                ':email' => 'rotation-' . bin2hex(random_bytes(5)) . '@example.test',
            ]
        )->queryScalar();

        return ['ldap' => $ldapId, 'identity' => $identityId, 'already' => $alreadyId, 'mfa' => $userId];
    }

    private function storedSecrets(array $ids): array
    {
        return [
            'ldap' => (string)Yii::$app->db->createCommand(
                'SELECT bind_password FROM ldap_providers WHERE id = :id',
                [':id' => $ids['ldap']]
            )->queryScalar(),
            'identity' => (string)Yii::$app->db->createCommand(
                'SELECT client_secret FROM identity_providers WHERE id = :id',
                [':id' => $ids['identity']]
            )->queryScalar(),
            'already' => (string)Yii::$app->db->createCommand(
                'SELECT client_secret FROM identity_providers WHERE id = :id',
                [':id' => $ids['already']]
            )->queryScalar(),
            'mfa' => (string)Yii::$app->db->createCommand(
                'SELECT mfa_secret FROM users WHERE id = :id',
                [':id' => $ids['mfa']]
            )->queryScalar(),
        ];
    }
}
