<?php

namespace tests\Integration;

require_once __DIR__ . '/ConcurrentProcessTrait.php';

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
    use ConcurrentProcessTrait;
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

        $this->assertTrue($token->isSuccess());
        $this->assertSame('Bearer', $token['data']['token_type']);
        $this->assertSame('profile', $token['data']['scope']);
        $this->assertNotEmpty($token['data']['access_token']);
        $this->assertNotEmpty($token['data']['refresh_token']);
        $accessClaims = $this->jwtPayload($token['data']['access_token']);
        $this->assertSame($this->username . '@example.test', $accessClaims['email']);
        $this->assertSame('local', $accessClaims['source']);
        $this->assertSame(Yii::$app->params['issuer'], $accessClaims['iss']);
        $this->assertSame(Yii::$app->params['apiAudience'], $accessClaims['aud']);
        $this->assertSame(JwtService::PURPOSE_OAUTH_USER, $accessClaims['purpose']);
        $this->assertSame($accessClaims['iat'], $accessClaims['nbf']);
        $this->assertSame($this->userId, (new AuthService())->currentUser($this->bearerRequest($token['data']['access_token']))->id);
        Yii::$app->db->createCommand()->update(
            'oauth_clients',
            ['enabled' => false],
            ['client_id' => $clientId]
        )->execute();
        $this->assertNull((new AuthService())->currentUser($this->bearerRequest($token['data']['access_token'])));
        Yii::$app->db->createCommand()->update(
            'oauth_clients',
            ['enabled' => true],
            ['client_id' => $clientId]
        )->execute();
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

        $this->assertFalse($reuse->isSuccess());
        $this->assertSame(400, $reuse['status']);
        $this->assertSame('invalid_grant', $reuse->error->code);
    }

    public function testFailedPkceExchangeDoesNotConsumeAuthorizationCode(): void
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
        $this->assertFalse($invalid->isSuccess());
        $this->assertSame('invalid_grant', $invalid->error->code);
        $this->assertSame(0, $this->usedAuthorizationCodeCount($flow['client_id']));

        $retry = $service->token($this->postRequest(array_merge($request, [
            'code_verifier' => $flow['verifier'],
        ])));
        $this->assertTrue($retry->isSuccess());
        $this->assertSame(1, $this->usedAuthorizationCodeCount($flow['client_id']));
        $this->assertSame(1, $this->refreshTokenCount($flow['client_id']));
    }

    public function testIdTokenCarriesNonceAndGrantAuthenticationTime(): void
    {
        $flow = $this->issueAuthorizationCodeToken(true, 'openid profile', 'nonce-from-authorize');
        $codeClaims = $this->jwtPayload($flow['token']['data']['id_token']);
        $codeCreatedAt = (int)Yii::$app->db->createCommand(
            'SELECT FLOOR(EXTRACT(EPOCH FROM created_at))::bigint FROM oauth_authorization_codes WHERE client_id = :client_id',
            [':client_id' => $flow['client_id']]
        )->queryScalar();

        $this->assertSame('nonce-from-authorize', $codeClaims['nonce']);
        $this->assertSame($codeCreatedAt, $codeClaims['auth_time']);

        $refreshAuthTime = 1700000000;
        Yii::$app->db->createCommand(
            'UPDATE refresh_tokens SET created_at = to_timestamp(:auth_time) WHERE token_hash = :token_hash',
            [
                ':auth_time' => $refreshAuthTime,
                ':token_hash' => hash('sha256', $flow['token']['data']['refresh_token']),
            ]
        )->execute();

        $refresh = (new OAuthService())->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ]));
        $refreshClaims = $this->jwtPayload($refresh['data']['id_token']);

        $this->assertTrue($refresh->isSuccess());
        $this->assertArrayNotHasKey('nonce', $refreshClaims);
        $this->assertSame($refreshAuthTime, $refreshClaims['auth_time']);
    }

    public function testClientRegistrationRejectsRedirectFragments(): void
    {
        $redirectUri = 'https://rp.example.test/callback?tenant=one&state=old&code=old#section';
        $created = (new OAuthService())->createClient([
            'client_id' => 'fragment-' . bin2hex(random_bytes(4)),
            'name' => 'Fragment client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => [$redirectUri],
            'enabled' => true,
        ]);

        $this->assertSame(400, $created['status']);
        $this->assertSame('redirect URIs violate the client redirect policy', $created['data']['error']);
    }

    public function testBasicClientCredentialsUseFormUrlEncoding(): void
    {
        $clientId = 'phpunit oauth+' . bin2hex(random_bytes(4));
        $this->clientIds[] = $clientId;
        $created = (new OAuthService())->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit encoded Basic client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'allowed_scopes' => ['profile'],
            'enabled' => true,
        ]);
        $this->clientDbIds[] = (string)$created['data']['id'];

        $request = $this->postRequest([
            'grant_type' => 'client_credentials',
            'scope' => 'profile',
        ]);
        $request->headers->set(
            'Authorization',
            'Basic ' . base64_encode(
                urlencode($clientId) . ':' . urlencode($created['data']['client_secret'])
            )
        );
        $token = (new OAuthService())->token($request);

        $this->assertTrue($token->isSuccess());
        $this->assertSame('profile', $token['data']['scope']);
        $tokenClaims = $this->jwtPayload($token['data']['access_token']);
        $this->assertSame($clientId, $tokenClaims['client_id']);
        $this->assertSame('', $tokenClaims['email']);
        $this->assertSame('client', $tokenClaims['source']);
        $this->assertSame(Yii::$app->params['issuer'], $tokenClaims['iss']);
        $this->assertSame(Yii::$app->params['apiAudience'], $tokenClaims['aud']);
        $this->assertSame(JwtService::PURPOSE_CLIENT_CREDENTIALS, $tokenClaims['purpose']);
        $machineRequest = $this->bearerRequest($token['data']['access_token']);
        $this->assertSame($clientId, (new AuthService())->authenticate($machineRequest)['client']->client_id);
        Yii::$app->db->createCommand()->update(
            'oauth_clients',
            ['enabled' => false],
            ['client_id' => $clientId]
        )->execute();
        $disabledAuthentication = (new AuthService())->authenticate($machineRequest);
        $this->assertNull($disabledAuthentication['client'] ?? null);
        Yii::$app->db->createCommand()->update(
            'oauth_clients',
            ['enabled' => true],
            ['client_id' => $clientId]
        )->execute();

        $emptyScopeRequest = $this->postRequest(['grant_type' => 'client_credentials']);
        $emptyScopeRequest->headers->set(
            'Authorization',
            'Basic ' . base64_encode(
                urlencode($clientId) . ':' . urlencode($created['data']['client_secret'])
            )
        );
        $emptyScopeToken = (new OAuthService())->token($emptyScopeRequest);

        $this->assertTrue($emptyScopeToken->isSuccess());
        $this->assertArrayNotHasKey('scope', $emptyScopeToken['data']);
        $this->assertArrayNotHasKey('scope', $this->jwtPayload($emptyScopeToken['data']['access_token']));
    }

    public function testAuthorizeErrorsRedirectOnlyAfterRegisteredRedirectValidation(): void
    {
        $flow = $this->issueAuthorizationCodeToken(false);
        $verifier = 'error-verifier-' . bin2hex(random_bytes(20));
        $challenge = JwtService::base64url(hash('sha256', $verifier, true));
        $service = new OAuthService();

        $missingState = $service->authorize($this->getRequest([
            'response_type' => 'code',
            'client_id' => $flow['client_id'],
            'redirect_uri' => 'https://rp.example.test/callback',
            'scope' => 'profile',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], ['sso_session' => $this->loginSessionToken()]));
        $this->assertTrue($missingState->isSuccess());
        parse_str((string)parse_url($missingState->metadata['redirect'], PHP_URL_QUERY), $errorQuery);
        $this->assertSame('invalid_request', $errorQuery['error']);
        $this->assertSame(Yii::$app->params['issuer'], $errorQuery['iss']);

        $unregistered = $service->authorize($this->getRequest([
            'response_type' => 'code',
            'client_id' => $flow['client_id'],
            'redirect_uri' => 'https://attacker.example/callback',
            'scope' => 'profile',
            'state' => 'state-value',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
        $this->assertFalse($unregistered->isSuccess());
        $this->assertArrayNotHasKey('redirect', $unregistered->metadata);
    }

    public function testOpenidAuthorizeRequiresNonceAndAllClientsRequirePkceS256(): void
    {
        $flow = $this->issueAuthorizationCodeToken(false);
        $service = new OAuthService();
        $base = [
            'response_type' => 'code',
            'client_id' => $flow['client_id'],
            'redirect_uri' => 'https://rp.example.test/callback',
            'state' => 'required-state',
        ];
        $cookie = ['sso_session' => $this->loginSessionToken()];

        $missingPkce = $service->authorize($this->getRequest(array_merge($base, [
            'scope' => 'profile',
        ]), $cookie));
        parse_str((string)parse_url($missingPkce->metadata['redirect'], PHP_URL_QUERY), $pkceError);
        $this->assertSame('invalid_request', $pkceError['error']);

        $verifier = 'nonce-verifier-' . bin2hex(random_bytes(20));
        $missingNonce = $service->authorize($this->getRequest(array_merge($base, [
            'scope' => 'openid',
            'code_challenge' => JwtService::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]), $cookie));
        parse_str((string)parse_url($missingNonce->metadata['redirect'], PHP_URL_QUERY), $nonceError);
        $this->assertSame('invalid_request', $nonceError['error']);
        $this->assertStringContainsString('nonce', $nonceError['error_description']);
    }

    public function testClientCannotUseTwoAuthenticationMethods(): void
    {
        $flow = $this->issueAuthorizationCodeToken(false);
        $request = $this->postRequest([
            'grant_type' => 'authorization_code',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'code' => $flow['code'],
            'redirect_uri' => 'https://rp.example.test/callback',
            'code_verifier' => $flow['verifier'],
        ]);
        $request->headers->set(
            'Authorization',
            'Basic ' . base64_encode(urlencode($flow['client_id']) . ':' . urlencode($flow['client_secret']))
        );

        $result = (new OAuthService())->token($request);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('invalid_client', $result->error->code);
    }

    public function testUserCanListAndRevokeConsentWithRefreshTokens(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $service = new OAuthService();
        $request = $this->getRequest([], ['sso_session' => $this->loginSessionToken()]);
        $granted = $service->grantConsent($request, [
            'client_id' => $flow['client_id'],
            'scope' => 'profile',
        ]);
        $this->assertTrue($granted->isSuccess());

        $consents = $service->listUserConsents($this->userId);
        $this->assertSame($flow['client_id'], $consents[0]['client_id']);
        $this->assertSame(['profile'], $consents[0]['scopes']);

        $this->assertTrue($service->revokeUserConsent($this->userId, $flow['client_id']));
        $this->assertSame([], $service->listUserConsents($this->userId));
        $this->assertSame(1, $this->revokedRefreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->auditCount('consent_revoked', $flow['client_id']));
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

        $this->assertTrue($refresh->isSuccess());
        $this->assertNotSame($flow['token']['data']['refresh_token'], $refresh['data']['refresh_token']);
        $this->assertSame(2, $this->refreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->rotatedRefreshTokenCount($flow['client_id']));

        $reuse = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ]));

        $this->assertFalse($reuse->isSuccess());
        $this->assertSame('invalid_grant', $reuse->error->code);
        $this->assertSame(2, $this->revokedRefreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->auditCount('refresh_token_reuse_detected', $flow['client_id']));

        $descendant = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $refresh['data']['refresh_token'],
        ]));

        $this->assertFalse($descendant->isSuccess());
        $this->assertSame('invalid_grant', $descendant->error->code);
    }

    public function testConcurrentRefreshRotationIssuesOnlyOneDescendant(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $arguments = [
            'oauth-refresh',
            $flow['client_id'],
            $flow['client_secret'],
            $flow['token']['data']['refresh_token'],
        ];

        $results = $this->runConcurrentWorkers([$arguments, $arguments]);
        sort($results);

        $this->assertSame(['invalid_grant', 'success'], $results);
        $this->assertSame(2, $this->refreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->rotatedRefreshTokenCount($flow['client_id']));
        $this->assertSame(2, $this->revokedRefreshTokenCount($flow['client_id']));
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

        $this->assertTrue($revoke->isSuccess());
        $this->assertSame(1, $this->revokedRefreshTokenCount($flow['client_id']));

        $refresh = $service->token($this->postRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $flow['client_id'],
            'client_secret' => $flow['client_secret'],
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ]));

        $this->assertFalse($refresh->isSuccess());
        $this->assertSame('invalid_grant', $refresh->error->code);
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
        $this->assertTrue($active->isSuccess());
        $this->assertTrue($active['data']['active']);
        $this->assertSame('refresh_token', $active['data']['token_type']);
        $this->assertSame($this->userId, $active['data']['sub']);

        $rotated = $service->token($this->postRequest(array_merge($credentials, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $flow['token']['data']['refresh_token'],
        ])));
        $this->assertTrue($rotated->isSuccess());

        $old = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $flow['token']['data']['refresh_token'],
        ])));
        $this->assertFalse($old['data']['active']);

        $revoke = $service->revoke($this->postRequest(array_merge($credentials, [
            'token' => $rotated['data']['refresh_token'],
        ])));
        $this->assertTrue($revoke->isSuccess());
        $this->assertSame(2, $this->revokedRefreshTokenCount($flow['client_id']));
        $this->assertSame(1, $this->auditCount('refresh_token_revoked', $flow['client_id']));

        $revoked = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $rotated['data']['refresh_token'],
        ])));
        $this->assertFalse($revoked['data']['active']);
    }

    public function testIntrospectionCannotObserveAnotherClientsTokens(): void
    {
        $flow = $this->issueAuthorizationCodeToken();
        $otherId = 'phpunit-introspector-' . bin2hex(random_bytes(4));
        $this->clientIds[] = $otherId;
        $other = (new OAuthService())->createClient([
            'client_id' => $otherId,
            'name' => 'Isolated introspection client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'allowed_scopes' => ['profile'],
            'enabled' => true,
        ]);
        $this->clientDbIds[] = (string)$other['data']['id'];
        $credentials = [
            'client_id' => $otherId,
            'client_secret' => $other['data']['client_secret'],
        ];
        $service = new OAuthService();

        $access = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $flow['token']['data']['access_token'],
        ])));
        $refresh = $service->introspect($this->postRequest(array_merge($credentials, [
            'token' => $flow['token']['data']['refresh_token'],
        ])));

        $this->assertTrue($access->isSuccess());
        $this->assertFalse($access['data']['active']);
        $this->assertTrue($refresh->isSuccess());
        $this->assertFalse($refresh['data']['active']);
    }

    private function issueAuthorizationCodeToken(
        bool $exchange = true,
        string $scope = 'profile',
        string $nonce = '',
        string $redirectUri = 'https://rp.example.test/callback'
    ): array
    {
        $service = new OAuthService();
        $clientId = 'phpunit_oauth_client_' . bin2hex(random_bytes(4));
        $this->clientIds[] = $clientId;
        $created = $service->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit OAuth token client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => [$redirectUri],
            'allowed_scopes' => ['openid', 'profile'],
            'skip_consent' => true,
            'enabled' => true,
        ]);
        $this->assertSame(201, $created['status'], json_encode($created));
        $this->clientDbIds[] = (string)$created['data']['id'];

        $verifier = 'verifier-' . bin2hex(random_bytes(24));
        $challenge = JwtService::base64url(hash('sha256', $verifier, true));
        $authorize = $service->authorize($this->getRequest([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ], ['sso_session' => $this->loginSessionToken()]));

        $this->assertTrue($authorize->isSuccess());
        $redirect = parse_url($authorize->metadata['redirect']);
        parse_str($redirect['query'] ?? '', $query);
        $this->assertNotEmpty($query['code']);
        $this->assertSame('state-123', $query['state']);

        $token = $exchange
            ? $service->token($this->postRequest([
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $created['data']['client_secret'],
                'code' => $query['code'],
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
            ]))
            : null;

        return [
            'client_id' => $clientId,
            'client_secret' => $created['data']['client_secret'],
            'code' => $query['code'],
            'verifier' => $verifier,
            'token' => $token,
            'authorize_redirect' => $authorize->metadata['redirect'],
        ];
    }

    private function loginSessionToken(): string
    {
        $login = (new AuthService())->login([
            'username' => $this->username,
            'password' => 'correct-password-123',
        ], $this->getRequest());

        $this->assertTrue($login->isSuccess());
        return (string)$login->metadata['cookie']->value;
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

    private function jwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        return json_decode(JwtService::base64urlDecode($parts[1]), true);
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
