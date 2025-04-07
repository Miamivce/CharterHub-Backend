<?php
/**
 * CharterHub Reset Login Attempts API Endpoint
 * 
 * This file resets the login attempts counter for an IP address - DEVELOPMENT MODE ONLY
 */

// Include configuration
require_once __DIR__ . '/config.php';

// Set CORS headers
set_cors_headers(['POST', 'GET', 'OPTIONS']);

// THIS ENDPOINT IS FOR DEVELOPMENT USE ONLY
if (!defined('DEVELOPMENT_MODE') || DEVELOPMENT_MODE !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint is only available in development mode'
    ]);
    exit;
}

// Handle both GET and POST requests for flexibility
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get the IP address to reset (default to current user's IP)
$ip_address = $_SERVER['REMOTE_ADDR'];

// If a specific IP is provided and the user is on localhost, use that IP
if (isset($_REQUEST['ip']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
    $ip_address = $_REQUEST['ip'];
}

// Reset rate limiting for the IP address
$success = reset_rate_limiting($ip_address);

if ($success) {
    echo json_encode([
        'success' => true,
        'message' => "Rate limiting reset for IP: {$ip_address}",
        'data' => [
            'ip_address' => $ip_address,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => "Failed to reset rate limiting for IP: {$ip_address}"
    ]);
} 