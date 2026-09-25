<?php

namespace tests\Integration;

use app\services\CryptoService;
use app\services\SecretRewrapService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Transaction;

class SecretRewrapServiceTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
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
        Yii::$app->db->createCommand()->update('ldap_providers', ['bind_password' => ''])->execute();
        Yii::$app->db->createCommand()->update('identity_providers', ['client_secret' => ''])->execute();
        Yii::$app->db->createCommand()->update('users', ['mfa_secret' => ''])->execute();
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive) {
            $this->transaction->rollBack();
        }

        parent::tearDown();
    }

    public function testDryRunValidatesButDoesNotChangeLegacyCiphertext(): void
    {
        $id = $this->insertProvider(self::LEGACY_PHP_VECTOR);
        $result = $this->service()->rewrap(false);

        $this->assertFalse($result['applied']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['sources']['ldap_providers.bind_password']);
        $this->assertSame(self::LEGACY_PHP_VECTOR, $this->providerSecret($id));
    }

    public function testApplyRewrapsAtomicallyAndCanBeRepeated(): void
    {
        $id = $this->insertProvider(self::LEGACY_PHP_VECTOR);
        $service = $this->service();
        $result = $service->rewrap(true);

        $rewrapped = $this->providerSecret($id);
        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['total']);
        $this->assertStringStartsWith('enc:v1:', $rewrapped);
        $this->assertSame('rolling-migration-secret', (new CryptoService(self::KEY))->decrypt($rewrapped));

        $second = $service->rewrap(true);
        $this->assertSame(0, $second['total']);
        $this->assertSame($rewrapped, $this->providerSecret($id));
    }

    public function testWrongKeyRollsBackEverySource(): void
    {
        $id = $this->insertProvider(self::LEGACY_PHP_VECTOR);

        try {
            (new SecretRewrapService(
                Yii::$app->db,
                new CryptoService(str_repeat('x', 32))
            ))->rewrap(true);
            $this->fail('Wrong encryption key must fail rewrap');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($id, $exception->getMessage());
        }

        $this->assertSame(self::LEGACY_PHP_VECTOR, $this->providerSecret($id));
    }

    private function service(): SecretRewrapService
    {
        return new SecretRewrapService(Yii::$app->db, new CryptoService(self::KEY));
    }

    private function insertProvider(string $secret): string
    {
        return (string)Yii::$app->db->createCommand(
            "INSERT INTO ldap_providers(name,host,bind_dn,bind_password,base_dn,enabled)
             VALUES (:name,'ldap.example.test','cn=admin',:secret,'dc=example,dc=test',false)
             RETURNING id",
            [':name' => 'rewrap-' . bin2hex(random_bytes(5)), ':secret' => $secret]
        )->queryScalar();
    }

    private function providerSecret(string $id): string
    {
        return (string)Yii::$app->db->createCommand(
            'SELECT bind_password FROM ldap_providers WHERE id = :id',
            [':id' => $id]
        )->queryScalar();
    }
}
