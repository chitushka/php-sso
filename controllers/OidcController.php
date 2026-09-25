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
        $authentication = $auth->authenticate(Yii::$app->request);
        if (!$authentication['user']) {
            return $this->fail(401, $authentication['error']);
        }
        $claims = $auth->currentClaims(Yii::$app->request);
        $user = $authentication['user'];

        $scope = (string)($claims['scope'] ?? '');
        $full = $scope === '';
        $scopes = array_filter(preg_split('/\s+/', trim($scope)));
        $data = [
            'sub' => $user->id,
        ];

        if ($full || in_array('profile', $scopes, true)) {
            $data['preferred_username'] = $user->username;
            $data['source'] = $user->source;
        }

        if ($full || in_array('email', $scopes, true)) {
            $data['email'] = $user->email;
        }

        return $data;
    }
}
