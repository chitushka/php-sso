<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// В dev-сервере отдаём реальные статические файлы напрямую.
if ($path !== '/' && is_file($file)) {
    return false;
}

// Yii2 должен видеть единый entry script даже для /.well-known/* URL.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/index.php';
