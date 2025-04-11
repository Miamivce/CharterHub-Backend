<?php
/**
 * JWT Authentication Helper
 * 
 * This file provides JWT authentication functions for API endpoints
 */

// Include core JWT functionality
require_once __DIR__ . '/jwt-core.php';

/**
 * Verify JWT token from Authorization header
 * 
 * @return array|bool User data if token is valid, false otherwise
 */
function verify_jwt_token() {
    // Get JWT token from Authorization header
    $headers = getallheaders();
    error_log("JWT-AUTH: Headers received: " . json_encode(array_keys($headers)));
    
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    if (empty($auth_header)) {
        // Try alternate header format
        $auth_header = isset($headers['authorization']) ? $headers['authorization'] : '';
    }
    
    error_log("JWT-AUTH: Authorization header: " . (empty($auth_header) ? "Not found" : substr($auth_header, 0, 20) . "..."));
    
    // Check if Authorization header exists and contains Bearer token
    if (empty($auth_header) || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        error_log("JWT Authentication Failed: No valid Authorization header");
        return false;
    }
    
    $jwt = $matches[1];
    error_log("JWT-AUTH: Token extracted, length: " . strlen($jwt));
    
    try {
        // Verify token using core function
        $payload = verify_token($jwt);
        
        if (!$payload) {
            error_log("JWT Authentication Failed: Invalid token");
            return false;
        }
        
        // Extract user data from payload
        $user = [
            'id' => $payload->sub,
            'email' => $payload->email,
            'role' => $payload->role,
        ];
        
        error_log("JWT-AUTH: Authentication successful for user ID: " . $user['id']);
        return $user;
    } catch (Exception $e) {
        error_log("JWT Authentication Failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify that user has admin role
 * 
 * @param array $user User data from verify_jwt_token
 * @return bool True if user is admin, false otherwise
 */
function is_admin_user($user) {
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $role = strtolower($user['role']);
    return ($role === 'admin' || $role === 'administrator');
}

/**
 * Send JSON response
 * 
 * @param array $data Response data
 * @param int $status HTTP status code
 */
function json_response($data, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/**
 * Get database connection
 * 
 * @return mysqli|PDO Database connection
 */
function get_database_connection() {
    // Use the database utilities that are working in the application
    require_once dirname(__FILE__) . '/../utils/database.php';
    
    try {
        // Use the working PDO connection
        return getDbConnection();
    } catch (Exception $e) {
        error_log("Database connection failed in jwt-auth.php: " . $e->getMessage());
        json_response([
            'success' => false,
            'message' => 'Database connection error'
        ], 500);
        exit;
    }
} 