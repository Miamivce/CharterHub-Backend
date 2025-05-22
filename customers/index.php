<?php
/**
 * Customer API Redirect
 * 
 * This file redirects all customer-related requests to the direct-customers.php API endpoint
 * This fixes the issue where the older customer endpoint path is still being used
 */

// Define allowed origins for CORS
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

// Get the request origin
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Log CORS check for debugging
error_log("CUSTOMERS/INDEX.PHP - Request received from origin: $origin, method: " . $_SERVER['REQUEST_METHOD']);

// Set CORS headers directly for immediate handling
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours
    
    error_log("CUSTOMERS/INDEX.PHP - Set CORS headers for origin: $origin");
} else {
    error_log("CUSTOMERS/INDEX.PHP - Origin not allowed: $origin");
}

// Handle preflight OPTIONS requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    error_log("CUSTOMERS/INDEX.PHP - Handling OPTIONS preflight request directly");
    http_response_code(200);
    exit;
}

// Make sure Authorization header is passed through
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    error_log("CUSTOMERS/INDEX.PHP - Found Authorization header, passing through");
} else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    // In some server configurations, Apache prefixes with REDIRECT_
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    error_log("CUSTOMERS/INDEX.PHP - Using REDIRECT_HTTP_AUTHORIZATION header");
} else {
    error_log("CUSTOMERS/INDEX.PHP - No Authorization header found, this might cause 401 errors");
}

// Log the redirection for debugging
error_log("Redirecting customer request from /customers/index.php to /api/admin/direct-customers.php");

// Include the direct-customers.php file with all its functionality
require_once __DIR__ . '/../api/admin/direct-customers.php';

// The script will terminate after direct-customers.php completes
exit; 