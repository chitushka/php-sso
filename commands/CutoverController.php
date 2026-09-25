<?php

namespace app\commands;

use app\services\CryptoService;
use app\services\CutoverCheckService;
use app\services\SecretRewrapService;
use Yii;
use yii\console\Controller;

class CutoverController extends Controller
{
    /** @var bool|string */
    public $apply = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'rewrap-secrets') {
            $options[] = 'apply';
        }

        return $options;
    }

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

    public function actionRewrapSecrets(): int
    {
        $apply = filter_var($this->apply, FILTER_VALIDATE_BOOLEAN);
        $key = (string)(getenv('SSO_ENCRYPTION_KEY') ?: '');
        $insecureKey = strlen($key) < 32 || stripos($key, 'change-me') !== false;
        if ($apply && $insecureKey) {
            $this->stderr("SSO_ENCRYPTION_KEY must be at least 32 characters and not a placeholder\n");
            return self::EXIT_CODE_ERROR;
        }
        if ($insecureKey) {
            $this->stdout("[WARN] dry-run uses an insecure SSO_ENCRYPTION_KEY; apply mode would refuse it\n");
        }

        try {
            $result = (new SecretRewrapService(Yii::$app->db, new CryptoService()))->rewrap($apply);
        } catch (\Throwable $exception) {
            $this->stderr('Secret rewrap failed: ' . $exception->getMessage() . "\n");
            return self::EXIT_CODE_ERROR;
        }

        $mode = $apply ? 'APPLIED' : 'DRY-RUN';
        foreach ($result['sources'] as $source => $count) {
            $this->stdout(sprintf("[%s] %s: %d legacy secrets\n", $mode, $source, $count));
        }
        $this->stdout(sprintf(
            "%s complete: %d secrets %s\n",
            $mode,
            $result['total'],
            $apply ? 'rewrapped' : 'would be rewrapped'
        ));

        return self::EXIT_CODE_NORMAL;
    }
}
