<?php

$db = require __DIR__ . '/db.php';
$params = require __DIR__ . '/params.php';

return [
    'id' => 'sso-php',
    'basePath' => dirname(__DIR__),
    'language' => 'ru-RU',
    'bootstrap' => ['log'],
    'on beforeRequest' => static function (): void {
        app\components\CorrelationId::begin(Yii::$app->request);
        Yii::$app->params['_cspNonce'] = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        if (app\components\MaintenanceMode::apply(Yii::$app->request, Yii::$app->response)) {
            Yii::$app->end(0, Yii::$app->response);
        }
        (new app\services\MetricsService())->begin();
    },
    'on afterRequest' => static function (): void {
        if (app\components\MaintenanceMode::blockedCurrentRequest()) {
            return;
        }
        (new app\services\MetricsService())->end(Yii::$app->request, Yii::$app->response);
    },
    'controllerMap' => [
        'oauth' => [
            'class' => app\controllers\OAuthController::class,
        ],
        'rbac' => [
            'class' => app\controllers\RbacController::class,
        ],
        'ldap' => [
            'class' => app\controllers\LdapController::class,
        ],
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'class' => app\components\TrustedRequest::class,
            'enableCookieValidation' => false,
            'cookieValidationKey' => $params['encryptionKey'],
            'parsers' => [
                'application/json' => app\components\JsonParser::class,
            ],
        ],
        'response' => [
            'on beforeSend' => static function ($event): void {
                $nonce = (string)(Yii::$app->params['_cspNonce'] ?? '');
                $event->sender->headers->set(
                    'Content-Security-Policy',
                    "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; "
                    . "img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; "
                    . "base-uri 'self'; frame-ancestors 'none'; form-action 'self'"
                );
                $event->sender->headers->set('X-Content-Type-Options', 'nosniff');
                $event->sender->headers->set(
                    'X-Request-ID',
                    (string)(Yii::$app->params['_requestId'] ?? '')
                );
                $event->sender->headers->set('Referrer-Policy', 'same-origin');
                $event->sender->headers->set(
                    'Permissions-Policy',
                    'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
                );
                if (
                    strtolower((string)getenv('SSO_ENV')) === 'prod'
                    && Yii::$app->request->isSecureConnection
                ) {
                    $event->sender->headers->set(
                        'Strict-Transport-Security',
                        'max-age=31536000; includeSubDomains'
                    );
                }
                $path = '/' . ltrim((string)Yii::$app->request->pathInfo, '/');
                if (
                    strpos($path, '/api/v1/auth/') === 0
                    || in_array($path, [
                        '/oauth2/authorize',
                        '/oauth2/token',
                        '/oauth2/revoke',
                        '/oauth2/introspect',
                        '/oauth2/consent',
                        '/oauth2/logout',
                    ], true)
                ) {
                    $event->sender->headers->set('Cache-Control', 'no-store');
                    $event->sender->headers->set('Pragma', 'no-cache');
                }
                $exception = Yii::$app->errorHandler->exception;
                if (
                    $exception instanceof yii\web\BadRequestHttpException
                    && $exception->getMessage() === 'invalid json body'
                ) {
                    $event->sender->format = yii\web\Response::FORMAT_JSON;
                    $event->sender->data = ['error' => 'invalid json body'];
                }
            },
            'formatters' => [
                yii\web\Response::FORMAT_JSON => yii\web\JsonResponseFormatter::class,
            ],
        ],
        'db' => $db,
        'cache' => ($params['cacheDriver'] === 'db')
            ? [
                'class' => yii\caching\DbCache::class,
                'db' => 'db',
                'cacheTable' => 'cache',
            ]
            : [
                'class' => yii\caching\FileCache::class,
            ],
        'log' => [
            'targets' => [
                [
                    'class' => app\components\JsonLogTarget::class,
                    'levels' => ['error', 'warning', 'info'],
                    'except' => ['yii\\db\\Command*', 'yii\\db\\Connection*'],
                    'logVars' => [],
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
                'GET metrics' => 'metrics/index',
                'GET api/v1/bootstrap/status' => 'bootstrap/status',
                'POST api/v1/bootstrap' => 'bootstrap/create',
                'GET api/v1/broker/providers' => 'broker/public-providers',
                'POST api/v1/auth/login' => 'auth/login',
                'POST api/v1/auth/password/forgot' => 'auth/forgot-password',
                'POST api/v1/auth/password/reset' => 'auth/reset-password',
                'POST api/v1/auth/password/change' => 'auth/change-password',
                'PUT api/v1/auth/profile' => 'auth/update-profile',
                'POST api/v1/auth/email/request' => 'auth/request-email-verification',
                'POST api/v1/auth/email/verify' => 'auth/verify-email',
                'POST api/v1/auth/mfa/verify' => 'auth/verify-mfa',
                'POST api/v1/auth/mfa/enroll' => 'auth/mfa-setup',
                'POST api/v1/auth/mfa/activate' => 'auth/mfa-enable',
                'POST api/v1/auth/mfa/setup' => 'auth/mfa-setup',
                'POST api/v1/auth/mfa/enable' => 'auth/mfa-enable',
                'POST api/v1/auth/mfa/disable' => 'auth/mfa-disable',
                'POST api/v1/auth/mfa/recovery-codes' => 'auth/mfa-recovery-codes',
                'POST api/v1/auth/logout' => 'auth/logout',
                'POST api/v1/auth/session-token' => 'auth/session-token',
                'GET api/v1/auth/me' => 'auth/me',
                'GET api/v1/auth/sessions' => 'auth/sessions',
                'DELETE api/v1/auth/sessions' => 'auth/revoke-all-sessions',
                'DELETE api/v1/auth/sessions/<id:[^/]+>' => 'auth/revoke-session',
                'GET api/v1/users/<userID:[^/]+>/roles' => 'rbac/list-user-roles',
                'POST api/v1/users/<userID:[^/]+>/roles' => 'rbac/assign-user-role',
                'DELETE api/v1/users/<userID:[^/]+>/roles/<roleID:[^/]+>' => 'rbac/remove-user-role',
                'GET api/v1/users/<userID:[^/]+>/groups' => 'groups/list-user-groups',
                'POST api/v1/users/<userID:[^/]+>/groups' => 'groups/assign-user-group',
                'DELETE api/v1/users/<userID:[^/]+>/groups/<groupID:[^/]+>' => 'groups/remove-user-group',
                'GET api/v1/users' => 'users/index',
                'POST api/v1/users' => 'users/create',
                'GET api/v1/users/<id:[^/]+>' => 'users/view',
                'PUT api/v1/users/<id:[^/]+>' => 'users/update',
                'DELETE api/v1/users/<id:[^/]+>' => 'users/delete',
                'GET api/v1/roles' => 'rbac/list-roles',
                'POST api/v1/roles' => 'rbac/create-role',
                'PUT api/v1/roles/<roleID:[^/]+>' => 'rbac/update-role',
                'DELETE api/v1/roles/<roleID:[^/]+>' => 'rbac/delete-role',
                'GET api/v1/roles/<roleID:[^/]+>/permissions' => 'rbac/list-role-permissions',
                'POST api/v1/roles/<roleID:[^/]+>/permissions' => 'rbac/assign-permission',
                'DELETE api/v1/roles/<roleID:[^/]+>/permissions/<permissionID:[^/]+>' => 'rbac/remove-permission',
                'GET api/v1/permissions' => 'rbac/list-permissions',
                'GET api/v1/groups' => 'groups/index',
                'POST api/v1/groups' => 'groups/create',
                'PUT api/v1/groups/<groupID:[^/]+>' => 'groups/update',
                'DELETE api/v1/groups/<groupID:[^/]+>' => 'groups/delete',
                'GET api/v1/groups/<groupID:[^/]+>/roles' => 'groups/list-roles',
                'POST api/v1/groups/<groupID:[^/]+>/roles' => 'groups/assign-role',
                'DELETE api/v1/groups/<groupID:[^/]+>/roles/<roleID:[^/]+>' => 'groups/remove-role',
                'GET api/v1/audit' => 'audit/index',
                'GET api/v1/ldap/providers' => 'ldap/list-providers',
                'POST api/v1/ldap/providers' => 'ldap/create-provider',
                'PUT api/v1/ldap/providers/<id:[^/]+>' => 'ldap/update-provider',
                'DELETE api/v1/ldap/providers/<id:[^/]+>' => 'ldap/delete-provider',
                'POST api/v1/ldap/providers/test' => 'ldap/test',
                'GET api/v1/ldap/providers/<id:[^/]+>/group-mappings' => 'ldap/list-group-mappings',
                'POST api/v1/ldap/providers/<id:[^/]+>/group-mappings' => 'ldap/create-group-mapping',
                'DELETE api/v1/ldap/providers/<id:[^/]+>/group-mappings/<mappingID:[^/]+>' => 'ldap/delete-group-mapping',
                'GET api/v1/identity-providers' => 'broker/list-providers',
                'POST api/v1/identity-providers' => 'broker/create-provider',
                'PUT api/v1/identity-providers/<id:[^/]+>' => 'broker/update-provider',
                'DELETE api/v1/identity-providers/<id:[^/]+>' => 'broker/delete-provider',
                'GET api/v1/oauth/clients' => 'oauth/list-clients',
                'POST api/v1/oauth/clients' => 'oauth/create-client',
                'GET api/v1/oauth/clients/<id:[^/]+>' => 'oauth/get-client',
                'PUT api/v1/oauth/clients/<id:[^/]+>' => 'oauth/update-client',
                'DELETE api/v1/oauth/clients/<id:[^/]+>' => 'oauth/delete-client',
                'POST api/v1/oauth/clients/<id:[^/]+>/rotate-secret' => 'oauth/rotate-secret',
                'GET oauth2/authorize' => 'oauth/authorize',
                'POST oauth2/token' => 'oauth/token',
                'POST oauth2/revoke' => 'oauth/revoke',
                'POST oauth2/introspect' => 'oauth/introspect',
                'GET oauth2/consent' => 'oauth/consent',
                'POST oauth2/consent' => 'oauth/grant-consent',
                'GET api/v1/auth/consents' => 'oauth/list-user-consents',
                'DELETE api/v1/auth/consents/<clientId:[^/]+>' => 'oauth/revoke-user-consent',
                'GET oauth2/logout' => 'oauth/logout',
                'POST oauth2/logout' => 'oauth/logout',
                'GET oauth2/broker/<providerCode:[A-Za-z0-9_.-]+>/login' => 'broker/login',
                'GET oauth2/broker/<providerCode:[A-Za-z0-9_.-]+>/callback' => 'broker/callback',
                'GET oauth2/userinfo' => 'oidc/userinfo',
                'GET .well-known/openid-configuration' => 'oidc/configuration',
                'GET .well-known/jwks.json' => 'oidc/jwks',
                'api' => 'api/not-found',
                'api/<path:.*>' => 'api/not-found',
                'oauth2/<path:.*>' => 'api/not-found',
                '.well-known/<path:.*>' => 'api/not-found',
                '' => 'admin/index',
                'GET <path:.+>' => 'admin/index',
            ],
        ],
    ],
    'params' => $params,
];
