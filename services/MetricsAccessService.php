<?php

namespace app\services;

use Yii;
use yii\web\Request;

class MetricsAccessService
{
    public function allowed(Request $request): bool
    {
        $configuredToken = (string)(Yii::$app->params['metricsBearerToken'] ?? '');
        $authorization = (string)$request->headers->get('Authorization', '');
        if (
            $configuredToken !== '' && stripos($authorization, 'Bearer ') === 0
            && hash_equals($configuredToken, trim(substr($authorization, 7)))
        ) {
            return true;
        }

        foreach (array_filter(array_map('trim', explode(',', (string)(Yii::$app->params['metricsAllowedCidrs'] ?? '')))) as $cidr) {
            if ($this->contains($cidr, (string)$request->userIP)) {
                return true;
            }
        }

        return false;
    }

    private function contains(string $cidr, string $ip): bool
    {
        if (strpos($cidr, '/') === false) {
            $cidr .= strpos($cidr, ':') === false ? '/32' : '/128';
        }
        [$network, $prefixText] = explode('/', $cidr, 2);
        if (preg_match('/^\d+$/D', $prefixText) !== 1) {
            return false;
        }
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }
        $prefix = (int)$prefixText;
        if ($prefix < 0 || $prefix > strlen($packedIp) * 8) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if (substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }
}
