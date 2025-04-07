<?php
/**
 * JWT Token Validation Middleware
 * 
 * This file provides functions to validate JWT tokens and enforce role-based access control.
 */

// Include configuration
require_once 'config.php';

/**
 * Validates a JWT token and returns the decoded payload if valid
 * 
 * @param string $token The JWT token to validate
 * @return array|false The decoded payload if valid, false otherwise
 */
function validate_token($token) {
    try {
        // Debug log token
        error_log("Validating token: " . substr($token, 0, 20) . "...");
        
        // Decode the token
        $payload = jwt_decode($token, JWT_SECRET);
        
        if (!$payload) {
            error_log("Token decode failed");
            return false;
        }
        
        error_log("Token payload: " . json_encode($payload));
        
        // Check if token has expired
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            error_log("Token expired: " . ($payload['exp'] ?? 'no exp') . " < " . time());
            return false;
        }
        
        // Verify issuer and audience if present
        if (isset($payload['iss']) && $payload['iss'] !== JWT_ISSUER) {
            error_log("Token issuer mismatch: " . $payload['iss'] . " !== " . JWT_ISSUER);
            return false;
        }
        
        if (isset($payload['aud']) && $payload['aud'] !== JWT_AUDIENCE) {
            error_log("Token audience mismatch: " . $payload['aud'] . " !== " . JWT_AUDIENCE);
            return false;
        }
        
        // Verify token in database if the function exists
        if (function_exists('verify_token_in_database')) {
            // Include token storage helper if not already included
            if (!function_exists('verify_token_in_database')) {
                error_log("Including token-storage.php for database verification");
                require_once dirname(dirname(__DIR__)) . '/auth/token-storage.php';
            }
            
            if (!verify_token_in_database($token)) {
                error_log("Token not found in database or revoked");
                return false;
            }
            error_log("Token verified in database");
        } else {
            error_log("Token database verification skipped - function not available");
        }
        
        // Token is valid
        error_log("Token is valid");
        return $payload;
    } catch (Exception $e) {
        error_log('Token validation error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Checks if the current request has a valid token and the user has the required role
 * 
 * @param array $allowed_roles Array of roles allowed to access the resource
 * @return array User data if authorized
 */
function require_auth($allowed_roles = []) {
    error_log("require_auth: Starting authentication check");
    
    // Get the authorization header
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    error_log("require_auth: Authorization header: " . substr($auth_header, 0, 20) . "...");
    
    // Check if Authorization header exists and has a Bearer token
    if (!$auth_header || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        error_log("require_auth: No Bearer token found in Authorization header");
        error_response('No token provided', 401);
    }
    
    // Extract token
    $token = $matches[1];
    error_log("require_auth: Extracted token: " . substr($token, 0, 20) . "...");
    
    // Include token storage helper if not already included
    if (!function_exists('verify_token_in_database')) {
        $token_storage_path = dirname(dirname(__DIR__)) . '/auth/token-storage.php';
        if (file_exists($token_storage_path)) {
            error_log("require_auth: Including token storage helper from: " . $token_storage_path);
            
            // Define CHARTERHUB_LOADED constant if not already defined
            if (!defined('CHARTERHUB_LOADED')) {
                define('CHARTERHUB_LOADED', true);
            }
            
            require_once $token_storage_path;
        } else {
            error_log("require_auth: Token storage helper not found at: " . $token_storage_path);
        }
    }
    
    // Check token in database first if the function exists
    if (function_exists('verify_token_in_database')) {
        error_log("require_auth: Checking token in database");
        if (!verify_token_in_database($token)) {
            error_log("require_auth: Token not found in database or revoked");
            error_response('Invalid or expired token', 401);
        }
        error_log("require_auth: Token verified in database");
    } else {
        error_log("require_auth: Database verification skipped - function not available");
    }
    
    // Validate token
    $payload = validate_token($token);
    if (!$payload) {
        error_log("require_auth: Token validation failed");
        error_response('Invalid or expired token', 401);
    }
    
    error_log("require_auth: Token validated successfully");
    
    // Check if the token has a subject (user ID)
    if (!isset($payload['sub'])) {
        error_log("require_auth: Token missing 'sub' claim");
        error_response('Invalid token format', 401);
    }
    
    // If roles are specified, check if the user has the required role
    if (!empty($allowed_roles)) {
        if (!isset($payload['role']) || !in_array($payload['role'], $allowed_roles)) {
            error_log("require_auth: Insufficient permissions. User role: " . ($payload['role'] ?? 'none') . ", Required roles: " . implode(', ', $allowed_roles));
            error_response('Insufficient permissions', 403);
        }
    }
    
    // Get user data from database
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, role FROM wp_charterhub_users WHERE id = ?');
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();
    
    // Check if user exists
    if (!$user) {
        error_log("require_auth: User not found in database. User ID: " . $payload['sub']);
        error_response('User not found', 401);
    }
    
    error_log("require_auth: Authentication successful for user: " . $user['email']);
    
    // Add user data to the request
    return $user;
}

/**
 * Checks if the user is an admin
 * 
 * @return array User data if authorized
 */
function require_admin() {
    return require_auth(['admin']);
}

/**
 * Checks if the user is a client
 * 
 * @return array User data if authorized
 */
function require_client() {
    return require_auth(['client']);
}

/**
 * Checks if the user has permission to access their own data
 * 
 * @param int $resource_user_id ID of the user who owns the resource
 * @return array User data if authorized
 */
function require_self_or_admin($resource_user_id) {
    $user = require_auth(['admin', 'client']);
    
    // Admins can access any user's data
    if ($user['role'] === 'admin') {
        return $user;
    }
    
    // Clients can only access their own data
    if ($user['id'] != $resource_user_id) {
        error_response('You can only access your own data', 403);
    }
    
    return $user;
} 