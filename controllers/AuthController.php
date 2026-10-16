<?php

namespace app\controllers;

use app\services\AccountService;
use app\services\AuthService;
use app\services\MfaService;
use app\services\MetricsService;
use Yii;
use yii\base\Module;
use yii\web\BadRequestHttpException;

class AuthController extends ApiController
{
    private AuthService $auth;
    private AccountService $accounts;
    private MfaService $mfa;
    private MetricsService $metrics;

    public function __construct(
        string $id,
        Module $module,
        AuthService $auth,
        AccountService $accounts,
        MfaService $mfa,
        MetricsService $metrics,
        array $config = []
    ) {
        $this->auth = $auth;
        $this->accounts = $accounts;
        $this->mfa = $mfa;
        $this->metrics = $metrics;
        parent::__construct($id, $module, $config);
    }

    public function actionLogin(): array
    {
        $fields = ['username', 'password'];
        $result = $this->auth->login($this->body($fields, array_fill_keys($fields, 'string')), Yii::$app->request);

        $this->metrics->event(
            'auth',
            !$result->isSuccess() ? 'failure' : (isset($result->metadata['cookie']) ? 'success' : 'mfa_required')
        );

        if (!$result->isSuccess()) {
            return $this->fail($result->status, $result->error->message);
        }

        if (isset($result->metadata['cookie'])) {
            Yii::$app->response->cookies->add($result->metadata['cookie']);
            Yii::$app->response->cookies->add($result->metadata['csrf_cookie']);
        }
        return $result->data;
    }

    public function actionVerifyMfa(): array
    {
        $fields = ['mfa_token', 'code'];
        $result = $this->auth->verifyMfaLogin($this->body($fields, array_fill_keys($fields, 'string')), Yii::$app->request);

        $this->metrics->event('auth', $result->isSuccess() ? 'success' : 'failure');

        if (!$result->isSuccess()) {
            return $this->fail($result->status, $result->error->message);
        }

        Yii::$app->response->cookies->add($result->metadata['cookie']);
        Yii::$app->response->cookies->add($result->metadata['csrf_cookie']);
        return $result->data;
    }

    public function actionLogout(): array
    {
        $token = Yii::$app->request->cookies->getValue('sso_session', '');
        $auth = $this->auth;
        $auth->logout($token);
        $auth->revokeAccessToken(Yii::$app->request);
        Yii::$app->response->cookies->remove('sso_session');
        Yii::$app->response->cookies->remove(\app\services\BrowserSecurityService::CSRF_COOKIE);
        return ['status' => 'logged_out'];
    }

    public function actionSessionToken(): array
    {
        $auth = $this->auth;
        $authentication = $auth->sessionAuthentication(
            (string)Yii::$app->request->cookies->getValue('sso_session', '')
        );
        if (!$authentication) {
            return $this->fail(401, 'invalid session');
        }

        return [
            'access_token' => $auth->issueAccessToken(
                $authentication['user'],
                null,
                (int)$authentication['auth_time']
            ),
            'user' => $authentication['user']->publicData(),
        ];
    }

    public function actionMe(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        return $user->publicData();
    }

    public function actionSessions(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        return $this->auth->listSessions($user->id);
    }

    public function actionRevokeSession(string $id): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid session id');
        }
        $id = $this->canonicalUuid($id);

        if (!$this->auth->revokeSession($user->id, $id)) {
            return $this->fail(404, 'session not found');
        }

        return ['status' => 'revoked'];
    }

    public function actionRevokeAllSessions(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $this->auth->revokeAllSessions($user);
        Yii::$app->response->cookies->remove('sso_session');
        Yii::$app->response->cookies->remove(\app\services\BrowserSecurityService::CSRF_COOKIE);
        return ['status' => 'all_revoked'];
    }

    public function actionForgotPassword(): array
    {
        try {
            $login = trim((string)($this->body(['login'], ['login' => 'string'])['login'] ?? ''));
        } catch (BadRequestHttpException $exception) {
            return $this->fail(400, 'login is required');
        }
        if ($login === '') {
            return $this->fail(400, 'login is required');
        }

        $this->accounts->forgotPassword($login);
        return ['status' => 'ok'];
    }

    public function actionResetPassword(): array
    {
        $body = $this->body(['token', 'password'], ['token' => 'string', 'password' => 'string']);
        $result = $this->accounts->resetPassword(
            (string)($body['token'] ?? ''),
            (string)($body['password'] ?? '')
        );

        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionChangePassword(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $body = $this->body(
            ['old_password', 'new_password'],
            ['old_password' => 'string', 'new_password' => 'string']
        );
        $result = $this->accounts->changePassword(
            $user,
            (string)($body['old_password'] ?? ''),
            (string)($body['new_password'] ?? '')
        );

        if ($result['status'] === 200) {
            Yii::$app->response->cookies->remove('sso_session');
        }

        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdateProfile(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $result = $this->accounts->updateProfile(
            $user,
            $this->body(
                ['first_name', 'last_name', 'attributes'],
                ['first_name' => 'string', 'last_name' => 'string', 'attributes' => 'string_map']
            )
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionRequestEmailVerification(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $result = $this->accounts->requestEmailVerification($user);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionVerifyEmail(): array
    {
        $result = $this->accounts->verifyEmail(
            (string)($this->body(['token'], ['token' => 'string'])['token'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaSetup(): array
    {
        $user = $this->requireRecentUser();
        if (is_array($user)) {
            return $user;
        }

        if ($user->mfa_enabled) {
            return $this->fail(409, 'mfa already enabled');
        }

        return $this->mfa->setup($user);
    }

    public function actionMfaEnable(): array
    {
        $user = $this->requireRecentUser();
        if (is_array($user)) {
            return $user;
        }

        $body = $this->body(['code'], ['code' => 'string']);
        $result = $this->mfa->enable(
            $user,
            '',
            (string)($body['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        if ($result['status'] === 200) {
            Yii::$app->response->cookies->remove('sso_session');
            Yii::$app->response->cookies->remove(\app\services\BrowserSecurityService::CSRF_COOKIE);
        }
        return $result['data'];
    }

    public function actionMfaDisable(): array
    {
        $user = $this->requireRecentUser();
        if (is_array($user)) {
            return $user;
        }

        $result = $this->mfa->disable(
            $user,
            (string)($this->body(['code'], ['code' => 'string'])['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        if ($result['status'] === 200) {
            Yii::$app->response->cookies->remove('sso_session');
            Yii::$app->response->cookies->remove(\app\services\BrowserSecurityService::CSRF_COOKIE);
        }
        return $result['data'];
    }

    public function actionMfaRecoveryCodes(): array
    {
        $user = $this->requireRecentUser();
        if (is_array($user)) {
            return $user;
        }

        $result = $this->mfa->regenerateRecoveryCodes(
            $user,
            (string)($this->body(['code'], ['code' => 'string'])['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
