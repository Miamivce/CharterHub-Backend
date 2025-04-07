<?php
/**
 * CharterHub Token Debug API Endpoint
 * 
 * This file provides debugging information about tokens and authentication
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Include configuration and CORS handling
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cors-fix.php'; // Apply CORS headers first

// Set content type to JSON
header('Content-Type: application/json');

// Only allow in development mode
if (!defined('DEVELOPMENT_MODE') || DEVELOPMENT_MODE !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint is only available in development mode'
    ]);
    exit;
}

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // Get JWT token from Authorization header
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    $jwt = null;
    $jwt_data = null;
    
    if (strpos($auth_header, 'Bearer ') === 0) {
        $jwt = substr($auth_header, 7);
        try {
            $jwt_data = verify_jwt_token($jwt, true); // Allow expired tokens
        } catch (Exception $e) {
            $jwt_data = ['error' => $e->getMessage()];
        }
    }
    
    // Check for refresh token in charterhub_users table
    $refresh_tokens = [];
    $stmt = $pdo->query("
        SELECT chu.id, chu.wp_user_id, chu.role, chu.refresh_token, u.user_email
        FROM {$db_config['table_prefix']}charterhub_users chu
        JOIN {$db_config['table_prefix']}users u ON chu.wp_user_id = u.ID
        WHERE chu.refresh_token IS NOT NULL
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $refresh_tokens[] = [
            'id' => $row['id'],
            'wp_user_id' => $row['wp_user_id'],
            'role' => $row['role'],
            'user_email' => $row['user_email'],
            'has_refresh_token' => !empty($row['refresh_token']),
            'refresh_token_length' => strlen($row['refresh_token'])
        ];
    }
    
    // Check CSRF token
    $csrf_token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null;
    $csrf_valid = $csrf_token ? verify_csrf_token($csrf_token) : false;
    
    // Return debug information
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'auth_header' => $auth_header ? 'Present' : 'Missing',
        'jwt_token' => $jwt ? [
            'present' => true,
            'data' => $jwt_data
        ] : [
            'present' => false
        ],
        'refresh_tokens' => $refresh_tokens,
        'csrf_token' => [
            'present' => !empty($csrf_token),
            'valid' => $csrf_valid
        ],
        'development_mode' => DEVELOPMENT_MODE,
        'request_headers' => getallheaders()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} 