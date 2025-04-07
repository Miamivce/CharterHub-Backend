<?php
/**
 * CORS Fix for Authentication Endpoints
 * 
 * This file sets proper CORS headers for the unified authentication system
 * running on port 8000.
 */

// Don't allow direct access
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Direct access not allowed']);
    exit;
}

/**
 * Set CORS headers for the unified authentication system
 */
function apply_cors_headers() {
    $allowed_origins = [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:3003',
        'http://localhost:3004',
        'http://localhost:3005'
    ];
    
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    
    // If the origin is allowed, reflect it back in the ACAO header
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
        header("Access-Control-Max-Age: 86400"); // 24 hours cache for preflight requests
        
        // Log successful CORS
        error_log("CORS headers applied for origin: $origin");
    } else {
        error_log("CORS: Rejected origin: $origin");
        // Don't set any CORS headers for disallowed origins
    }
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        error_log("OPTIONS preflight request handled with 200 response");
        http_response_code(200);
        exit;
    }
}

// Apply CORS headers immediately when this file is included
apply_cors_headers(); 