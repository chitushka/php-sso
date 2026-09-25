<?php

namespace app\commands;

use app\services\CryptoService;
use app\services\CutoverCheckService;
use Yii;
use yii\console\Controller;

class CutoverController extends Controller
{
    public function actionCheck(): int
    {
        $names = [
            'SSO_ENV',
            'SSO_DATABASE_URL',
            'SSO_ISSUER',
            'SSO_CUTOVER_EXPECTED_ISSUER',
            'SSO_JWT_SECRET',
            'SSO_ENCRYPTION_KEY',
            'SSO_ACCESS_TOKEN_TTL',
            'SSO_SESSION_TTL',
            'SSO_REFRESH_TOKEN_TTL',
            'SSO_COOKIE_SECURE',
            'SSO_CACHE_DRIVER',
            'SSO_TRUSTED_PROXIES',
        ];
        $environment = [];
        foreach ($names as $name) {
            $environment[$name] = getenv($name) === false ? '' : (string)getenv($name);
        }

        $service = new CutoverCheckService(Yii::$app->db, $environment, new CryptoService());
        $results = $service->check();
        $counts = [CutoverCheckService::PASS => 0, CutoverCheckService::WARN => 0, CutoverCheckService::FAIL => 0];

        foreach ($results as $result) {
            $status = (string)$result['status'];
            $counts[$status]++;
            $this->stdout(sprintf("[%s] %s: %s\n", $status, $result['check'], $result['message']));
        }

        $this->stdout(sprintf(
            "Cutover readiness: %d passed, %d warnings, %d failures\n",
            $counts[CutoverCheckService::PASS],
            $counts[CutoverCheckService::WARN],
            $counts[CutoverCheckService::FAIL]
        ));

        return CutoverCheckService::hasFailures($results)
            ? self::EXIT_CODE_ERROR
            : self::EXIT_CODE_NORMAL;
    }
}
