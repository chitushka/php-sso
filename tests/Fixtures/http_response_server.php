<?php

$mode = (string)($argv[1] ?? 'length');
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) {
    fwrite(STDERR, 'server failed');
    exit(1);
}
$address = stream_socket_get_name($server, false);
echo substr(strrchr($address, ':'), 1) . "\n";
flush();

$client = stream_socket_accept($server, 10);
if (!$client) {
    exit(2);
}
while (($line = fgets($client)) !== false && trim($line) !== '') {
}

if ($mode === 'chunked') {
    fwrite($client, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
    foreach ([str_repeat('a', 48), str_repeat('b', 48)] as $chunk) {
        fwrite($client, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
    }
    fwrite($client, "0\r\n\r\n");
} else {
    $body = str_repeat('x', 96);
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
}

fclose($client);
fclose($server);
