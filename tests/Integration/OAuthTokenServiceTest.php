<?php

namespace tests\Integration;

use app\models\OAuthClient;
use app\services\AuthService;
use app\services\JwtService;
use app\services\OAuthService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\web\Request;

class OAuthTokenServiceTest extends TestCase
{
    private string $username;
    private ?string $userId = null;
    private array $clientIds = [];
    private array $clientDbIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }
        Yii::$app->params = array_merge(require dirname(__DIR__, 2) . '/config/params.php', Yii::$app->params);

        $suffix = bin2hex(random_bytes(4));
        $this->username = 'phpunit_oauth_' . $suffix;
        $this->userId = Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source,email_verified)
             VALUES (:username,:email,:password_hash,'active','local',true)
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
            Yii::$app->db->createCommand()->delete('sessions', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('refresh_tokens', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
        }

        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        parent::tearDown();
    }

    public function testAuthorizationCodeTokenRequiresPkceAndRejectsCodeReuse(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $clientId = $flow['client_id'];
        $clientSecret = $flow['client_secret'];
        $code = $flow['code'];
        $verifier = $flow['verifier'];
        $token = $flow['token'];
        $service = new OAuthService();

        $this->assertTrue($token['ok']);
        $this->assertSame('Bearer', $token['data']['token_type']);
        $this->assertSame('profile', $token['data']['scope']);
        $this->assertNotEmpty($token['data']['access_token']);
        $this->assertNotEmpty($token['data']['refresh_token']);
        $this->assertSame($this->userId, (new AuthService())->currentUser($this->bearerRequest($token['data']['access_token']))->id);
        $this->assertSame(1, $this->usedAuthorizationCodeCount($clientId));
        $this->assertSame(1, $this->refreshTokenCount($clientId));

        $reuse = $service->token($this->postRequest([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => 'https://rp.example.test/callback',
            'code_verifier' => $verifier,
        ]));

        $this->assertFalse($reuse['ok']);
        $this->assertSame(400, $reuse['status']);
        $this->assertSame('invalid_grant', $reuse['error']);
    }

    public function testFailedPkceExchangeConsumesAuthorizationCode(): void
    {
        $flow = $this->issueAuthorizationCodeToken(false);
        $service = new OAuthService();
        $request = [
            'grant_type' => 'authorization_code',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'code' => $flow['code'],
            'redirect_uri' => 'https://rp.example.test/callback',
        ];

        $invalid = $service->token($this->postRequest(array_merge($request, [
            'code_verifier' => 'wrong-verifier',
        ])));
        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid_grant', $invalid['error']);
        $this->assertSame(1, $this->usedAuthorizationCodeCount($flow['client_id']));

        $retry = $service->token($this->postRequest(array_merge($request, [
            'code_verifier' => $flow['verifier'],
        ])));
        $this->assertFalse($retry['ok']);
        $this->assertSame('invalid_grant', $retry['error']);
        $this->assertSame(0, $this->refreshTokenCount($flow['client_id']));
    }

    public function testRefreshRotationRejectsReuseAndRevokesDescendants(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $service = new OAuthService();

        $refresh = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ]));

        $this->assertTrue($refresh['ok']);
        $this->assertNotSame($flow['token']['data']['refresh_token'], $refresh['data']['refresh_token']);
        $this->assertSame(2, $this->refreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->rotatedRefreshTokenCount($flow['client_id']));

        $reuse = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ]));

        $this->assertFalse($reuse['ok']);
        $this->assertSame('invalid_grant', $reuse['error']);
        $this->assertSame(2, $this->revokedRefreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->auditCount('refresh_token_reuse_detected', $flow['client_id']));

        $descendant = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $refresh['data']['refresh_token'],
        ]));

        $this->assertFalse($descendant['ok']);
        $this->assertSame('invalid_grant', $descendant['error']);
    }

    public function testRevokeRefreshTokenPreventsFurtherRefresh(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $service = new OAuthService();

        $revoke = $service->revoke($this->postRequest([
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'token' => $flow['token']['data']['refresh_token'],
        ]));

        $this->assertTrue($revoke['ok']);
        $this->assertSame(1, $this->revokedRefreshTokenCount($flow['client_id']));

        $refresh = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ]));

        $this->assertFalse($refresh['ok']);
        $this->assertSame('invalid_grant', $refresh['error']);
    }

    public function testRefreshIntrospectionAndRevocationCoverEntireFamily(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $service = new OAuthService();
        $credentials = [
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
        ];

        $active = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $flow['token']['data']['refresh_token'],
        ])));
        $this->assertTrue($active['ok']);
        $this->assertTrue($active['data']['active']);
        $this->assertSame('refresh_token', $active['data']['token_type']);
        $this->assertSame($this->userId, $active['data']['sub']);

        $rotated = $service->token($this->postRequest(array_merge($credentials, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ])));
        $this->assertTrue($rotated['ok']);

        $old = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $flow['token']['data']['refresh_token'],
        ])));
        $this->assertFalse($old['data']['active']);

        $revoke = $service->revoke($this->postRequest(array_merge($credentials, [
            'token' => $rotated['data']['refresh_token'],
        ])));
        $this->assertTrue($revoke['ok']);
        $this->assertSame(2, $this->revokedRefreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->auditCount('refresh_token_revoked', $flow['client_id']));

        $revoked = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $rotated['data']['refresh_token'],
        ])));
        $this->assertFalse($revoked['data']['active']);
    }

    private function issueAuthorizationCodeToken(bool $exchange = true): array
    {
        $service = new OAuthService();
        $clientId = 'phpunit_oauth_client_' . bin2hex(random_bytes(4));
        $this->clientIds[] = $clientId;
        $created = $service->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit OAuth token client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => ['https://rp.example.test/callback'],
            'allowed_scopes' => ['profile'],
            'skip_consent' => true,
            'enabled' => true,
        ]);
        $this->assertSame(201, $created['status']);
        $this->clientDbIds[] = (string)$created['data']['id'];

        $verifier = 'verifier-' . bin2hex(random_bytes(24));
        $challenge = JwtService::base64url(hash('sha256', $verifier, true));
        $authorize = $service->authorize($this->getRequest([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://rp.example.test/callback',
            'scope' => 'profile',
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], ['sso_session' => $this->loginSessionToken()]));

        $this->assertTrue($authorize['ok']);
        $redirect = parse_url($authorize['redirect']);
        parse_str($redirect['query'] ?? '', $query);
        $this->assertNotEmpty($query['code']);
        $this->assertSame('state-123', $query['state']);

        $token = $exchange
            ? $service->token($this->postRequest([
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $created['data']['client_secret'],
                'code' => $query['code'],
                'redirect_uri' => 'https://rp.example.test/callback',
                'code_verifier' => $verifier,
            ]))
            : null;

        return [
            'client_id' => $clientId,
            'client_secret' => $created['data']['client_secret'],
            'code' => $query['code'],
            'verifier' => $verifier,
            'token' => $token,
        ];
    }

    private function loginSessionToken(): string
    {
        $login = (new AuthService())->login([
            'username' => $this->username,
            'password' => 'correct-password-123',
        ], $this->getRequest());

        $this->assertTrue($login['ok']);
        return (string)$login['cookie']->value;
    }

    private function getRequest(array $query = [], array $cookies = []): Request
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.45';
        $_COOKIE = $cookies;
        $request = new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $request->setQueryParams($query);

        return $request;
    }

    private function postRequest(array $body): Request
    {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        $request = $this->getRequest();
        $request->setBodyParams($body);

        return $request;
    }

    private function bearerRequest(string $token): Request
    {
        $request = $this->getRequest();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function usedAuthorizationCodeCount(string $clientId): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM oauth_authorization_codes WHERE client_id = :client_id AND used_at IS NOT NULL',
            [':client_id' => $clientId]
        )->queryScalar();
    }

    private function refreshTokenCount(string $clientId): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM refresh_tokens WHERE client_id = :client_id AND user_id = :user_id',
            [':client_id' => $clientId, ':user_id' => $this->userId]
        )->queryScalar();
    }

    private function rotatedRefreshTokenCount(string $clientId): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM refresh_tokens WHERE client_id = :client_id AND user_id = :user_id AND rotated_at IS NOT NULL',
            [':client_id' => $clientId, ':user_id' => $this->userId]
        )->queryScalar();
    }

    private function revokedRefreshTokenCount(string $clientId): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM refresh_tokens WHERE client_id = :client_id AND user_id = :user_id AND revoked_at IS NOT NULL',
            [':client_id' => $clientId, ':user_id' => $this->userId]
        )->queryScalar();
    }

    private function auditCount(string $action, string $targetId): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :action AND target_id = :target_id',
            [':action' => $action, ':target_id' => $targetId]
        )->queryScalar();
    }
}
