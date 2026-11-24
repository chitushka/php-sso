<?php

$root = dirname(__DIR__, 2);
$directories = new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $file): bool {
        return !$file->isDir() || !in_array($file->getFilename(), ['vendor', 'runtime', '.git', 'node_modules'], true);
    }
);
$count = 0;
$failed = false;
foreach (new RecursiveIteratorIterator($directories) as $file) {
    if ($file->getExtension() !== 'php' && $file->getPathname() !== $root . '/yii') {
        continue;
    }
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode("\n", $output) . "\n");
        $failed = true;
    }
    $count++;
}
echo sprintf("PHP %s: checked %d files\n", PHP_VERSION, $count);
exit($failed ? 1 : 0);
