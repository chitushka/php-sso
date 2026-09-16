<?php

namespace tests\Integration;

use app\controllers\OidcController;
use app\models\OAuthClient;
use app\services\JwtService;
use app\services\OAuthService;
use app\services\OidcService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\FileCache;
use yii\web\Application;
use yii\web\Request;
use yii\web\Response;

class OidcServiceTest extends TestCase
{
    private string $username;
    private ?string $userId = null;
    private array $clientIds = [];
    private array $clientDbIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null) {
            Yii::$app = null;
        }

        new Application([
            'id' => 'sso-oidc-integration',
            'basePath' => dirname(__DIR__, 2),
            'components' => [
                'db' => require dirname(__DIR__, 2) . '/config/db.php',
                'cache' => FileCache::class,
                'request' => [
                    'class' => Request::class,
                    'enableCookieValidation' => false,
                    'cookieValidationKey' => 'unit-test-cookie-validation-key',
                    'scriptFile' => __FILE__,
                    'scriptUrl' => '/index.php',
                ],
                'response' => Response::class,
            ],
            'params' => require dirname(__DIR__, 2) . '/config/params.php',
        ]);

        $suffix = bin2hex(random_bytes(4));
        $this->username = 'phpunit_oidc_' . $suffix;
        $this->userId = Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source,first_name,last_name,email_verified)
             VALUES (:username,:email,:password_hash,'active','local','Open','Tester',true)
             RETURNING id",
            [
                ':username' => $this->username,
                ':email' => $this->username . '@example.test',
                ':password_hash' => password_hash('correct-password-123', PASSWORD_DEFAULT),
            ]
        )->queryScalar();
    }

    protected function tearDown(): void
    {
        if (!empty($this->clientDbIds) || !empty($this->clientIds)) {
            Yii::$app->db->createCommand()
                ->delete('audit_logs', [
                    'or',
                    ['target_id' => $this->clientDbIds],
                    ['target_id' => $this->clientIds],
                ])
                ->execute();
        }
        if (!empty($this->clientIds)) {
            Yii::$app->db->createCommand()->delete('oauth_clients', ['client_id' => $this->clientIds])->execute();
        }
        if ($this->userId !== null) {
            Yii::$app->db->createCommand()->delete('refresh_tokens', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('sessions', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
        }

        parent::tearDown();
    }

    public function testIssueIdTokenVerifiesHintAndExposesJwks(): void
    {
        $service = new OidcService();
        $idToken = $service->issueIdToken($this->userId, 'phpunit-client', 'nonce-123');
        $claims = $service->verifyIdTokenHint($idToken);

        $this->assertSame($this->userId, $claims['sub']);
        $this->assertSame('phpunit-client', $claims['aud']);
        $this->assertSame('nonce-123', $claims['nonce']);
        $this->assertSame($this->username, $claims['preferred_username']);
        $this->assertSame($this->username . '@example.test', $claims['email']);

        $jwks = $service->jwks();
        $this->assertNotEmpty($jwks['keys'][0]['kid']);
        $this->assertSame('RSA', $jwks['keys'][0]['kty']);
        $this->assertSame('RS256', $jwks['keys'][0]['alg']);
    }

    public function testUserinfoFiltersClaimsByScope(): void
    {
        $controller = new OidcController('oidc', Yii::$app);

        Yii::$app->set('request', $this->bearerRequest((new JwtService())->issue($this->userId, ['scope' => 'profile'])));
        $profile = $controller->actionUserinfo();
        $this->assertSame($this->userId, $profile['sub']);
        $this->assertSame($this->username, $profile['preferred_username']);
        $this->assertSame('local', $profile['source']);
        $this->assertArrayNotHasKey('email', $profile);

        Yii::$app->set('request', $this->bearerRequest((new JwtService())->issue($this->userId, ['scope' => 'email'])));
        $email = $controller->actionUserinfo();
        $this->assertSame($this->userId, $email['sub']);
        $this->assertSame($this->username . '@example.test', $email['email']);
        $this->assertArrayNotHasKey('preferred_username', $email);

        Yii::$app->set('request', $this->bearerRequest((new JwtService())->issue($this->userId)));
        $full = $controller->actionUserinfo();
        $this->assertSame('Open Tester', $full['name']);
        $this->assertTrue($full['email_verified']);
    }

    public function testLogoutWithIdTokenHintValidatesRedirectAndRevokesRefreshTokens(): void
    {
        $oauth = new OAuthService();
        $clientId = 'phpunit_oidc_client_' . bin2hex(random_bytes(4));
        $this->clientIds[] = $clientId;
        $created = $oauth->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit OIDC client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => ['https://rp.example.test/callback'],
            'allowed_scopes' => ['openid', 'profile'],
            'post_logout_redirect_uris' => ['https://rp.example.test/signed-out'],
        ]);
        $this->assertSame(201, $created['status']);
        $this->clientDbIds[] = (string)$created['data']['id'];

        Yii::$app->db->createCommand(
            "INSERT INTO refresh_tokens(token_hash,family_id,user_id,client_id,scope,expires_at)
             VALUES (:token_hash, gen_random_uuid(), :user_id, :client_id, 'openid profile', now() + interval '1 hour')",
            [
                ':token_hash' => hash('sha256', 'refresh-token-for-logout'),
                ':user_id' => $this->userId,
                ':client_id' => $clientId,
            ]
        )->execute();

        $idToken = (new OidcService())->issueIdToken($this->userId, $clientId);
        $logout = $oauth->logout($this->getRequest([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => 'https://rp.example.test/signed-out',
            'state' => 'logout-state',
        ]));

        $this->assertTrue($logout['ok']);
        $this->assertSame('https://rp.example.test/signed-out?state=logout-state', $logout['redirect']);
        $this->assertSame(1, $this->revokedRefreshTokenCount($clientId));
        $this->assertSame(1, $this->auditCount('logout'));

        $badRedirect = $oauth->logout($this->getRequest([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => 'https://rp.example.test/not-registered',
        ]));

        $this->assertFalse($badRedirect['ok']);
        $this->assertSame('invalid_request', $badRedirect['error']);
    }

    private function getRequest(array $query = []): Request
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.46';
        $_COOKIE = [];
        $request = new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $request->setQueryParams($query);

        return $request;
    }

    private function bearerRequest(string $token): Request
    {
        $request = $this->getRequest();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function revokedRefreshTokenCount(string $clientId): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = :user_id AND client_id = :client_id AND revoked_at IS NOT NULL',
            [':user_id' => $this->userId, ':client_id' => $clientId]
        )->queryScalar();
    }

    private function auditCount(string $action): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :user_id AND action = :action',
            [':user_id' => $this->userId, ':action' => $action]
        )->queryScalar();
    }
}
