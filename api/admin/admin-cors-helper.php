<?php
/**
 * Admin CORS Helper
 * 
 * Provides CORS handling functions for admin API endpoints
 * Centralizes CORS configuration for consistency across all admin endpoints
 */

// Prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
    exit('No direct script access allowed');
}

// Apply CORS headers for admin endpoints
function apply_admin_cors_headers($allowed_methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']) {
    // Define allowed origins
    $allowed_origins = [
        'http://localhost:3000',
        'http://localhost:5173', 
        'http://localhost:8000',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:8000',
        'https://charterhub.yachtstory.com',
        'https://staging-charterhub.yachtstory.com',
        'https://admin.yachtstory.be',
        'https://www.admin.yachtstory.be',
        'http://admin.yachtstory.be',
        'https://app.yachtstory.be'
    ];
    
    // Get the origin from the request headers
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    
    // Log request details for debugging
    $request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    error_log("Admin API Request: $request_method from origin: $origin");
    
    // Check if origin is allowed
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-HTTP-Method-Override");
        header("Access-Control-Allow-Methods: " . implode(', ', $allowed_methods));
        header("Access-Control-Max-Age: 86400"); // 24 hours cache
        
        error_log("CORS headers applied for origin: $origin");
    } else if (empty($origin)) {
        // Default fallback if no origin is provided
        header("Access-Control-Allow-Origin: http://localhost:3000");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-HTTP-Method-Override");
        header("Access-Control-Allow-Methods: " . implode(', ', $allowed_methods));
        header("Access-Control-Max-Age: 86400"); // 24 hours cache
        error_log("CORS headers applied with default localhost:3000 (no origin provided)");
    } else {
        // Log unauthorized origin attempts
        error_log("CORS request rejected from non-allowed origin: " . $origin);
        // Don't set Access-Control-Allow-Origin header for non-allowed origins
    }
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        error_log("OPTIONS preflight request handled with 200 response");
        exit;
    }
}

// Helper function to log request details
function log_admin_request_details() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $path = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? 'UNKNOWN';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    
    error_log("Admin API Request: $method $path from $origin | User-Agent: $userAgent");
    
    // Log content type for non-GET requests
    if ($method !== 'GET') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? 'UNKNOWN';
        error_log("Request Content-Type: $contentType");
    }
}
?> 