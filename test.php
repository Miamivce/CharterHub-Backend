<?php
// Basic diagnostic endpoint that should always work
header('Content-Type: application/json');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Build a simple response
$response = [
    'success' => true,
    'message' => 'Test endpoint is working',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_info' => [
        'version' => PHP_VERSION,
        'sapi' => php_sapi_name(),
        'os' => PHP_OS
    ],
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'query' => $_GET
    ]
];

// Output JSON
echo json_encode($response, JSON_PRETTY_PRINT);
?> 