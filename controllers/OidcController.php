<?php

namespace app\controllers;

use app\services\AuthService;
use app\services\OidcService;
use Yii;

class OidcController extends ApiController
{
    public function actionConfiguration(): array
    {
        return (new OidcService())->configuration();
    }

    public function actionJwks(): array
    {
        return (new OidcService())->jwks();
    }

    public function actionUserinfo(): array
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        return [
            'sub' => $user->id,
            'preferred_username' => $user->username,
            'email' => $user->email,
            'email_verified' => (bool)$user->email_verified,
            'name' => trim($user->first_name . ' ' . $user->last_name),
            'source' => $user->source,
        ];
    }
}
