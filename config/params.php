<?php

use app\components\ConfigValidator;
use app\components\Environment;

$environment = strtolower((string)Environment::value('SSO_ENV', 'development'));
ConfigValidator::oneOf('SSO_ENV', $environment, ['development', 'test', 'prod']);
$production = $environment === 'prod';
$issuer = ConfigValidator::url(
    'SSO_ISSUER',
    (string)Environment::value('SSO_ISSUER', 'http://localhost:8080'),
    $production
);
$audience = ConfigValidator::url(
    'SSO_API_AUDIENCE',
    (string)Environment::value('SSO_API_AUDIENCE', $issuer . '/api'),
    $production
);
$jwtSecret = (string)Environment::value(
    'SSO_JWT_SECRET',
    'change-me-please-change-me-please-change-me'
);
$encryptionKey = (string)Environment::value(
    'SSO_ENCRYPTION_KEY',
    'change-me-please-change-me-please-change-me'
);
$cacheDriver = ConfigValidator::oneOf(
    'SSO_CACHE_DRIVER',
    strtolower((string)Environment::value('SSO_CACHE_DRIVER', 'file')),
    ['file', 'db']
);
$metricsAllowedCidrs = ConfigValidator::cidrList('SSO_METRICS_ALLOWED_CIDRS', (string)Environment::value(
    'SSO_METRICS_ALLOWED_CIDRS',
    $production ? '' : '127.0.0.1/32,::1/128'
));
$metricsBearerToken = (string)Environment::value('SSO_METRICS_BEARER_TOKEN', '');
$ldapCaCertificate = ConfigValidator::readableFile(
    'SSO_LDAP_CA_CERT',
    (string)Environment::value('SSO_LDAP_CA_CERT', '')
);
$smtpCaCertificate = ConfigValidator::readableFile(
    'SSO_SMTP_CA_CERT',
    (string)Environment::value('SSO_SMTP_CA_CERT', '')
);
$smtpUsername = (string)Environment::value('SSO_SMTP_USERNAME', '');
$smtpStartTls = Environment::bool('SSO_SMTP_STARTTLS', false);

if ($production) {
    foreach (['SSO_JWT_SECRET' => $jwtSecret, 'SSO_ENCRYPTION_KEY' => $encryptionKey] as $name => $value) {
        if (strlen($value) < 32 || stripos($value, 'change-me') !== false) {
            throw new RuntimeException($name . ' must be a production secret');
        }
    }
    if (!Environment::value('SSO_DATABASE_URL')) {
        throw new RuntimeException('SSO_DATABASE_URL is required in production');
    }
    if (!Environment::bool('SSO_COOKIE_SECURE', true)) {
        throw new RuntimeException('SSO_COOKIE_SECURE must be enabled in production');
    }
    if ($metricsAllowedCidrs === '' && strlen($metricsBearerToken) < 32) {
        throw new RuntimeException('production metrics endpoint requires CIDR allowlist or bearer token');
    }
    if ($smtpUsername !== '' && !$smtpStartTls) {
        throw new RuntimeException('SSO_SMTP_STARTTLS is required for authenticated production SMTP');
    }
}

return [
    'version' => '1.0.0',
    'environment' => $environment,
    'issuer' => $issuer,
    'apiAudience' => $audience,
    'jwtSecret' => $jwtSecret,
    'encryptionKey' => $encryptionKey,
    'accessTokenTtl' => Environment::int('SSO_ACCESS_TOKEN_TTL', 900, 60, 86400),
    'sessionTtl' => Environment::int('SSO_SESSION_TTL', 2592000, 300, 31536000),
    'refreshTokenTtl' => Environment::int('SSO_REFRESH_TOKEN_TTL', 2592000, 300, 31536000),
    'cookieSecure' => Environment::bool('SSO_COOKIE_SECURE', false),
    'maxBodyBytes' => Environment::int('SSO_MAX_BODY_BYTES', 1048576, 1024, 10485760),
    'rateLimitPerMinute' => Environment::int('SSO_RATE_LIMIT_PER_MINUTE', 30, 1, 100000),
    'rateLimitBurst' => Environment::int('SSO_RATE_LIMIT_BURST', 10, 1, 100000),
    'cacheDriver' => $cacheDriver,
    'trustedProxies' => (string)Environment::value('SSO_TRUSTED_PROXIES', ''),
    'outboundHostAllowlist' => (string)Environment::value('SSO_OUTBOUND_HOST_ALLOWLIST', ''),
    'outboundConnectTimeout' => Environment::int('SSO_OUTBOUND_CONNECT_TIMEOUT', 5, 1, 60),
    'outboundReadTimeout' => Environment::int('SSO_OUTBOUND_READ_TIMEOUT', 10, 1, 120),
    'outboundMaxResponseBytes' => Environment::int(
        'SSO_OUTBOUND_MAX_RESPONSE_BYTES',
        1048576,
        1024,
        10485760
    ),
    'ldapAllowedHosts' => (string)Environment::value('SSO_LDAP_ALLOWED_HOSTS', ''),
    'ldapCaCertificate' => $ldapCaCertificate,
    'ldapAllowInsecure' => Environment::bool('SSO_LDAP_ALLOW_INSECURE', false),
    'ldapNetworkTimeout' => Environment::int('SSO_LDAP_NETWORK_TIMEOUT', 5, 1, 60),
    'rateLimitedRoutes' => [
        'auth/login',
        'auth/verify-mfa',
        'auth/forgot-password',
        'auth/reset-password',
        'auth/verify-email',
        'bootstrap/create',
        'oauth/token',
        'oauth/revoke',
        'oauth/introspect',
    ],
    'smtpHost' => (string)Environment::value('SSO_SMTP_HOST', ''),
    'smtpPort' => Environment::int('SSO_SMTP_PORT', 25, 1, 65535),
    'smtpUsername' => $smtpUsername,
    'smtpPassword' => (string)Environment::value('SSO_SMTP_PASSWORD', ''),
    'smtpFrom' => (string)Environment::value('SSO_SMTP_FROM', ''),
    'smtpStartTls' => $smtpStartTls,
    'smtpCaCertificate' => $smtpCaCertificate,
    'auditRetentionDays' => Environment::int('SSO_AUDIT_RETENTION_DAYS', 365, 1, 3650),
    'cleanupBatchSize' => Environment::int('SSO_CLEANUP_BATCH_SIZE', 1000, 1, 10000),
    'cleanupInterval' => Environment::int('SSO_CLEANUP_INTERVAL', 3600, 60, 86400),
    'metricsAllowedCidrs' => $metricsAllowedCidrs,
    'metricsBearerToken' => $metricsBearerToken,
    'readinessCryptoChecks' => true,
];
