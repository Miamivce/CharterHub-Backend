<?php
/**
 * CharterHub Logout API Endpoint
 * 
 * This file handles user logout by blacklisting tokens and clearing cookies.
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

/**
 * Invalidate all tokens for a user by incrementing their token_version
 * 
 * @param int $user_id The user ID
 * @return bool Whether the operation was successful
 */
function invalidate_all_user_tokens($user_id) {
    try {
        // Increment token_version to invalidate all existing tokens
        $updated = executeUpdate(
            "UPDATE wp_charterhub_users SET token_version = token_version + 1 WHERE id = ?",
            [$user_id]
        );
        
        // Blacklist all tokens for this user
        blacklist_all_user_tokens($user_id, 'logout');
        
        return $updated > 0;
    } catch (Exception $e) {
        error_log("Failed to invalidate user tokens: " . $e->getMessage());
        return false;
    }
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Options requests should be handled by apply_global_cors
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get the access token from the request
$token = extract_token();
$refresh_token = get_refresh_token_from_cookie();

// If no tokens provided, still clear cookies but return a warning
if (!$token && !$refresh_token) {
    // Clear refresh token cookie
    clear_refresh_token_cookie();
    
    json_response([
        'success' => true,
        'message' => 'Logged out successfully, but no tokens were provided',
        'warning' => 'No tokens were provided for blacklisting'
    ]);
}

try {
    // Validate the access token if provided
    $user_id = 0;
    
    if ($token) {
        $payload = validate_token($token, false); // Don't check blacklist during logout
        
        if ($payload) {
            $user_id = $payload->sub;
            
            // Blacklist the access token
            blacklist_token($token, 'logout');
            log_auth_action('logout', $user_id, 'Access token blacklisted');
        }
    }
    
    // Handle refresh token if provided
    if ($refresh_token) {
        $refresh_payload = validate_token($refresh_token, false); // Don't check blacklist during logout
        
        if ($refresh_payload) {
            // If we didn't get a user_id from the access token, use the one from the refresh token
            if (!$user_id && isset($refresh_payload->sub)) {
                $user_id = $refresh_payload->sub;
            }
            
            // Blacklist the refresh token
            blacklist_token($refresh_token, 'logout');
            log_auth_action('logout', $user_id, 'Refresh token blacklisted');
        }
    }
    
    // If we have a user_id, invalidate all tokens for additional security
    if ($user_id) {
        $success = invalidate_all_user_tokens($user_id);
        if ($success) {
            log_auth_action('logout', $user_id, 'All user tokens invalidated');
        }
    }
    
    // Clear refresh token cookie
    clear_refresh_token_cookie();
    
    // Log the logout action
    log_auth_action('logout_success', $user_id, 'User logged out successfully');
    
    // Return success response
    json_response([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
} catch (Exception $e) {
    // Clear refresh token cookie even if there's an error
    clear_refresh_token_cookie();
    
    log_auth_action('logout_error', $user_id, 'Error during logout: ' . $e->getMessage());
    error_response('Error during logout. Your session has been cleared.', 500, 'server_error');
} 