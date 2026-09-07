<?php

return [
    'version' => '0.1.0-port',
    'issuer' => rtrim(getenv('SSO_ISSUER') ?: 'http://localhost:8080', '/'),
    'jwtSecret' => getenv('SSO_JWT_SECRET') ?: 'change-me-please-change-me-please-change-me',
    'encryptionKey' => getenv('SSO_ENCRYPTION_KEY') ?: 'change-me-please-change-me-please-change-me',
    'accessTokenTtl' => (int)(getenv('SSO_ACCESS_TOKEN_TTL') ?: 900),
    'sessionTtl' => (int)(getenv('SSO_SESSION_TTL') ?: 2592000),
    'refreshTokenTtl' => (int)(getenv('SSO_REFRESH_TOKEN_TTL') ?: 2592000),
    'cookieSecure' => filter_var(getenv('SSO_COOKIE_SECURE') ?: false, FILTER_VALIDATE_BOOLEAN),
    'maxBodyBytes' => (int)(getenv('SSO_MAX_BODY_BYTES') ?: 1048576),
    'rateLimitPerMinute' => (int)(getenv('SSO_RATE_LIMIT_PER_MINUTE') ?: 30),
    'rateLimitBurst' => (int)(getenv('SSO_RATE_LIMIT_BURST') ?: 10),
    'rateLimitedRoutes' => [
        'auth/login',
        'bootstrap/create',
        'oauth/token',
        'oauth/revoke',
        'oauth/introspect',
    ],
];
