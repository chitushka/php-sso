<?php

namespace app\commands;

use app\services\CryptoService;
use app\services\CutoverCheckService;
use app\services\SecretKeyRotationService;
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
        if (in_array($actionID, ['rewrap-secrets', 'rotate-encryption-key'], true)) {
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

    public function actionRotateEncryptionKey(): int
    {
        $apply = filter_var($this->apply, FILTER_VALIDATE_BOOLEAN);
        $activeKey = (string)(getenv('SSO_ENCRYPTION_KEY') ?: '');
        $oldKey = (string)(getenv('SSO_OLD_ENCRYPTION_KEY') ?: '');
        $newKey = (string)(getenv('SSO_NEW_ENCRYPTION_KEY') ?: '');

        if ($oldKey === '' || $newKey === '') {
            $this->stderr("SSO_OLD_ENCRYPTION_KEY and SSO_NEW_ENCRYPTION_KEY are required\n");
            return self::EXIT_CODE_ERROR;
        }
        if (hash_equals($oldKey, $newKey)) {
            $this->stderr("old and new encryption keys must differ\n");
            return self::EXIT_CODE_ERROR;
        }
        if (strlen($newKey) < 32 || stripos($newKey, 'change-me') !== false) {
            $this->stderr("SSO_NEW_ENCRYPTION_KEY must be at least 32 characters and not a placeholder\n");
            return self::EXIT_CODE_ERROR;
        }
        if ($apply && !hash_equals($oldKey, $activeKey)) {
            $this->stderr("SSO_OLD_ENCRYPTION_KEY must match the active SSO_ENCRYPTION_KEY\n");
            return self::EXIT_CODE_ERROR;
        }

        try {
            $result = (new SecretKeyRotationService(
                Yii::$app->db,
                new CryptoService($oldKey),
                new CryptoService($newKey)
            ))->rotate($apply);
        } catch (\Throwable $exception) {
            $this->stderr('Encryption key rotation failed: ' . $exception->getMessage() . "\n");
            return self::EXIT_CODE_ERROR;
        }

        $mode = $apply ? 'APPLIED' : 'DRY-RUN';
        foreach ($result['sources'] as $source => $counts) {
            $this->stdout(sprintf(
                "[%s] %s: %d rotate, %d already rotated\n",
                $mode,
                $source,
                $counts['rotate'],
                $counts['already_rotated']
            ));
        }
        $this->stdout(sprintf(
            "%s complete: %d secrets %s, %d already used the new key\n",
            $mode,
            $result['rotate_total'],
            $apply ? 'rotated' : 'would be rotated',
            $result['already_rotated_total']
        ));

        return self::EXIT_CODE_NORMAL;
    }

    public function actionGenerateLocalEncryptionKey(): int
    {
        if ((string)getenv('SSO_ENV') === 'prod') {
            $this->stderr("local key generation is forbidden when SSO_ENV=prod\n");
            return self::EXIT_CODE_ERROR;
        }

        $oldKey = (string)(getenv('SSO_ENCRYPTION_KEY') ?: '');
        if ($oldKey === '') {
            $this->stderr("active SSO_ENCRYPTION_KEY is required\n");
            return self::EXIT_CODE_ERROR;
        }

        $envPath = dirname(__DIR__) . '/.env';
        $recoveryPath = dirname(__DIR__) . '/runtime/cutover-new-encryption-key';
        $contents = @file_get_contents($envPath);
        if ($contents === false) {
            $this->stderr("cannot read local .env\n");
            return self::EXIT_CODE_ERROR;
        }

        $newKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $updatedContents = preg_replace(
            '/^SSO_ENCRYPTION_KEY=.*$/m',
            'SSO_ENCRYPTION_KEY=' . $newKey,
            $contents,
            1,
            $replacementCount
        );
        if ($updatedContents === null || $replacementCount !== 1) {
            $this->stderr("local .env must contain exactly one SSO_ENCRYPTION_KEY entry\n");
            return self::EXIT_CODE_ERROR;
        }

        // Recovery-файл не даёт потерять новый ключ, если запись .env оборвётся после commit БД.
        if (@file_put_contents($recoveryPath, $newKey . "\n", LOCK_EX) === false) {
            $this->stderr("cannot create runtime key recovery file\n");
            return self::EXIT_CODE_ERROR;
        }
        @chmod($recoveryPath, 0600);

        try {
            $service = new SecretKeyRotationService(
                Yii::$app->db,
                new CryptoService($oldKey),
                new CryptoService($newKey)
            );
            $dryRun = $service->rotate(false);
            $result = $service->rotate(true);
            if ($dryRun['rotate_total'] !== $result['rotate_total']) {
                throw new \RuntimeException('secret set changed between dry-run and apply');
            }
        } catch (\Throwable $exception) {
            @unlink($recoveryPath);
            $this->stderr('Local encryption key rotation failed: ' . $exception->getMessage() . "\n");
            return self::EXIT_CODE_ERROR;
        }

        if (@file_put_contents($envPath, $updatedContents, LOCK_EX) === false) {
            $this->stderr(
                "database was rotated, but .env update failed; recover the key from runtime/cutover-new-encryption-key\n"
            );
            return self::EXIT_CODE_ERROR;
        }

        $saved = @file_get_contents($envPath);
        if ($saved === false || strpos($saved, 'SSO_ENCRYPTION_KEY=' . $newKey) === false) {
            $this->stderr(
                "database was rotated, but .env verification failed; recover the key from runtime/cutover-new-encryption-key\n"
            );
            return self::EXIT_CODE_ERROR;
        }

        @unlink($recoveryPath);
        $this->stdout(sprintf(
            "Local encryption key generated: %d secrets rotated, %d already used the generated key\n",
            $result['rotate_total'],
            $result['already_rotated_total']
        ));
        $this->stdout("Restart PHP containers before using encrypted LDAP/MFA/broker data\n");

        return self::EXIT_CODE_NORMAL;
    }
}
