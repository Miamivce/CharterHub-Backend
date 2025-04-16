<?php
/**
 * Customers Endpoint
 * 
 * This endpoint serves as a proxy to /api/admin/direct-customers.php.
 * It ensures proper CORS handling and maintains backward compatibility.
 */

// Define CHARTERHUB_LOADED constant for included files
define('CHARTERHUB_LOADED', true);

// Initialize a timer to track execution time
$start_time = microtime(true);

// Include admin CORS helper if available
$cors_helper_path = __DIR__ . '/../api/admin/admin-cors-helper.php';
$direct_customers_path = __DIR__ . '/../api/admin/direct-customers.php';
$direct_auth_helper_path = __DIR__ . '/../api/admin/direct-auth-helper.php';

// Log request for debugging
error_log("CUSTOMERS PROXY: Request received from origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'unknown') . ", method: " . $_SERVER['REQUEST_METHOD']);
error_log("CUSTOMERS PROXY: Request URI: " . $_SERVER['REQUEST_URI']);
error_log("CUSTOMERS PROXY: Looking for admin-cors-helper at: $cors_helper_path");
error_log("CUSTOMERS PROXY: Looking for direct-customers at: $direct_customers_path");

// Start output buffering to prevent header issues
if (!ob_get_level()) {
    ob_start();
}

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
    'https://charter-hub.vercel.app/',
    'https://app.yachtstory.be'
];

// Get the request origin
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Check if the origin is allowed
$originIsAllowed = in_array($origin, $allowed_origins);
$isDev = strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false;

// Set CORS headers directly (fail-safe approach)
if ($originIsAllowed || $isDev) {
    error_log("CUSTOMERS PROXY: Setting CORS headers for origin: $origin");
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires");
    header("Access-Control-Max-Age: 86400"); // 24 hours
} else {
    error_log("CUSTOMERS PROXY: Disallowed origin: $origin");
}

// Handle preflight OPTIONS request immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    error_log("CUSTOMERS PROXY: Responding to OPTIONS preflight request");
    http_response_code(200);
    exit;
}

// Try to include the proper files if they exist
$cors_helper_loaded = false;
if (file_exists($cors_helper_path)) {
    error_log("CUSTOMERS PROXY: Including admin-cors-helper.php");
    require_once $cors_helper_path;
    $cors_helper_loaded = true;
}

// For non-OPTIONS requests, forward to direct-customers.php
try {
    if (file_exists($direct_customers_path) && file_exists($direct_auth_helper_path)) {
        error_log("CUSTOMERS PROXY: Including direct-auth-helper.php and direct-customers.php");
        
        // Capture the request body for POST requests
        $requestBody = file_get_contents("php://input");
        error_log("CUSTOMERS PROXY: Request body: " . $requestBody);
        
        // Create a backup of the request body
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($requestBody)) {
            // Store the original input in a global variable
            $GLOBALS['_ORIGINAL_INPUT'] = $requestBody;
            
            // Create a stream with the original request body
            $tempStream = fopen('php://temp', 'r+');
            fwrite($tempStream, $requestBody);
            rewind($tempStream);
            
            // Override php://input with our stream
            stream_context_set_default([
                'php' => [
                    'input' => $tempStream
                ]
            ]);
        }
        
        // Include the actual endpoint files
        require_once $direct_auth_helper_path;
        
        // Instead of requiring the file, include it with output buffering
        error_log("CUSTOMERS PROXY: Forwarding request to direct-customers.php");
        
        // Capture the output
        ob_start();
        require $direct_customers_path;
        $output = ob_get_clean();
        
        // If we got here, the direct-customers.php processed the request
        error_log("CUSTOMERS PROXY: Request processed by direct-customers.php. Output length: " . strlen($output));
        
        // Output the response
        echo $output;
    } else {
        // Fall back to direct error response
        error_log("CUSTOMERS PROXY: direct-customers.php or direct-auth-helper.php not found");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error: Required files not found',
            'debug' => [
                'direct_customers_exists' => file_exists($direct_customers_path),
                'direct_auth_helper_exists' => file_exists($direct_auth_helper_path),
                'cors_helper_exists' => file_exists($cors_helper_path)
            ]
        ]);
    }
} catch (Exception $e) {
    // Log the error
    error_log("CUSTOMERS PROXY: Exception: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

// Log execution time
$end_time = microtime(true);
$execution_time = ($end_time - $start_time) * 1000; // in milliseconds
error_log("CUSTOMERS PROXY: Execution time: " . number_format($execution_time, 2) . " ms");
?> 