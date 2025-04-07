<?php
/**
 * CORS Headers Helper
 * 
 * This file provides a function to apply CORS headers consistently across all API endpoints.
 * It enables proper cross-origin resource sharing for development and production environments.
 */

/**
 * Apply CORS headers to allow cross-origin requests
 * This should be called at the beginning of each API file, before any output
 */
function apply_cors_headers() {
    // Allow specific origins for development
    $allowed_origins = [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:3003',
        'http://localhost:3004',
        'http://localhost:3005',
        'http://127.0.0.1:3000'
    ];
    
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        // For development, allow any origin
        header("Access-Control-Allow-Origin: $origin");
    }
    
    // Essential CORS headers
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Max-Age: 86400"); // 24 hours cache
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
} 