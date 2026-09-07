<?php

namespace app\controllers;

use app\services\AuthService;
use Yii;

class AuthController extends ApiController
{
    public function actionLogin(): array
    {
        $result = (new AuthService())->login($this->body(), Yii::$app->request);

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
}
