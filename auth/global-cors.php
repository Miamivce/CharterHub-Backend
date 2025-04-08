<?php
/**
 * Global CORS configuration for CharterHub Auth API
 * 
 * This file handles CORS headers for all API endpoints.
 * It's included at the start of each API file.
 */

// Don't allow direct access
if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access not allowed');
}

// Start output buffering to prevent unwanted output from included files
// but don't force it if already started
if (!ob_get_level()) {
    ob_start();
}

// Function to send JSON response consistently
function send_json_response($data, $status_code = 200) {
    // Clear any output buffering to ensure clean response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set proper JSON content type and status code
    header('Content-Type: application/json');
    http_response_code($status_code);
    
    // Send JSON response
    echo json_encode($data);
    exit;
}

// IMPORTANT: Don't automatically apply CORS headers or use exit from this file
// Each endpoint should call apply_cors_headers() explicitly

// Function to apply CORS headers with allowed methods
function apply_cors_headers($allowed_methods = ['GET', 'POST', 'OPTIONS']) {
    // Read allowed origins from environment variable
    $env_origins = getenv('CORS_ALLOWED_ORIGINS');
    error_log("CORS environment variable: " . $env_origins);
    $env_origins_array = $env_origins ? explode(',', $env_origins) : [];
    
    // Default allowed origins
    $default_origins = [
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
        'https://charter-hub.vercel.app',
        'https://admin.yachtstory.be',
        'https://app.yachtstory.be'
    ];
    
    // Combine environment origins with default origins and remove duplicates
    $allowed_origins = array_unique(array_merge($default_origins, $env_origins_array));
    
    // Debug - Log all configured origins
    error_log("CORS allowed origins: " . implode(', ', $allowed_origins));
    
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    // Debug - Log the request origin
    error_log("CORS request from origin: " . $origin);

    // Check if origin is allowed or if we're in development mode
    $isDev = strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false;
    $isAllowed = in_array($origin, $allowed_origins);

    error_log("CORS origin allowed: " . ($isAllowed ? 'yes' : 'no'));
    error_log("CORS isDev: " . ($isDev ? 'yes' : 'no'));

    // Set CORS headers based on origin
    if ($isAllowed || $isDev) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        
        // Convert allowed methods array to string
        $methods_string = implode(', ', $allowed_methods);
        
        // Always set these headers for any request type
        header("Access-Control-Allow-Methods: $methods_string");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires");
        
        // Preflight request handler
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header("Access-Control-Max-Age: 86400"); // 24 hours
            
            // End response for preflight with 200 status
            http_response_code(200);
            exit;
        }
    } else {
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            error_log("CORS request from disallowed origin: " . $_SERVER['HTTP_ORIGIN'] . " - Make sure to add it to CORS_ALLOWED_ORIGINS environment variable or defaults.");
        }
    }
}

// Alias for backward compatibility
function apply_global_cors($allowed_methods = ['GET', 'POST', 'OPTIONS']) {
    apply_cors_headers($allowed_methods);
} 