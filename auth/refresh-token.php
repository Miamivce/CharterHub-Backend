<?php
/**
 * CharterHub Refresh Token API Endpoint
 * 
 * This file handles refresh token validation and token rotation.
 * Part of the JWT Authentication System Refactoring.
 */

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Include the global CORS handler
require_once dirname(__FILE__) . '/global-cors.php';
apply_global_cors(['POST', 'OPTIONS']);

// Include required files
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/jwt-core.php';
require_once dirname(__FILE__) . '/token-blacklist.php';
require_once dirname(__FILE__) . '/../utils/database.php';  // Include the database abstraction layer

header('Content-Type: application/json');

// Define helper functions
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function error_response($message, $status = 400, $code = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    $response = ['error' => true, 'message' => $message];
    if ($code) {
        $response['code'] = $code;
    }
    echo json_encode($response);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Options requests should be handled by apply_global_cors
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get refresh token from HTTP-only cookie
$refresh_token = get_refresh_token_from_cookie();

if (!$refresh_token) {
    log_auth_action('refresh_failed', 0, 'No refresh token provided');
    error_response('No refresh token provided', 401, 'missing_token');
}

// Validate the refresh token
$payload = validate_token($refresh_token);

if (!$payload) {
    log_auth_action('refresh_failed', 0, 'Invalid refresh token');
    error_response('Invalid refresh token', 401, 'invalid_token');
}

// Verify this is actually a refresh token
if (!isset($payload->type) || $payload->type !== 'refresh') {
    log_auth_action('refresh_failed', $payload->sub ?? 0, 'Not a refresh token');
    error_response('Invalid token type', 401, 'invalid_token_type');
}

try {
    // Get user data from database using the database abstraction layer
    $user = fetchRow(
        "SELECT id, email, first_name, last_name, role, token_version FROM wp_charterhub_users WHERE id = ?",
        [$payload->sub]
    );
    
    if (!$user) {
        log_auth_action('refresh_failed', $payload->sub, 'User not found');
        error_response('User not found', 401, 'user_not_found');
    }
    
    // Verify token version matches
    if ($user['token_version'] != $payload->tvr) {
        log_auth_action('refresh_failed', $payload->sub, 'Token version mismatch', [
            'token_version' => $payload->tvr,
            'current_version' => $user['token_version']
        ]);
        error_response('Token has been invalidated', 401, 'token_invalidated');
    }
    
    // Blacklist the current refresh token (token rotation)
    blacklist_token($refresh_token, 'token_rotation');
    log_auth_action('token_rotated', $user['id'], 'Refresh token rotated');
    
    // Generate new tokens
    $access_token = generate_access_token(
        $user['id'],
        $user['email'],
        $user['role'],
        $user['token_version']
    );
    
    $refresh_token = generate_refresh_token(
        $user['id'],
        $user['email'],
        $user['role'],
        $user['token_version']
    );
    
    if (!$access_token || !$refresh_token) {
        log_auth_action('token_generation_failed', $user['id'], 'Failed to generate new tokens during refresh');
        error_response('Failed to generate new tokens', 500, 'token_generation_failed');
    }
    
    // Set new refresh token as HTTP-only cookie
    set_refresh_token_cookie($refresh_token, time() + (86400 * 30)); // 30 days
    
    // Prepare user data to return
    $user_data = [
        'id' => intval($user['id']),
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'role' => $user['role']
    ];
    
    // Log successful token refresh
    log_auth_action('refresh_success', $user['id'], 'Token refreshed successfully');
    
    // Return new access token
    global $jwt_expiration;
    
    // If $jwt_expiration is not defined or null, set a default value (30 minutes)
    if (!isset($jwt_expiration) || $jwt_expiration === null) {
        $jwt_expiration = 1800;
    }
    
    json_response([
        'success' => true,
        'message' => 'Token refreshed successfully',
        'user' => $user_data,
        'access_token' => $access_token,
        'expires_in' => $jwt_expiration,
        'token_type' => 'Bearer'
    ]);
} catch (Exception $e) {
    log_auth_action('refresh_error', $payload->sub ?? 0, 'Refresh error: ' . $e->getMessage());
    error_response('Authentication error. Please try again later.', 500, 'server_error');
} 