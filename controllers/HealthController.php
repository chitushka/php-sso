<?php

namespace app\controllers;

use Throwable;
use Yii;
use app\services\CryptoService;
use app\services\OidcService;

class HealthController extends ApiController
{
    public function actionLive(): array
    {
        return ['status' => 'ok'];
    }

    public function actionReady(): array
    {
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        } catch (Throwable $exception) {
            return $this->fail(503, 'database unavailable');
        }

        if ((bool)(Yii::$app->params['readinessCryptoChecks'] ?? false)) {
            try {
                $probe = random_bytes(32);
                $crypto = new CryptoService();
                if (!hash_equals($probe, $crypto->decrypt($crypto->encrypt($probe)))) {
                    return $this->fail(503, 'cryptography unavailable');
                }
            } catch (Throwable $exception) {
                return $this->fail(503, 'cryptography unavailable');
            }
            if (!(new OidcService())->signingReady()) {
                return $this->fail(503, 'signing unavailable');
            }
        }

        return ['status' => 'ready'];
    }

    public function actionVersion(): array
    {
        return ['version' => Yii::$app->params['version']];
    }
}
