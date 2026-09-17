<?php

namespace app\controllers;

use app\services\AccountService;
use app\services\AuthService;
use app\services\MfaService;
use Yii;

class AuthController extends ApiController
{
    public function actionLogin(): array
    {
        $result = (new AuthService())->login($this->body(), Yii::$app->request);

        if (!$result['ok']) {
            return $this->fail($result['status'], $result['error']);
        }

        if (isset($result['cookie'])) {
            Yii::$app->response->cookies->add($result['cookie']);
        }
        return $result['data'];
    }

    public function actionVerifyMfa(): array
    {
        $result = (new AuthService())->verifyMfaLogin($this->body(), Yii::$app->request);

        if (!$result['ok']) {
            return $this->fail($result['status'], $result['error']);
        }

        Yii::$app->response->cookies->add($result['cookie']);
        return $result['data'];
    }

    public function actionLogout(): array
    {
        $token = Yii::$app->request->cookies->getValue('sso_session', '');
        (new AuthService())->logout($token);
        Yii::$app->response->cookies->remove('sso_session');
        return ['status' => 'logged_out'];
    }

    public function actionMe(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        return $user->publicData();
    }

    public function actionSessions(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        return (new AuthService())->listSessions($user->id);
    }

    public function actionRevokeSession(string $id): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid session id');
        }

        if (!(new AuthService())->revokeSession($user->id, $id)) {
            return $this->fail(404, 'session not found');
        }

        return ['status' => 'revoked'];
    }

    public function actionRevokeAllSessions(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        (new AuthService())->revokeAllSessions($user);
        Yii::$app->response->cookies->remove('sso_session');
        return ['status' => 'all_revoked'];
    }

    public function actionForgotPassword(): array
    {
        $login = trim((string)($this->body()['login'] ?? ''));
        if ($login === '') {
            return $this->fail(400, 'login is required');
        }

        (new AccountService())->forgotPassword($login);
        return ['status' => 'ok'];
    }

    public function actionResetPassword(): array
    {
        $body = $this->body();
        $result = (new AccountService())->resetPassword(
            (string)($body['token'] ?? ''),
            (string)($body['password'] ?? '')
        );

        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionChangePassword(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $body = $this->body();
        $result = (new AccountService())->changePassword(
            $user,
            (string)($body['old_password'] ?? ''),
            (string)($body['new_password'] ?? '')
        );

        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdateProfile(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $result = (new AccountService())->updateProfile($user, $this->body());
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionRequestEmailVerification(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $result = (new AccountService())->requestEmailVerification($user);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionVerifyEmail(): array
    {
        $result = (new AccountService())->verifyEmail((string)($this->body()['token'] ?? ''));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaSetup(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        if ($user->mfa_enabled) {
            return $this->fail(409, 'mfa already enabled');
        }

        return (new MfaService())->setup($user);
    }

    public function actionMfaEnable(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $body = $this->body();
        $result = (new MfaService())->enable(
            $user,
            (string)($body['secret'] ?? ''),
            (string)($body['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaDisable(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $result = (new MfaService())->disable($user, (string)($this->body()['code'] ?? ''));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaRecoveryCodes(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $result = (new MfaService())->regenerateRecoveryCodes($user, (string)($this->body()['code'] ?? ''));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
