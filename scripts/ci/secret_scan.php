<?php

$root = dirname(__DIR__, 2);
$patterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\R[A-Za-z0-9+\/=]{40,}/',
    '/\bAKIA[0-9A-Z]{16}\b/',
    '/\bgh[ps]_[A-Za-z0-9]{36,}\b/',
    '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$violations = [];
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!$file->isFile()
        || preg_match('~/(?:\.git|vendor|runtime|tests/Fixtures)/~', $path)
        || preg_match('~/(?:\.env|\.env\..+)$~', $path)
        || preg_match('/\.(?:png|ico|lock)$/', $path)) {
        continue;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        continue;
    }
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $contents)) {
            $violations[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
            break;
        }
    }
}
if ($violations !== []) {
    fwrite(STDERR, 'Potential secrets found: ' . implode(', ', $violations) . "\n");
    exit(1);
}
echo "Secret scan passed\n";
