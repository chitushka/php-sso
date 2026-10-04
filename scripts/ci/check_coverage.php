<?php

$report = $argv[1] ?? '';
$threshold = (float)($argv[2] ?? 35);
if (!is_file($report)) {
    fwrite(STDERR, "Coverage report is missing\n");
    exit(1);
}
$xml = simplexml_load_file($report);
if ($xml === false) {
    fwrite(STDERR, "Coverage report is invalid\n");
    exit(1);
}
$critical = [
    'services/AuthService.php',
    'services/OAuthService.php',
    'services/OidcService.php',
    'services/CryptoService.php',
    'services/BrowserSecurityService.php',
];
$covered = 0;
$total = 0;
foreach ($xml->project->file as $file) {
    $name = str_replace('\\', '/', (string)$file['name']);
    if (!in_array($name, $critical, true) && !in_array(basename(dirname($name)) . '/' . basename($name), $critical, true)) {
        continue;
    }
    foreach ($file->line as $line) {
        if ((string)$line['type'] === 'stmt') {
            $total++;
            if ((int)$line['count'] > 0) {
                $covered++;
            }
        }
    }
}
$percentage = $total === 0 ? 0.0 : ($covered / $total) * 100;
printf("Security-critical line coverage: %.2f%% (%d/%d)\n", $percentage, $covered, $total);
exit($percentage + 0.0001 >= $threshold ? 0 : 1);
