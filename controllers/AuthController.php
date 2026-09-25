<?php

namespace app\controllers;

use app\services\AccountService;
use app\services\AuthService;
use app\services\MfaService;
use Yii;
use yii\web\BadRequestHttpException;

class AuthController extends ApiController
{
    public function actionLogin(): array
    {
        $fields = ['username', 'password'];
        $result = (new AuthService())->login($this->body($fields, array_fill_keys($fields, 'string')), Yii::$app->request);

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
        $fields = ['mfa_token', 'code'];
        $result = (new AuthService())->verifyMfaLogin($this->body($fields, array_fill_keys($fields, 'string')), Yii::$app->request);

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

        return (new AuthService())->listSessions($user->id);
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

        if (!(new AuthService())->revokeSession($user->id, $id)) {
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

        (new AuthService())->revokeAllSessions($user);
        Yii::$app->response->cookies->remove('sso_session');
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

        (new AccountService())->forgotPassword($login);
        return ['status' => 'ok'];
    }

    public function actionResetPassword(): array
    {
        $body = $this->body(['token', 'password'], ['token' => 'string', 'password' => 'string']);
        $result = (new AccountService())->resetPassword(
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
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $result = (new AccountService())->updateProfile(
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

        $result = (new AccountService())->requestEmailVerification($user);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionVerifyEmail(): array
    {
        $result = (new AccountService())->verifyEmail(
            (string)($this->body(['token'], ['token' => 'string'])['token'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaSetup(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        if ($user->mfa_enabled) {
            return $this->fail(409, 'mfa already enabled');
        }

        return (new MfaService())->setup($user);
    }

    public function actionMfaEnable(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $body = $this->body(['code'], ['code' => 'string']);
        $result = (new MfaService())->enable(
            $user,
            '',
            (string)($body['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaDisable(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $result = (new MfaService())->disable(
            $user,
            (string)($this->body(['code'], ['code' => 'string'])['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionMfaRecoveryCodes(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        $result = (new MfaService())->regenerateRecoveryCodes(
            $user,
            (string)($this->body(['code'], ['code' => 'string'])['code'] ?? '')
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
