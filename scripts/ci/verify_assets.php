<?php

$asset = dirname(__DIR__, 2) . '/web/js/vue.global.prod.js';
$manifest = $asset . '.sha256';
$expected = strtolower(strtok(trim((string)file_get_contents($manifest)), " \t"));
$actual = hash_file('sha256', $asset);
if ($actual === false || !hash_equals($expected, strtolower($actual))) {
    fwrite(STDERR, "Vendored Vue asset checksum mismatch\n");
    exit(1);
}
echo "Vendored Vue asset checksum is valid\n";
