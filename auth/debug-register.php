<?php
header('Content-Type: application/json');

// Fix CORS with specific origin instead of wildcard for credentials
$allowed_origin = 'https://charter-hub.vercel.app';
if (isset($_SERVER['HTTP_ORIGIN'])) {
    if ($_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
        header("Access-Control-Allow-Origin: $allowed_origin");
    } else {
        // For development, also allow localhost origins
        if (strpos($_SERVER['HTTP_ORIGIN'], 'localhost') !== false || 
            strpos($_SERVER['HTTP_ORIGIN'], '127.0.0.1') !== false) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        }
    }
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Read input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Return success with the data we received for diagnosis
echo json_encode([
    'success' => true,
    'message' => 'Registration debug mode',
    'received_data' => $data,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'timestamp' => date('Y-m-d H:i:s')
]);
