<?php

$db = require __DIR__ . '/db.php';

return [
    'id' => 'sso-php',
    'basePath' => dirname(__DIR__),
    'language' => 'ru-RU',
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => getenv('SSO_ENCRYPTION_KEY') ?: 'change-me-please-change-me-please-change-me',
            'parsers' => [
                'application/json' => yii\web\JsonParser::class,
            ],
        ],
        'response' => [
            'formatters' => [
                yii\web\Response::FORMAT_JSON => yii\web\JsonResponseFormatter::class,
            ],
        ],
        'db' => $db,
        'cache' => [
            'class' => yii\caching\FileCache::class,
        ],
        'log' => [
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET health/live' => 'health/live',
                'GET health/ready' => 'health/ready',
                'GET health/version' => 'health/version',
                'GET api/v1/bootstrap/status' => 'bootstrap/status',
                'POST api/v1/bootstrap' => 'bootstrap/create',
                'POST api/v1/auth/login' => 'auth/login',
                'POST api/v1/auth/logout' => 'auth/logout',
                'GET api/v1/auth/me' => 'auth/me',
                'GET api/v1/auth/sessions' => 'auth/sessions',
                'DELETE api/v1/auth/sessions' => 'auth/revoke-all-sessions',
                'DELETE api/v1/auth/sessions/<id:[0-9a-fA-F-]+>' => 'auth/revoke-session',
                'GET api/v1/oauth/clients' => 'oauth/list-clients',
                'POST api/v1/oauth/clients' => 'oauth/create-client',
                'GET api/v1/oauth/clients/<id:[0-9a-fA-F-]+>' => 'oauth/get-client',
                'PUT api/v1/oauth/clients/<id:[0-9a-fA-F-]+>' => 'oauth/update-client',
                'DELETE api/v1/oauth/clients/<id:[0-9a-fA-F-]+>' => 'oauth/delete-client',
                'POST api/v1/oauth/clients/<id:[0-9a-fA-F-]+>/rotate-secret' => 'oauth/rotate-secret',
                'GET oauth2/authorize' => 'oauth/authorize',
                'POST oauth2/token' => 'oauth/token',
                'POST oauth2/revoke' => 'oauth/revoke',
                'POST oauth2/introspect' => 'oauth/introspect',
                'GET oauth2/consent' => 'oauth/consent',
                'POST oauth2/consent' => 'oauth/grant-consent',
                'GET oauth2/logout' => 'oauth/logout',
                'POST oauth2/logout' => 'oauth/logout',
                'GET oauth2/userinfo' => 'oidc/userinfo',
                'GET .well-known/openid-configuration' => 'oidc/configuration',
                'GET .well-known/jwks.json' => 'oidc/jwks',
                '' => 'admin/index',
                '<path:.+>' => 'admin/index',
            ],
        ],
    ],
    'params' => require __DIR__ . '/params.php',
];
