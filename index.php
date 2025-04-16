<?php
/**
 * CharterHub API Entry Point
 * 
 * This file serves as the main entry point for all API requests in the Render deployment.
 * It includes handling for CORS and routing requests to the appropriate API endpoints.
 */

// Define allowed origins
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173', 
    'http://localhost:8080',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:8080',
    'https://charterhub.yachtstory.com',
    'https://staging-charterhub.yachtstory.com',
    'https://app.yachtstory.be',
    'https://admin.yachtstory.be',
    'https://www.admin.yachtstory.be',
    'http://admin.yachtstory.be',
    'https://yachtstory.be',
    'https://www.yachtstory.be',
    'https://charter-hub.vercel.app/'
];

// Get the origin from the request
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Log request for debugging
error_log("API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'] . " from origin: " . $origin);

// Check if origin is allowed
$isAllowed = in_array($origin, $allowed_origins);
$isDev = strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false;

// Set CORS headers based on origin
if ($isAllowed || $isDev) {
    // Use specific origin, not wildcard, for credentials compatibility
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
} else {
    // Use wildcard only for non-credential requests
    header('Access-Control-Allow-Origin: *');
    error_log("Unknown origin: $origin - using wildcard CORS");
}

// Set common CORS headers
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

// Special case for /customers endpoint for backward compatibility
if ($path === '/customers') {
    require_once __DIR__ . '/customers/index.php';
    exit();
}

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
