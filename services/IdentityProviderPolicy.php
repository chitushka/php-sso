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

    public function endpointsAreSafe(IdentityProvider $provider): bool
    {
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
