<?php
/**
 * Admin CORS Helper
 * 
 * Helper functions for handling CORS for admin API endpoints
 */

// Prevent direct access
defined('CHARTERHUB_LOADED') or die('No direct access allowed');

// Include the global CORS helper to ensure consistent origin handling
if (!function_exists('apply_cors_headers')) {
    $global_cors_path = __DIR__ . '/../../auth/global-cors.php';
    if (file_exists($global_cors_path)) {
        require_once $global_cors_path;
        error_log("Admin CORS: Loaded global CORS helper from $global_cors_path");
    } else {
        error_log("Admin CORS: Could not find global CORS helper at $global_cors_path");
    }
}

/**
 * Apply CORS headers for admin API endpoints
 * 
 * @param array $allowed_methods The HTTP methods to allow
 * @return void
 */
function apply_admin_cors_headers($allowed_methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']) {
    // First try to use the global CORS helper if available
    if (function_exists('apply_cors_headers')) {
        error_log("Admin CORS: Using global apply_cors_headers function");
        apply_cors_headers($allowed_methods);
        
        // If this is an OPTIONS request, it should have been handled by apply_cors_headers
        // If not, we'll handle it at the end of this function
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return;
        }
    } else {
        // Fallback to direct implementation if global CORS helper is not available
        error_log("Admin CORS: Global CORS helper not available, using admin-specific implementation");
        
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
            'https://charterhub.app',
            'https://staging.charterhub.app',
            'https://dev.charterhub.app',
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
        
        // Log CORS origins for debugging
        error_log("CORS allowed origins: " . implode(', ', $allowed_origins));
        error_log("CORS request from origin: $origin");
        error_log("CORS check: Origin=$origin, isDev=" . (strpos($origin, 'localhost') !== false ? '1' : '') . ", isAllowed=" . (in_array($origin, $allowed_origins) ? '1' : '0'));
        
        // Set CORS headers
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Allow-Methods: " . implode(', ', $allowed_methods));
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
            header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours
        }
        
        // Handle preflight OPTIONS request and exit
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
    }
}

/**
 * Log detailed information about the request for debugging
 * 
 * @return void
 */
function log_request_details() {
    error_log("Admin API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
    error_log("Origin: " . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : 'none'));
    error_log("User-Agent: " . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'none'));
    error_log("Request Headers: " . json_encode(getallheaders()));
    
    // Log request body for non-GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            error_log("Request Body: " . $input);
        }
    }
}
?> 