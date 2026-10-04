<?php

namespace app\services;

use Yii;

class OutboundHttpService
{
    private const DEFAULT_MAX_RESPONSE_BYTES = 1048576;

    public function isValidEndpoint(string $url, bool $allowFragment = false): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) || strpos($url, '\\') !== false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || (!$allowFragment && isset($parts['fragment']))) {
            return false;
        }

        $host = $this->normalizeHost((string)$parts['host']);
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->isAllowlisted($host))) {
            return false;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return is_int($port) && $port > 0 && $port <= 65535;
    }

    public function requestJson(string $url, string $method, ?string $body, array $headers): ?array
    {
        $raw = $this->request($url, $method, $body, $headers);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function request(string $url, string $method, ?string $body, array $headers): ?string
    {
        if (!$this->isValidEndpoint($url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = $this->normalizeHost((string)$parts['host']);
        $addresses = $this->resolveAddresses($host);
        if (empty($addresses)) {
            return null;
        }

        if (!$this->isAllowlisted($host)) {
            foreach ($addresses as $address) {
                if (!$this->isPublicAddress($address)) {
                    return null;
                }
            }
        }

        $scheme = strtolower((string)$parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $hostHeader = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $hostHeader .= ':' . $port;
        }
        $headers[] = 'Host: ' . $hostHeader;

        foreach ($addresses as $address) {
            // Адрес повторно проверяется непосредственно перед каждым соединением.
            if (!$this->isAllowlisted($host) && !$this->isPublicAddress($address)) {
                return null;
            }

            $authority = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
            if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
                $authority .= ':' . $port;
            }
            $resolvedUrl = $scheme . '://' . $authority . $path;
            if (isset($parts['query'])) {
                $resolvedUrl .= '?' . $parts['query'];
            }

            $context = stream_context_create([
                'http' => [
                    'method' => strtoupper($method),
                    'header' => implode("\r\n", $headers),
                    'content' => $body ?? '',
                    'timeout' => max(1, (int)(Yii::$app->params['outboundConnectTimeout'] ?? 5)),
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => $host,
                    'SNI_enabled' => true,
                ],
            ]);

            $stream = @fopen($resolvedUrl, 'rb', false, $context);
            if ($stream === false) {
                continue;
            }
            try {
                stream_set_timeout($stream, max(1, (int)(Yii::$app->params['outboundReadTimeout'] ?? 10)));
                $limit = max(1, (int)(Yii::$app->params['outboundMaxResponseBytes'] ?? self::DEFAULT_MAX_RESPONSE_BYTES));
                $raw = stream_get_contents($stream, $limit + 1);
                $metadata = stream_get_meta_data($stream);
                if ($raw === false || strlen($raw) > $limit || !empty($metadata['timed_out'])) {
                    return null;
                }
                $status = $this->responseStatus($http_response_header ?? []);
                return $status >= 200 && $status < 300 ? $raw : null;
            } finally {
                fclose($stream);
            }
        }

        return null;
    }

    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        // PHP 7.4 считает IPv4-mapped IPv6 публичным по IPv6-диапазону,
        // поэтому отдельно проверяем вложенный IPv4 и не пропускаем loopback/private адреса.
        if (
            strlen($packed) === 16
            && substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff"
        ) {
            $mappedAddress = inet_ntop(substr($packed, 12));
            return is_string($mappedAddress) && $this->isPublicAddress($mappedAddress);
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function isAllowlisted(string $host): bool
    {
        $configured = (string)(Yii::$app->params['outboundHostAllowlist'] ?? '');
        $hosts = array_filter(array_map(function (string $value): string {
            return $this->normalizeHost($value);
        }, explode(',', $configured)));

        return in_array($this->normalizeHost($host), $hosts, true);
    }

    private function normalizeHost(string $host): string
    {
        return rtrim(strtolower(trim($host, " \t\n\r\0\x0B[]")), '.');
    }

    private function responseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string)$header, $matches)) {
                $status = (int)$matches[1];
            }
        }
        return $status;
    }
}
