<?php

use app\services\AuthService;
use yii\console\Application;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

new Application(require dirname(__DIR__, 2) . '/config/console.php');
Yii::$app->params = array_merge(require dirname(__DIR__, 2) . '/config/params.php', Yii::$app->params);

(new AuthService())->registerLoginFailure((string)($argv[1] ?? ''), (string)($argv[2] ?? ''));
