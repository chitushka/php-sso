<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/token') {
    parse_str(file_get_contents('php://input') ?: '', $body);
    $code = (string)($body['code'] ?? '');

    header('Content-Type: application/json');
    echo json_encode([
        'access_token' => $code,
        'token_type' => 'Bearer',
        'expires_in' => 300,
    ]);
    return;
}

if ($path === '/userinfo') {
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $subject = preg_replace('/^Bearer\s+/i', '', $authorization) ?: 'broker-user';
    $safe = strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $subject) ?: 'broker-user');

    header('Content-Type: application/json');
    echo json_encode([
        'sub' => $subject,
        'email' => $safe . '@broker.example.test',
        'email_verified' => true,
        'preferred_username' => $safe,
    ]);
    return;
}

if ($path === '/authorize') {
    header('Content-Type: text/plain');
    echo 'mock authorize endpoint';
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found']);
