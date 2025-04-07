<?php
/**
 * CharterHub Login API Endpoint
 * 
 * This file handles user authentication and generates JWT tokens.
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
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

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

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (!$data || !isset($data['email']) || !isset($data['password'])) {
    error_response('Email and password are required');
}

// Normalize email by trimming and converting to lowercase
$email = strtolower(trim(sanitize_input($data['email'])));
$password = $data['password']; // Don't sanitize password

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_response('Invalid email format');
}

try {
    // Log login attempt
    log_auth_action('login_attempt', 0, 'Login attempt', ['email' => $email]);
    
    // Try to find user by email using the database abstraction layer
    $user = fetchRow(
        'SELECT id, email, password, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE email = ?',
        [$email]
    );
    
    // If not found, try case-insensitive match
    if (!$user) {
        $user = fetchRow(
            'SELECT id, email, password, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE LOWER(email) = LOWER(?)',
            [$email]
        );
    }
    
    // Check if user exists
    if (!$user) {
        log_auth_action('login_failed', 0, 'User not found', ['email' => $email]);
        error_response('Invalid credentials', 401, 'invalid_credentials');
    }
    
    // Check if user is verified - strengthened verification check
    if (!$user['verified']) {
        // Log the verification status for debugging
        error_log("LOGIN.PHP: Login attempt for unverified account: {$email}");
        
        // Check if they have a verification token, if not, generate a new one
        $verification_token = !empty($user['verification_token']) ? 
            $user['verification_token'] : bin2hex(random_bytes(32));
        
        // If we had to generate a new token, update the user record
        if ($verification_token !== $user['verification_token']) {
            try {
                executeUpdate(
                    "UPDATE {$db_config['table_prefix']}charterhub_users 
                    SET verification_token = ?, updated_at = NOW() 
                    WHERE id = ?",
                    [$verification_token, $user['id']]
                );
                error_log("LOGIN.PHP: Generated new verification token for user: {$user['id']}");
            } catch (Exception $e) {
                error_log("LOGIN.PHP ERROR: Failed to update verification token: " . $e->getMessage());
                // Continue with the existing token if update fails
            }
        }
        
        // Generate verification URL
        $verification_url = "/verify-email?token=" . $verification_token;
        
        // Include verification details in the error response
        log_auth_action('login_failed', $user['id'], 'Account not verified', ['email' => $email]);
        send_json_response([
            'success' => false,
            'error' => 'account_not_verified',
            'message' => 'Account not verified. Please check your email for verification instructions.',
            'verification_required' => true,
            'verification_url' => $verification_url // Only sent in development mode
        ], 401);
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        log_auth_action('login_failed', $user['id'], 'Invalid password', ['email' => $email]);
        error_response('Invalid credentials', 401, 'invalid_credentials');
    }
    
    // If email case is different than what's stored but same when normalized, update the stored email
    if (strtolower(trim($user['email'])) === strtolower(trim($email)) && $user['email'] !== $email) {
        try {
            executeUpdate(
                'UPDATE wp_charterhub_users SET email = ? WHERE id = ?',
                [$email, $user['id']]
            );
            
            log_auth_action('email_updated', $user['id'], 'Updated email case', [
                'old_email' => $user['email'],
                'new_email' => $email
            ]);
            
            // Update the user array to use the new email for the rest of this request
            $user['email'] = $email;
        } catch (Exception $updateError) {
            log_auth_action('email_update_failed', $user['id'], 'Failed to update email case: ' . $updateError->getMessage());
            // Non-critical error, continue with login
        }
    }
    
    // Update last login time
    executeUpdate(
        'UPDATE wp_charterhub_users SET last_login = NOW() WHERE id = ?',
        [$user['id']]
    );
    
    // Generate tokens
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
        log_auth_action('token_generation_failed', $user['id'], 'Failed to generate tokens');
        error_response('Authentication error. Please try again later.', 500, 'token_generation_failed');
    }
    
    // Set refresh token as HTTP-only cookie
    set_refresh_token_cookie($refresh_token, time() + (86400 * 30)); // 30 days
    
    // Prepare user data to return
    $user_data = [
        'id' => intval($user['id']),
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'phone_number' => $user['phone_number'] ?? '',
        'company' => $user['company'] ?? '',
        'role' => $user['role'],
        'verified' => (bool)$user['verified']
    ];
    
    // Log successful login
    log_auth_action('login_success', $user['id'], 'Login successful');
    
    // Prepare response
    global $jwt_expiration;
    
    // If $jwt_expiration is not defined or null, set a default value (30 minutes)
    if (!isset($jwt_expiration) || $jwt_expiration === null) {
        $jwt_expiration = 1800;
    }
    
    json_response([
        'success' => true,
        'message' => 'Login successful',
        'user' => $user_data,
        'access_token' => $access_token,
        'expires_in' => $jwt_expiration,
        'token_type' => 'Bearer'
    ]);
    
} catch (Exception $e) {
    log_auth_action('login_error', 0, 'Login error: ' . $e->getMessage());
    error_response('Authentication error. Please try again later.', 500, 'server_error');
}