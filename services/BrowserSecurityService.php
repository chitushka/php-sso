<?php

namespace app\services;

use yii\web\Request;

class BrowserSecurityService
{
    public const CSRF_COOKIE = 'sso_csrf';
    public const CSRF_HEADER = 'X-CSRF-Token';

    private const PROTECTED_ROUTES = [
        'auth/logout',
        'auth/session-token',
        'oauth/grant-consent',
        'oauth/logout',
    ];

    public function requiresCsrf(string $route, Request $request): bool
    {
        if (!in_array($route, self::PROTECTED_ROUTES, true) || !$request->isPost) {
            return false;
        }

        return stripos((string)$request->headers->get('Authorization', ''), 'Bearer ') !== 0;
    }

    public function validateCsrf(Request $request): bool
    {
        $cookie = (string)$request->cookies->getValue(self::CSRF_COOKIE, '');
        $header = trim((string)$request->headers->get(self::CSRF_HEADER, ''));
        if ($cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
            return false;
        }

        if (strtolower((string)$request->headers->get('Sec-Fetch-Site', '')) === 'cross-site') {
            return false;
        }

        $origin = trim((string)$request->headers->get('Origin', ''));
        return $origin === '' || hash_equals(
            $this->origin((string)ApplicationRuntime::param('issuer')),
            $this->origin($origin)
        );
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (
            !is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return '';
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        if (($scheme === 'https' && $port === ':443') || ($scheme === 'http' && $port === ':80')) {
            $port = '';
        }

        return $scheme . '://' . $host . $port;
    }
}
