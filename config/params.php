<?php

$environment = strtolower((string)getenv('SSO_ENV'));
$issuer = rtrim(getenv('SSO_ISSUER') ?: 'http://localhost:8080', '/');
$jwtSecret = getenv('SSO_JWT_SECRET') ?: 'change-me-please-change-me-please-change-me';
$encryptionKey = getenv('SSO_ENCRYPTION_KEY') ?: 'change-me-please-change-me-please-change-me';
if ($environment === 'prod') {
    foreach (['SSO_JWT_SECRET' => $jwtSecret, 'SSO_ENCRYPTION_KEY' => $encryptionKey] as $name => $value) {
        if (strlen($value) < 32 || stripos($value, 'change-me') !== false) {
            throw new RuntimeException($name . ' must be a production secret');
        }
    }
    if (parse_url($issuer, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('SSO_ISSUER must use HTTPS in production');
    }
    if (!getenv('SSO_DATABASE_URL')) {
        throw new RuntimeException('SSO_DATABASE_URL is required in production');
    }
}

return [
    'version' => '0.1.0-port',
    'issuer' => $issuer,
    'apiAudience' => getenv('SSO_API_AUDIENCE') ?: rtrim(getenv('SSO_ISSUER') ?: 'http://localhost:8080', '/') . '/api',
    'jwtSecret' => $jwtSecret,
    'encryptionKey' => $encryptionKey,
    'accessTokenTtl' => (int)(getenv('SSO_ACCESS_TOKEN_TTL') ?: 900),
    'sessionTtl' => (int)(getenv('SSO_SESSION_TTL') ?: 2592000),
    'refreshTokenTtl' => (int)(getenv('SSO_REFRESH_TOKEN_TTL') ?: 2592000),
    'cookieSecure' => filter_var(getenv('SSO_COOKIE_SECURE') ?: false, FILTER_VALIDATE_BOOLEAN),
    'maxBodyBytes' => (int)(getenv('SSO_MAX_BODY_BYTES') ?: 1048576),
    'rateLimitPerMinute' => (int)(getenv('SSO_RATE_LIMIT_PER_MINUTE') ?: 30),
    'rateLimitBurst' => (int)(getenv('SSO_RATE_LIMIT_BURST') ?: 10),
    'cacheDriver' => getenv('SSO_CACHE_DRIVER') ?: 'file',
    'trustedProxies' => getenv('SSO_TRUSTED_PROXIES') ?: '',
    'outboundHostAllowlist' => getenv('SSO_OUTBOUND_HOST_ALLOWLIST') ?: '',
    'ldapAllowedHosts' => getenv('SSO_LDAP_ALLOWED_HOSTS') ?: '',
    'ldapCaCertificate' => getenv('SSO_LDAP_CA_CERT') ?: '',
    'ldapAllowInsecure' => filter_var(getenv('SSO_LDAP_ALLOW_INSECURE') ?: false, FILTER_VALIDATE_BOOLEAN),
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
    'smtpHost' => getenv('SSO_SMTP_HOST') ?: '',
    'smtpPort' => (int)(getenv('SSO_SMTP_PORT') ?: 25),
    'smtpUsername' => getenv('SSO_SMTP_USERNAME') ?: '',
    'smtpPassword' => getenv('SSO_SMTP_PASSWORD') ?: '',
    'smtpFrom' => getenv('SSO_SMTP_FROM') ?: '',
    'smtpStartTls' => filter_var(getenv('SSO_SMTP_STARTTLS') ?: false, FILTER_VALIDATE_BOOLEAN),
    'smtpCaCertificate' => getenv('SSO_SMTP_CA_CERT') ?: '',
    'auditRetentionDays' => (int)(getenv('SSO_AUDIT_RETENTION_DAYS') ?: 365),
    'cleanupBatchSize' => (int)(getenv('SSO_CLEANUP_BATCH_SIZE') ?: 1000),
];
