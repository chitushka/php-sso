<?php

namespace app\services;

use app\components\Rfc3339;
use app\models\IdentityProvider;

final class IdentityProviderPolicy
{
    private OutboundHttpService $outboundHttp;

    public function __construct(?OutboundHttpService $outboundHttp = null)
    {
        $this->outboundHttp = $outboundHttp ?? new OutboundHttpService();
    }

    public function applyPreset(IdentityProvider $provider): IdentityProvider
    {
        if ($provider->type === 'google') {
            $provider->authorize_url = $provider->authorize_url ?: 'https://accounts.google.com/o/oauth2/v2/auth';
            $provider->token_url = $provider->token_url ?: 'https://oauth2.googleapis.com/token';
            $provider->userinfo_url = $provider->userinfo_url ?: 'https://openidconnect.googleapis.com/v1/userinfo';
        }
        if ($provider->type === 'github') {
            $provider->authorize_url = $provider->authorize_url ?: 'https://github.com/login/oauth/authorize';
            $provider->token_url = $provider->token_url ?: 'https://github.com/login/oauth/access_token';
            $provider->userinfo_url = $provider->userinfo_url ?: 'https://api.github.com/user';
            if (!$provider->scopes || $provider->scopes === 'openid profile email') {
                $provider->scopes = 'read:user user:email';
            }
        }
        return $provider;
    }

    public function endpointsAreSafe(IdentityProvider $provider): bool
    {
        if (
            !(bool)$provider->enabled
            && (string)$provider->authorize_url === ''
            && (string)$provider->token_url === ''
            && (string)$provider->userinfo_url === ''
        ) {
            return true;
        }

        return $this->outboundHttp->isValidEndpoint((string)$provider->authorize_url, true)
            && $this->outboundHttp->isValidEndpoint((string)$provider->token_url)
            && $this->outboundHttp->isValidEndpoint((string)$provider->userinfo_url);
    }

    public function publicData(IdentityProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'type' => $provider->type,
            'client_id' => $provider->client_id,
            'authorize_url' => $provider->authorize_url,
            'token_url' => $provider->token_url,
            'userinfo_url' => $provider->userinfo_url,
            'scopes' => $provider->scopes,
            'enabled' => (bool)$provider->enabled,
            'created_at' => Rfc3339::format($provider->created_at),
            'updated_at' => Rfc3339::format($provider->updated_at),
        ];
    }
}
