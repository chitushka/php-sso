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
        $this->assertSame('phpunit-client', $claims['azp']);
        $this->assertSame(Yii::$app->params['issuer'], $claims['iss']);
        $this->assertSame('nonce-123', $claims['nonce']);
        $this->assertIsInt($claims['auth_time']);
        $this->assertLessThanOrEqual($claims['iat'], $claims['auth_time']);
        $this->assertSame($this->username, $claims['preferred_username']);
        $this->assertSame($this->username . '@example.test', $claims['email']);
        $storedPrivateKey = (string)Yii::$app->db->createCommand(
            'SELECT private_key_pem FROM oidc_signing_keys WHERE status = :status ORDER BY created_at DESC LIMIT 1',
            [':status' => 'active']
        )->queryScalar();
        $this->assertStringStartsWith('enc:v1:', $storedPrivateKey);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $storedPrivateKey);

        $jwks = $service->jwks();
        $this->assertNotEmpty($jwks['keys'][0]['kid']);
        $this->assertSame('RSA', $jwks['keys'][0]['kty']);
        $this->assertSame('RS256', $jwks['keys'][0]['alg']);

        $logoutToken = $service->issueLogoutToken($this->userId, 'phpunit-client');
        $logoutClaims = $service->verifyIdTokenHint($logoutToken);
        $this->assertSame($this->userId, $logoutClaims['sub']);
        $this->assertSame('phpunit-client', $logoutClaims['aud']);
        $this->assertNotEmpty($logoutClaims['jti']);
        $this->assertArrayHasKey('http://schemas.openid.net/event/backchannel-logout', $logoutClaims['events']);
        $this->assertSame(120, $logoutClaims['exp'] - $logoutClaims['iat']);
    }

    public function testSigningKeyRotationKeepsOldKeyInJwksDuringGracePeriod(): void
    {
        $service = new OidcService();
        $token = $service->issueIdToken($this->userId, 'rotation-client');
        $header = $this->jwtPart($token, 0);
        $oldKid = (string)$header['kid'];
        $oldKey = Yii::$app->db->createCommand(
            'SELECT id, created_at, expires_at FROM oidc_signing_keys WHERE kid = :kid',
            [':kid' => $oldKid]
        )->queryOne();
        $newKid = null;

        try {
            Yii::$app->db->createCommand(
                "UPDATE oidc_signing_keys SET created_at = now() - interval '31 days' WHERE kid = :kid",
                [':kid' => $oldKid]
            )->execute();

            $jwks = $service->jwks();
            $kids = array_column($jwks['keys'], 'kid');
            $newKid = (string)$kids[0];

            $this->assertNotSame($oldKid, $newKid);
            $this->assertContains($oldKid, $kids);
            $this->assertNotNull($service->verifyIdTokenHint($token));
            $this->assertSame('retiring', $this->keyStatus($oldKid));

            Yii::$app->db->createCommand(
                "UPDATE oidc_signing_keys SET expires_at = now() - interval '1 second' WHERE kid = :kid",
                [':kid' => $oldKid]
            )->execute();

            $afterGrace = $service->jwks();
            $this->assertNotContains($oldKid, array_column($afterGrace['keys'], 'kid'));
            $this->assertSame('retired', $this->keyStatus($oldKid));
            $this->assertNull($service->verifyIdTokenHint($token));
        } finally {
            if ($newKid !== null && $newKid !== $oldKid) {
                Yii::$app->db->createCommand()->delete('oidc_signing_keys', ['kid' => $newKid])->execute();
            }
            Yii::$app->db->createCommand()->update('oidc_signing_keys', [
                'status' => 'active',
                'created_at' => $oldKey['created_at'],
                'expires_at' => $oldKey['expires_at'],
            ], ['kid' => $oldKid])->execute();
        }
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
        $this->assertSame($this->username, $full['preferred_username']);
        $this->assertSame($this->username . '@example.test', $full['email']);
        $this->assertArrayNotHasKey('name', $full);
        $this->assertArrayNotHasKey('email_verified', $full);
    }

    public function testLogoutWithIdTokenHintValidatesRedirectAndRevokesRefreshTokens(): void
    {
        $oauth = new OAuthService();
        $clientId = 'phpunit_oidc_client_' . bin2hex(random_bytes(4));
        $logoutUri = 'https://rp.example.test/signed-out?tenant=one&state=old';
        $this->clientIds[] = $clientId;
        $created = $oauth->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit OIDC client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => ['https://rp.example.test/callback'],
            'allowed_scopes' => ['openid', 'profile'],
            'post_logout_redirect_uris' => [$logoutUri],
            'enabled' => true,
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
        $logout = $oauth->logout($this->getRequest(
            [
                'id_token_hint' => 'query-must-not-win',
                'post_logout_redirect_uri' => 'https://rp.example.test/not-registered',
                'state' => 'query-state',
            ],
            [
                'id_token_hint' => $idToken,
                'post_logout_redirect_uri' => $logoutUri,
                'state' => 'logout-state',
            ]
        ));

        $this->assertTrue($logout['ok']);
        $redirect = parse_url($logout['redirect']);
        parse_str($redirect['query'] ?? '', $query);
        $this->assertSame('one', $query['tenant']);
        $this->assertSame('logout-state', $query['state']);
        $this->assertArrayNotHasKey('fragment', $redirect);
        $this->assertSame(1, $this->revokedRefreshTokenCount($clientId));
        $this->assertSame(1, $this->auditCount('logout'));

        $badRedirect = $oauth->logout($this->getRequest([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => 'https://rp.example.test/not-registered',
        ]));

        $this->assertFalse($badRedirect['ok']);
        $this->assertSame('invalid_request', $badRedirect['error']);
    }

    private function getRequest(array $query = [], array $body = []): Request
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.46';
        $_SERVER['REQUEST_METHOD'] = $body === [] ? 'GET' : 'POST';
        $_COOKIE = [];
        $request = new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $request->setQueryParams($query);
        $request->setBodyParams($body);

        return $request;
    }

    private function bearerRequest(string $token): Request
    {
        $request = $this->getRequest();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function jwtPart(string $token, int $index): array
    {
        $parts = explode('.', $token);
        return json_decode(JwtService::base64urlDecode($parts[$index]), true);
    }

    private function keyStatus(string $kid): string
    {
        return (string)Yii::$app->db->createCommand(
            'SELECT status FROM oidc_signing_keys WHERE kid = :kid',
            [':kid' => $kid]
        )->queryScalar();
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
