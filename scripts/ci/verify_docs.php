<?php

$root = dirname(__DIR__, 2);
foreach (['README.md', 'PLAN.md', 'ARCHITECTURE.md', 'ENDPOINT_CONTRACTS.md', 'SECURITY.md', 'OPERATIONS.md', 'VENDORED_ASSETS.md'] as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Missing required documentation: {$file}\n");
        exit(1);
    }
}

$configuration = file_get_contents($root . '/config/params.php')
    . file_get_contents($root . '/config/db.php')
    . file_get_contents($root . '/docker-compose.yml');
preg_match_all('/SSO_[A-Z0-9_]+/', $configuration, $matches);
$example = (string)file_get_contents($root . '/.env.example')
    . (string)file_get_contents($root . '/.env.development.example');
$missing = [];
foreach (array_unique($matches[0]) as $name) {
    if (substr($name, -5) === '_FILE') {
        continue;
    }
    if (strpos($example, $name . '=') === false && strpos($example, $name . '_FILE=') === false) {
        $missing[] = $name;
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'Undocumented environment variables: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "Documentation environment contract is current\n";
