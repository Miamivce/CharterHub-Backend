<?php

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Retrieve JSON payload
$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';

if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'Token missing']);
    exit;
}

require_once __DIR__ . '/JWTAuthService.php';

$newToken = JWTAuthService::refreshToken($token);

if ($newToken) {
    echo json_encode(['token' => $newToken]);
    exit;
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

?> 