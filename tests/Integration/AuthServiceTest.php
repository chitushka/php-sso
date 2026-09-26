<?php

namespace tests\Integration;

use app\models\User;
use app\services\AuthService;
use app\services\JwtService;
use app\services\MfaService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\web\Request;

class AuthServiceTest extends TestCase
{
    private string $username;
    private string $email;
    private ?string $userId = null;
    private string $ip = '203.0.113.44';

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
        $this->username = 'phpunit_auth_' . $suffix;
        $this->email = $this->username . '@example.test';
        $this->userId = Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source,email_verified)
             VALUES (:username,:email,:password_hash,'active','local',true)
             RETURNING id",
            [
                ':username' => $this->username,
                ':email' => $this->email,
                ':password_hash' => password_hash('correct-password-123', PASSWORD_DEFAULT),
            ]
        )->queryScalar();
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            Yii::$app->db->createCommand()->delete('sessions', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
        }
        Yii::$app->db->createCommand()->delete('login_attempts', ['username' => $this->username])->execute();

        parent::tearDown();
    }

    public function testLoginIssuesSessionJwtAndLogoutRevokesSession(): void
    {
        $service = new AuthService();
        $request = $this->request();

        $failed = $service->login([
            'username' => $this->username,
            'password' => 'wrong-password',
        ], $request);
        $this->assertFalse($failed['ok']);
        $this->assertSame(401, $failed['status']);
        $this->assertSame(1, $this->loginAttemptCount());

        $login = $service->login([
            'username' => $this->username,
            'password' => 'correct-password-123',
        ], $request);

        $this->assertTrue($login['ok']);
        $this->assertArrayNotHasKey('token_type', $login['data']);
        $this->assertArrayNotHasKey('expires_in', $login['data']);
        $this->assertSame($login['cookie']->value, $login['data']['session_token']);
        $this->assertNotFalse(strtotime($login['data']['access_token_expires_at']));
        $this->assertNotFalse(strtotime($login['data']['session_expires_at']));
        $this->assertSame($this->userId, $login['data']['user']['id']);
        $this->assertInstanceOf(\stdClass::class, $login['data']['user']['attributes']);
        $this->assertArrayNotHasKey('ldap_provider_id', $login['data']['user']);
        $this->assertArrayNotHasKey('ldap_dn', $login['data']['user']);
        $this->assertArrayNotHasKey('last_login_at', $login['data']['user']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $login['data']['user']['created_at']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $login['data']['user']['updated_at']
        );
        $this->assertSame(0, $this->loginAttemptCount());

        $sessionToken = $login['cookie']->value;
        $this->assertSame($this->userId, $service->userBySession($sessionToken)->id);
        $this->assertSame(1, $this->activeSessionCount());

        $jwtRequest = $this->request();
        $jwtRequest->headers->set('Authorization', 'Bearer ' . $login['data']['access_token']);
        $this->assertSame($this->userId, $service->currentUser($jwtRequest)->id);
        $claims = $service->currentClaims($jwtRequest);
        $this->assertSame($this->username, $claims['username']);
        $this->assertSame($this->email, $claims['email']);
        $this->assertSame(User::SOURCE_LOCAL, $claims['source']);
        $this->assertArrayNotHasKey('iss', $claims);

        $this->assertSame(1, $this->auditCount('login_success'));

        $service->logout($sessionToken);
        $this->assertNull($service->userBySession($sessionToken));
        $this->assertSame(0, $this->activeSessionCount());
    }

    public function testSessionManagementListsOnlyActiveSessionsAndRevokesOwnedSessions(): void
    {
        $service = new AuthService();
        $request = $this->request();
        $login = $service->login([
            'username' => $this->username,
            'password' => 'correct-password-123',
        ], $request);

        $activeSessionId = (string)Yii::$app->db->createCommand(
            'SELECT id FROM sessions WHERE user_id = :user_id AND revoked_at IS NULL',
            [':user_id' => $this->userId]
        )->queryScalar();
        $expiredSessionId = (string)Yii::$app->db->createCommand(
            "INSERT INTO sessions(user_id, token_hash, ip, user_agent, expires_at)
             VALUES (:user_id, :token_hash, :ip, 'expired test session', now() - interval '1 minute')
             RETURNING id",
            [
                ':user_id' => $this->userId,
                ':token_hash' => hash('sha256', 'expired-' . $this->username),
                ':ip' => $this->ip,
            ]
        )->queryScalar();
        Yii::$app->db->createCommand(
            "INSERT INTO sessions(user_id, token_hash, ip, user_agent, expires_at, revoked_at)
             VALUES (:user_id, :token_hash, :ip, 'revoked test session', now() + interval '1 hour', now())",
            [
                ':user_id' => $this->userId,
                ':token_hash' => hash('sha256', 'revoked-' . $this->username),
                ':ip' => $this->ip,
            ]
        )->execute();

        $sessions = $service->listSessions($this->userId);
        $this->assertCount(1, $sessions);
        $this->assertSame($activeSessionId, $sessions[0]['id']);
        $this->assertSame($this->userId, $sessions[0]['user_id']);
        $this->assertArrayNotHasKey('token_hash', $sessions[0]);
        $this->assertArrayNotHasKey('revoked_at', $sessions[0]);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $sessions[0]['expires_at']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $sessions[0]['created_at']
        );

        $this->assertTrue($service->revokeSession($this->userId, $expiredSessionId));
        $this->assertTrue($service->revokeSession($this->userId, $activeSessionId));
        $this->assertSame([], $service->listSessions($this->userId));
        $this->assertNull($service->userBySession($login['cookie']->value));
    }

    public function testJwtVerificationRejectsInvalidRegisteredClaimsAndEncoding(): void
    {
        $jwt = new JwtService();
        $valid = $jwt->issue($this->userId, ['username' => $this->username], 60);
        $parts = explode('.', $valid);
        $forgedAlgorithm = $this->signedToken(
            ['typ' => 'JWT', 'alg' => 'RS256'],
            json_decode(JwtService::base64urlDecode($parts[1]), true)
        );

        $this->assertNull($jwt->verify($forgedAlgorithm));
        $this->assertNull($jwt->verify($jwt->issue($this->userId, [], 0)));
        $this->assertNull($jwt->verify($this->signedToken(
            ['typ' => 'JWT', 'alg' => 'HS256'],
            ['sub' => $this->userId, 'iat' => time(), 'exp' => 'tomorrow']
        )));
        $this->assertNull($jwt->verify($this->signedToken(
            ['typ' => 'JWT', 'alg' => 'HS256'],
            ['sub' => $this->userId, 'iat' => time(), 'nbf' => time() + 60, 'exp' => time() + 120]
        )));
        $this->assertNull($jwt->verify($this->signedToken(
            ['typ' => 'JWT', 'alg' => 'HS256'],
            ['sub' => $this->userId, 'iat' => time(), 'nbf' => 'now', 'exp' => time() + 120]
        )));
        $this->assertNotNull($jwt->verify($this->signedToken(
            ['typ' => 'JWT', 'alg' => 'HS256'],
            ['sub' => $this->userId, 'iat' => time(), 'nbf' => time() - 1, 'exp' => time() + 120]
        )));

        $paddedHeader = $parts[0] . '=';
        $paddedSignature = JwtService::base64url(hash_hmac(
            'sha256',
            $paddedHeader . '.' . $parts[1],
            Yii::$app->params['jwtSecret'],
            true
        ));
        $this->assertNull($jwt->verify($paddedHeader . '.' . $parts[1] . '.' . $paddedSignature));
    }

    public function testOAuthScopesRestrictAdministrativePermissions(): void
    {
        $service = new AuthService();
        $firstParty = ['sub' => $this->userId];
        $oauth = [
            'sub' => $this->userId,
            'client_id' => 'scope-test-client',
            'scope' => 'openid profile users:read',
        ];

        $this->assertTrue($service->tokenAllowsPermission($firstParty, 'users', 'delete'));
        $this->assertTrue($service->tokenAllowsPermission($oauth, 'users', 'read'));
        $this->assertFalse($service->tokenAllowsPermission($oauth, 'users', 'delete'));
        $this->assertFalse($service->tokenAllowsPermission($oauth, 'roles', 'read'));
    }

    public function testMfaVerificationDistinguishesTokenCodeAndLockout(): void
    {
        $mfa = new MfaService();
        $user = User::findOne(['id' => $this->userId]);
        $setup = $mfa->setup($user);
        $enabled = $mfa->enable(
            User::findOne(['id' => $this->userId]),
            $setup['secret'],
            $this->totpCode($setup['secret'])
        );
        $recoveryCode = $enabled['data']['recovery_codes'][0];
        $challenge = $mfa->createLoginChallenge(User::findOne(['id' => $this->userId]));

        $service = new AuthService();
        $request = $this->request();
        $invalidToken = $service->verifyMfaLogin([
            'mfa_token' => 'unknown-token',
            'code' => $recoveryCode,
        ], $request);
        $this->assertSame(401, $invalidToken['status']);
        $this->assertSame('invalid mfa token', $invalidToken['error']);
        $this->assertSame(0, $this->loginAttemptCount());

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $invalidCode = $service->verifyMfaLogin([
                'mfa_token' => $challenge['mfa_token'],
                'code' => 'not-a-code',
            ], $request);
            $this->assertSame(401, $invalidCode['status']);
            $this->assertSame('invalid code', $invalidCode['error']);
        }
        $this->assertSame(1, $this->loginAttemptCount());

        $locked = $service->verifyMfaLogin([
            'mfa_token' => $challenge['mfa_token'],
            'code' => $recoveryCode,
        ], $request);
        $this->assertSame(429, $locked['status']);
        $this->assertSame('too many failed attempts, try again later', $locked['error']);

        Yii::$app->db->createCommand()->delete('login_attempts', [
            'username' => $this->username,
            'ip' => $this->ip,
        ])->execute();
        $verified = $service->verifyMfaLogin([
            'mfa_token' => $challenge['mfa_token'],
            'code' => $recoveryCode,
        ], $request);
        $this->assertTrue($verified['ok']);
        $this->assertSame($this->userId, $verified['data']['user']['id']);
        $this->assertSame(0, $this->loginAttemptCount());
    }

    public function testRevokeAllRejectsOldJwtAndAllowsImmediateRelogin(): void
    {
        $service = new AuthService();
        $request = $this->request();
        $firstLogin = $service->login([
            'username' => $this->username,
            'password' => 'correct-password-123',
        ], $request);

        $service->revokeAllSessions(User::findOne(['id' => $this->userId]));
        $oldTokenRequest = $this->request();
        $oldTokenRequest->headers->set('Authorization', 'Bearer ' . $firstLogin['data']['access_token']);
        $this->assertNull($service->currentUser($oldTokenRequest));

        $secondLogin = $service->login([
            'username' => $this->username,
            'password' => 'correct-password-123',
        ], $this->request());
        $newTokenRequest = $this->request();
        $newTokenRequest->headers->set('Authorization', 'Bearer ' . $secondLogin['data']['access_token']);
        $this->assertSame($this->userId, $service->currentUser($newTokenRequest)->id);

        $claims = $service->currentClaims($newTokenRequest);
        $cutoff = strtotime((string)User::findOne(['id' => $this->userId])->tokens_invalid_before);
        $this->assertGreaterThan($cutoff, $claims['iat']);
    }

    private function request(): Request
    {
        $_SERVER['REMOTE_ADDR'] = $this->ip;
        $request = new Request([
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $request->headers->set('User-Agent', 'PHPUnit AuthServiceTest');

        return $request;
    }

    private function signedToken(array $header, array $payload): string
    {
        $encodedHeader = JwtService::base64url(json_encode($header));
        $encodedPayload = JwtService::base64url(json_encode($payload));
        $signature = JwtService::base64url(hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            Yii::$app->params['jwtSecret'],
            true
        ));

        return $encodedHeader . '.' . $encodedPayload . '.' . $signature;
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $position = strpos($alphabet, $char);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        $secretBytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $secretBytes .= chr(bindec($chunk));
            }
        }

        $counter = (int)floor(time() / 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $secretBytes, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function loginAttemptCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM login_attempts WHERE username = :username AND ip = :ip',
            [':username' => $this->username, ':ip' => $this->ip]
        )->queryScalar();
    }

    private function activeSessionCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM sessions WHERE user_id = :user_id AND revoked_at IS NULL',
            [':user_id' => $this->userId]
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
