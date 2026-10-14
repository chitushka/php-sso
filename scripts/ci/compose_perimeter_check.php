<?php

$root = dirname(__DIR__, 2);
$production = (string)file_get_contents($root . '/docker-compose.yml');
$development = (string)file_get_contents($root . '/docker-compose.development.yml');

function serviceBlock(string $yaml, string $service): string
{
    $pattern = '/^  ' . preg_quote($service, '/') . ":\r?\n(.*?)(?=^  [a-z0-9-]+:\r?$|\z)/ms";
    if (preg_match($pattern, $yaml, $matches) !== 1) {
        throw new RuntimeException('compose service is missing: ' . $service);
    }

    return $matches[1];
}

foreach (['postgres', 'web'] as $service) {
    if (preg_match('/^    ports:\r?$/m', serviceBlock($production, $service)) === 1) {
        throw new RuntimeException('production service publishes a host port: ' . $service);
    }
    if (preg_match('/^    ports:\r?$/m', serviceBlock($development, $service)) !== 1) {
        throw new RuntimeException('development service does not publish its local port: ' . $service);
    }
}

if (strpos(serviceBlock($production, 'https-proxy'), '"8443:8443"') === false) {
    throw new RuntimeException('production HTTPS entrypoint is not published');
}

echo "Compose network perimeter is separated for production and development\n";
