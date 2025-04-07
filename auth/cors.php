<?php
/**
 * CORS Configuration
 * 
 * This file contains CORS configuration for the CharterHub API.
 */

// Define CHARTERHUB_LOADED constant if not already defined
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Define allowed origins
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://localhost:3003',
    'http://localhost:3004',
    'http://localhost:3005'
];

// Get the origin from the request
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Set CORS headers
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Default to the first allowed origin if the request origin is not in the list
    header("Access-Control-Allow-Origin: {$allowed_origins[0]}");
}

// Set other CORS headers
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
header('Access-Control-Max-Age: 86400');  // 24 hours cache
header('Access-Control-Allow-Credentials: true');  // Allow credentials (cookies, authorization headers)

// Log CORS request in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_log("CORS request from origin: $origin");
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("Request URI: " . $_SERVER['REQUEST_URI']);
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
