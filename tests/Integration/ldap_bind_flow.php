<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

new yii\console\Application(require __DIR__ . '/../../config/console.php');

use app\services\LdapService;

$required = [
    'SSO_TEST_LDAP_HOST',
    'SSO_TEST_LDAP_BIND_DN',
    'SSO_TEST_LDAP_BIND_PASSWORD',
    'SSO_TEST_LDAP_BASE_DN',
    'SSO_TEST_LDAP_LOGIN',
    'SSO_TEST_LDAP_PASSWORD',
];

foreach ($required as $name) {
    if ((string)getenv($name) === '') {
        echo "LDAP bind flow skipped: {$name} is not set\n";
        exit(0);
    }
}

$db = Yii::$app->db;
$suffix = strtolower(bin2hex(random_bytes(4)));
$providerId = null;
$groupId = null;
$createdUserId = null;

function failTest(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function envBool(string $name): bool
{
    return filter_var(getenv($name) ?: false, FILTER_VALIDATE_BOOLEAN);
}

$service = new LdapService();

try {
    $provider = $service->createProvider([
        'name' => 'Integration LDAP ' . $suffix,
        'host' => (string)getenv('SSO_TEST_LDAP_HOST'),
        'port' => (int)(getenv('SSO_TEST_LDAP_PORT') ?: 389),
        'use_tls' => envBool('SSO_TEST_LDAP_USE_TLS'),
        'start_tls' => envBool('SSO_TEST_LDAP_START_TLS'),
        'bind_dn' => (string)getenv('SSO_TEST_LDAP_BIND_DN'),
        'bind_password' => (string)getenv('SSO_TEST_LDAP_BIND_PASSWORD'),
        'base_dn' => (string)getenv('SSO_TEST_LDAP_BASE_DN'),
        'user_filter' => getenv('SSO_TEST_LDAP_USER_FILTER') ?: '(uid={login})',
        'username_attribute' => getenv('SSO_TEST_LDAP_USERNAME_ATTRIBUTE') ?: 'uid',
        'email_attribute' => getenv('SSO_TEST_LDAP_EMAIL_ATTRIBUTE') ?: 'mail',
        'display_name_attribute' => getenv('SSO_TEST_LDAP_DISPLAY_NAME_ATTRIBUTE') ?: 'cn',
        'group_attribute' => getenv('SSO_TEST_LDAP_GROUP_ATTRIBUTE') ?: 'memberOf',
        'enabled' => true,
    ], null);
    assertTrue($provider['status'] === 201, 'LDAP provider must be created');
    $providerId = $provider['data']['id'];
    assertTrue(!array_key_exists('bind_password', $provider['data']), 'LDAP provider response must hide bind_password');

    $test = $service->test([
        'host' => (string)getenv('SSO_TEST_LDAP_HOST'),
        'port' => (int)(getenv('SSO_TEST_LDAP_PORT') ?: 389),
        'use_tls' => envBool('SSO_TEST_LDAP_USE_TLS'),
        'start_tls' => envBool('SSO_TEST_LDAP_START_TLS'),
        'bind_dn' => (string)getenv('SSO_TEST_LDAP_BIND_DN'),
        'bind_password' => (string)getenv('SSO_TEST_LDAP_BIND_PASSWORD'),
    ]);
    assertTrue(($test['ok'] ?? false) === true, 'LDAP service bind must succeed: ' . (string)($test['error'] ?? ''));

    $ldapGroup = (string)getenv('SSO_TEST_LDAP_GROUP_DN');
    if ($ldapGroup !== '') {
        $groupId = $db->createCommand(
            "INSERT INTO groups(code,name,description) VALUES (:code,'Integration LDAP Group','') RETURNING id",
            [':code' => 'integration_ldap_' . $suffix]
        )->queryScalar();

        $mapping = $service->createGroupMapping($providerId, [
            'ldap_group' => $ldapGroup,
            'group_id' => $groupId,
        ], null);
        assertTrue($mapping['status'] === 201, 'LDAP group mapping must be created');
    }

    $user = $service->authenticate(
        (string)getenv('SSO_TEST_LDAP_LOGIN'),
        (string)getenv('SSO_TEST_LDAP_PASSWORD')
    );
    assertTrue($user !== null, 'LDAP search + user bind must authenticate test user');
    $createdUserId = $user->id;
    assertTrue($user->source === 'ldap', 'LDAP user must have ldap source');
    assertTrue($user->ldap_provider_id === $providerId, 'LDAP user must reference test provider');

    if ($groupId !== null) {
        $groupLinked = (int)$db->createCommand(
            "SELECT COUNT(*) FROM user_groups WHERE user_id = :user_id AND group_id = :group_id AND source = 'ldap'",
            [':user_id' => $createdUserId, ':group_id' => $groupId]
        )->queryScalar();
        assertTrue($groupLinked === 1, 'LDAP mapped group must be linked to user');
    }

    $audit = (int)$db->createCommand(
        "SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :user_id AND action = 'ldap_login_success'",
        [':user_id' => $createdUserId]
    )->queryScalar();
    assertTrue($audit === 1, 'LDAP login success must be audited');

    echo "LDAP bind flow passed\n";
} finally {
    if ($createdUserId !== null) {
        $db->createCommand()->delete('user_groups', ['user_id' => $createdUserId, 'source' => 'ldap'])->execute();
        $db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $createdUserId], ['target_id' => $createdUserId]])->execute();
        $db->createCommand()->delete('users', ['id' => $createdUserId, 'ldap_provider_id' => $providerId])->execute();
    }
    if ($providerId !== null) {
        $db->createCommand()->delete('ldap_group_mappings', ['provider_id' => $providerId])->execute();
        $db->createCommand()->delete('audit_logs', ['target_type' => 'ldap_provider', 'target_id' => $providerId])->execute();
        $db->createCommand()->delete('ldap_providers', ['id' => $providerId])->execute();
    }
    if ($groupId !== null) {
        $db->createCommand()->delete('groups', ['id' => $groupId])->execute();
    }
}
