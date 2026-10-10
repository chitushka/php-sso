<?php

namespace app\services;

use app\models\OAuthClient;

final class OAuthRedirectPolicy
{
    private OutboundHttpService $outboundHttp;

    public function __construct(?OutboundHttpService $outboundHttp = null)
    {
        $this->outboundHttp = $outboundHttp ?? new OutboundHttpService();
    }

    public function clientUrisAreSafe(OAuthClient $client): bool
    {
        if ($client->allow_loopback_redirects && $client->type !== OAuthClient::TYPE_PUBLIC) {
            return false;
        }

        foreach (
            array_merge(
                OAuthClient::parsePgArray($client->redirect_uris),
                OAuthClient::parsePgArray($client->post_logout_redirect_uris)
            ) as $uri
        ) {
            if (!$this->uriIsSafe($uri, (bool)$client->allow_loopback_redirects)) {
                return false;
            }
        }

        return true;
    }

    public function backchannelUriIsSafe(string $uri): bool
    {
        return $uri === '' || $this->outboundHttp->isValidEndpoint($uri);
    }

    public function uriIsSafe(string $uri, bool $allowLoopback): bool
    {
        if ($uri === '' || preg_match('/[\x00-\x20]/', $uri) || strpos($uri, '\\') !== false) {
            return false;
        }
        $parts = parse_url($uri);
        if (
            !is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower((string)$parts['scheme']);
        return $scheme === 'https'
            || ($scheme === 'http' && $allowLoopback && $this->isLoopbackHost((string)$parts['host']));
    }

    public function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }
}
