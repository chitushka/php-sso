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
        $auth = new AuthService();
        $claims = $auth->currentClaims(Yii::$app->request);
        $user = $auth->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        $scope = (string)($claims['scope'] ?? '');
        $full = $scope === '';
        $scopes = array_filter(preg_split('/\s+/', trim($scope)));
        $data = [
            'sub' => $user->id,
        ];

        if ($full || in_array('profile', $scopes, true)) {
            $data['preferred_username'] = $user->username;
            $data['source'] = $user->source;
            if ($full) {
                $data['name'] = trim($user->first_name . ' ' . $user->last_name);
            }
        }

        if ($full || in_array('email', $scopes, true)) {
            $data['email'] = $user->email;
            if ($full) {
                $data['email_verified'] = (bool)$user->email_verified;
            }
        }

        return $data;
    }
}
