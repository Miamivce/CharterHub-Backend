<?php
/**
 * CharterHub Authentication Check API Endpoint
 * 
 * This file validates access tokens and checks user roles.
 * Part of the JWT Authentication System Refactoring.
 */

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Include the global CORS handler
require_once dirname(__FILE__) . '/global-cors.php';
apply_global_cors(['GET', 'POST', 'OPTIONS']);

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

// Allow GET and POST requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Options requests should be handled by apply_global_cors
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get required role from query parameters or POST data
$required_role = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['role'])) {
    $required_role = $_GET['role'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if ($data && isset($data['role'])) {
        $required_role = $data['role'];
    }
}

// Get the access token from the request
$token = extract_token();

if (!$token) {
    log_auth_action('auth_check_failed', 0, 'No token provided');
    error_response('Authentication required', 401, 'missing_token');
}

// Validate the token
$payload = validate_token($token);

if (!$payload) {
    log_auth_action('auth_check_failed', 0, 'Invalid token');
    error_response('Invalid token', 401, 'invalid_token');
}

try {
    // Get user data from database using the database abstraction layer
    $user = fetchRow(
        "SELECT id, email, first_name, last_name, role, token_version FROM wp_charterhub_users WHERE id = ?",
        [$payload->sub]
    );
    
    if (!$user) {
        log_auth_action('auth_check_failed', $payload->sub, 'User not found');
        error_response('User not found', 401, 'user_not_found');
    }
    
    // Verify token version matches
    if (isset($payload->tvr) && $user['token_version'] != $payload->tvr) {
        log_auth_action('auth_check_failed', $payload->sub, 'Token version mismatch', [
            'token_version' => $payload->tvr,
            'current_version' => $user['token_version']
        ]);
        error_response('Token has been invalidated. Please login again.', 401, 'token_invalidated');
    }
    
    // Check role if required
    if ($required_role && $user['role'] !== $required_role) {
        log_auth_action('auth_check_failed', $user['id'], 'Insufficient permissions', [
            'required_role' => $required_role,
            'user_role' => $user['role']
        ]);
        error_response('Insufficient permissions', 403, 'insufficient_permissions');
    }
    
    // Prepare user data to return
    $user_data = [
        'id' => intval($user['id']),
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'role' => $user['role']
    ];
    
    // Log successful authentication check
    log_auth_action('auth_check_success', $user['id'], 'Authentication check successful', [
        'required_role' => $required_role
    ]);
    
    // Return success response
    json_response([
        'success' => true,
        'authenticated' => true,
        'user' => $user_data,
        'role_check' => $required_role ? [
            'required_role' => $required_role,
            'has_role' => true
        ] : null
    ]);
} catch (Exception $e) {
    log_auth_action('auth_check_error', $payload->sub ?? 0, 'Error during authentication check: ' . $e->getMessage());
    error_response('Authentication error. Please try again later.', 500, 'server_error');
} 