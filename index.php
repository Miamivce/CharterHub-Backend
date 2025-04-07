<?php
/**
 * CharterHub API Entry Point
 * 
 * This file serves as the main entry point for all API requests in the Render deployment.
 * It includes handling for CORS and routing requests to the appropriate API endpoints.
 */

// Set appropriate headers for CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
header('Access-Control-Max-Age: 3600');

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    exit();
}

// Set content type to JSON by default
header('Content-Type: application/json');

// Get the requested URL path
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove leading slash and break into components
$path = ltrim($path, '/');
$components = explode('/', $path);

// Handle different API routes
if (count($components) > 0) {
    $base = $components[0];
    
    // Admin API routes
    if ($base === 'api' && isset($components[1]) && $components[1] === 'admin') {
        $endpoint = isset($components[2]) ? $components[2] : null;
        if ($endpoint) {
            $file = __DIR__ . '/api/admin/' . $endpoint . '.php';
            if (file_exists($file)) {
                require_once $file;
                exit();
            }
        }
    }
    
    // Auth routes
    else if ($base === 'auth') {
        $endpoint = isset($components[1]) ? $components[1] : null;
        if ($endpoint) {
            $file = __DIR__ . '/auth/' . $endpoint . '.php';
            if (file_exists($file)) {
                require_once $file;
                exit();
            }
        }
    }
    
    // Standard API routes
    else if ($base === 'api') {
        $endpoint = isset($components[1]) ? $components[1] : null;
        if ($endpoint) {
            $file = __DIR__ . '/api/' . $endpoint . '.php';
            if (file_exists($file)) {
                require_once $file;
                exit();
            }
        }
    }
}

// If no route matches or file doesn't exist, return API info
$response = [
    'name' => 'CharterHub API',
    'version' => '1.0.0',
    'description' => 'API for CharterHub booking and client management system',
    'status' => 'running',
    'endpoints' => [
        'auth' => [
            '/auth/login',
            '/auth/register',
            '/auth/refresh-token',
            '/auth/me'
        ],
        'admin' => [
            '/api/admin/direct-admin-users',
            '/api/admin/direct-customers',
            '/api/admin/direct-bookings'
        ],
        'client' => [
            '/api/client/bookings',
            '/api/client/profile'
        ]
    ],
    'server_time' => date('Y-m-d H:i:s')
];

echo json_encode($response);
exit();
