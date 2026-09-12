<?php

namespace app\components;

use yii\web\Request;

class TrustedRequest extends Request
{
    public function getUserIP()
    {
        $peer = $this->hostOnly((string)($_SERVER['REMOTE_ADDR'] ?? parent::getUserIP()));
        $trusted = $this->trustedNetworks();
        $xff = (string)$this->headers->get('X-Forwarded-For', '');

        if ($xff !== '' && $this->ipInNetworks($peer, $trusted)) {
            $client = $this->rightmostUntrusted($xff, $trusted);
            if ($client !== '') {
                return $client;
            }
        }

        return $peer;
    }

    private function trustedNetworks(): array
    {
        $raw = (string)($this->getTrustedProxiesParam());
        $networks = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') === false) {
                $entry .= strpos($entry, ':') === false ? '/32' : '/128';
            }
            [$network, $prefix] = explode('/', $entry, 2);
            $packed = @inet_pton($network);
            if ($packed === false) {
                continue;
            }
            $networks[] = [
                'packed' => $packed,
                'prefix' => (int)$prefix,
                'bytes' => strlen($packed),
            ];
        }

        return $networks;
    }

    private function getTrustedProxiesParam(): string
    {
        return \Yii::$app->params['trustedProxies'] ?? '';
    }

    private function hostOnly(string $addr): string
    {
        if (strpos($addr, '[') === 0) {
            $end = strpos($addr, ']');
            return $end === false ? $addr : substr($addr, 1, $end - 1);
        }

        $colon = strrpos($addr, ':');
        if ($colon !== false && strpos($addr, ':') === $colon) {
            return substr($addr, 0, $colon);
        }

        return $addr;
    }

    private function rightmostUntrusted(string $xff, array $trusted): string
    {
        $parts = array_reverse(explode(',', $xff));
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip !== '' && !$this->ipInNetworks($ip, $trusted)) {
                return $ip;
            }
        }

        return '';
    }

    private function ipInNetworks(string $ip, array $networks): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        foreach ($networks as $network) {
            if (strlen($packedIp) !== $network['bytes']) {
                continue;
            }
            if ($this->matchesPrefix($packedIp, $network['packed'], $network['prefix'])) {
                return true;
            }
        }

        return false;
    }

    private function matchesPrefix(string $ip, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ip, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
