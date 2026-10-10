<?php

namespace tests\Integration;

use app\services\CryptoService;
use app\services\DeploymentCheckService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Transaction;

class DeploymentCheckServiceTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

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
        Yii::$app->db->createCommand()->update('ldap_providers', ['bind_password' => '', 'enabled' => false])->execute();
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

    public function testReadyConfigurationSchemaAndSecretsHaveNoFailures(): void
    {
        $results = $this->service()->check();

        $this->assertFalse(DeploymentCheckService::hasFailures($results), $this->failureMessages($results));
        $this->assertSame(DeploymentCheckService::PASS, $this->status($results, 'database.schema'));
        $this->assertSame(DeploymentCheckService::PASS, $this->status($results, 'database.pgcrypto'));
        $this->assertSame(DeploymentCheckService::PASS, $this->status($results, 'secrets.decrypt'));
    }

    public function testConfigurationMismatchIsReportedAsFailure(): void
    {
        $environment = $this->environment();
        $environment['SSO_ISSUER'] = 'http://sso.example.test';
        $environment['SSO_DEPLOYMENT_EXPECTED_ISSUER'] = 'https://expected-sso.example.test';
        $environment['SSO_JWT_SECRET'] = 'change-me';
        $environment['SSO_ACCESS_TOKEN_TTL'] = '15m';

        $results = $this->service($environment)->check();

        $this->assertTrue(DeploymentCheckService::hasFailures($results));
        $this->assertSame(DeploymentCheckService::FAIL, $this->status($results, 'config.issuer_https'));
        $this->assertSame(DeploymentCheckService::FAIL, $this->status($results, 'config.issuer_match'));
        $this->assertSame(DeploymentCheckService::FAIL, $this->status($results, 'config.jwt_secret_strength'));
        $this->assertSame(DeploymentCheckService::FAIL, $this->status($results, 'config.access_token_ttl'));
    }

    public function testWrongEncryptionKeyIsDetectedWithoutExposingSecret(): void
    {
        $id = $this->insertProvider((new CryptoService(str_repeat('x', 32)))->encrypt('bind-password'));
        $results = $this->service()->check();

        $this->assertSame(DeploymentCheckService::FAIL, $this->status($results, 'secrets.decrypt'));
        $message = $this->message($results, 'secrets.decrypt');
        $this->assertStringContainsString($id, $message);
        $this->assertStringNotContainsString('bind-password', $message);
    }

    public function testUnsupportedCiphertextIsRejected(): void
    {
        $this->insertProvider('v1:unsupported');
        $results = $this->service()->check();

        $this->assertSame(DeploymentCheckService::FAIL, $this->status($results, 'secrets.decrypt'));
    }

    private function service(?array $environment = null): DeploymentCheckService
    {
        return new DeploymentCheckService(
            Yii::$app->db,
            $environment ?? $this->environment(),
            new CryptoService(self::KEY)
        );
    }

    private function environment(): array
    {
        return [
            'SSO_ENV' => 'prod',
            'SSO_DATABASE_URL' => 'postgres://sso:secret@postgres:5432/sso',
            'SSO_ISSUER' => 'https://sso.example.test',
            'SSO_DEPLOYMENT_EXPECTED_ISSUER' => 'https://sso.example.test',
            'SSO_JWT_SECRET' => str_repeat('j', 32),
            'SSO_ENCRYPTION_KEY' => self::KEY,
            'SSO_ACCESS_TOKEN_TTL' => '900',
            'SSO_SESSION_TTL' => '2592000',
            'SSO_REFRESH_TOKEN_TTL' => '2592000',
            'SSO_COOKIE_SECURE' => 'true',
            'SSO_CACHE_DRIVER' => 'db',
            'SSO_TRUSTED_PROXIES' => '10.0.0.0/8',
        ];
    }

    private function insertProvider(string $secret): string
    {
        return (string)Yii::$app->db->createCommand(
            "INSERT INTO ldap_providers(name,host,bind_dn,bind_password,base_dn,enabled)
             VALUES (:name,'ldap.example.test','cn=admin',:secret,'dc=example,dc=test',false)
             RETURNING id",
            [':name' => 'deployment-' . bin2hex(random_bytes(5)), ':secret' => $secret]
        )->queryScalar();
    }

    private function status(array $results, string $check): string
    {
        foreach ($results as $result) {
            if ($result['check'] === $check) {
                return (string)$result['status'];
            }
        }

        $this->fail('Missing check result: ' . $check);
    }

    private function message(array $results, string $check): string
    {
        foreach ($results as $result) {
            if ($result['check'] === $check) {
                return (string)$result['message'];
            }
        }

        $this->fail('Missing check result: ' . $check);
    }

    private function failureMessages(array $results): string
    {
        $messages = [];
        foreach ($results as $result) {
            if ($result['status'] === DeploymentCheckService::FAIL) {
                $messages[] = $result['check'] . ': ' . $result['message'];
            }
        }

        return implode('; ', $messages);
    }
}
