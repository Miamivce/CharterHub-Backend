<?php
/**
 * Simple JSON Test Endpoint
 *
 * This file provides a basic test endpoint for debugging purposes.
 * It returns a simple JSON response without requiring authentication.
 */

// Start output buffering to capture any errors
ob_start();

// Ensure proper content type
header('Content-Type: application/json');

// Add CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Build response data
$response = [
    'success' => true,
    'message' => 'API is functioning correctly',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_info' => [
        'version' => PHP_VERSION,
        'sapi' => php_sapi_name(),
        'extensions' => [
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'json' => extension_loaded('json')
        ],
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time')
    ],
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'query_params' => $_GET
    ]
];

// Send JSON response
echo json_encode($response, JSON_PRETTY_PRINT);

// End output buffering
ob_end_flush(); 