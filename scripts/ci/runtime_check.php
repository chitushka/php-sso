<?php

require '/app/vendor/autoload.php';
require '/app/vendor/yiisoft/yii2/Yii.php';

foreach (['json', 'ldap', 'mbstring', 'openssl', 'pdo_pgsql'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Missing PHP extension: ' . $extension);
    }
}
if (!in_array('argon2id', password_algos(), true)) {
    throw new RuntimeException('Argon2id is unavailable');
}
foreach ([
    app\components\Environment::class,
    app\services\ApplicationRuntime::class,
    app\controllers\OperationsController::class,
    app\controllers\FederatedIdentitiesController::class,
    app\services\results\ServiceResult::class,
    app\services\results\ServiceError::class,
] as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Application class cannot be autoloaded: ' . $class);
    }
}
if (is_dir('/app/tests') || is_file('/app/composer.json') || is_file('/app/vendor/bin/phpunit')) {
    throw new RuntimeException('Unexpected production image contents');
}

echo 'Production runtime PHP ' . PHP_VERSION . " is ready\n";
