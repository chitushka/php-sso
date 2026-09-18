<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$storage = sys_get_temp_dir() . '/sso-php-backchannel-logout.json';

if ($path === '/reset' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    @unlink($storage);
    http_response_code(204);
    return;
}

if ($path === '/logout' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    parse_str(file_get_contents('php://input') ?: '', $body);
    file_put_contents($storage, json_encode([
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
        'logout_token' => (string)($body['logout_token'] ?? ''),
    ]));
    http_response_code(204);
    return;
}

if ($path === '/last' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: application/json');
    echo is_file($storage) ? file_get_contents($storage) : json_encode([]);
    return;
}

http_response_code(404);
