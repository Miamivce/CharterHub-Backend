<?php
/**
 * CharterHub Client Login API Endpoint
 * 
 * This file handles client user authentication and generates JWT tokens.
 * It extends the standard login.php by adding a role check.
 */

// Set error display settings (ADDED)
ini_set('display_errors', 0);
error_reporting(E_ERROR);

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

// Set content-type early to ensure it's applied even if errors occur (MOVED UP)
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
    log_auth_action('client_login_attempt', 0, 'Client login attempt', ['email' => $email]);
    
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
        log_auth_action('client_login_failed', 0, 'User not found', ['email' => $email]);
        error_response('Invalid credentials', 401, 'invalid_credentials');
    }
    
    // CLIENT ONLY: Check if user has client role
    if ($user['role'] !== 'client') {
        log_auth_action('client_login_failed', $user['id'], 'Non-client user attempted client login', [
            'email' => $email,
            'role' => $user['role']
        ]);
        
        // Return the same error message as invalid credentials to avoid role enumeration
        error_response('Invalid credentials', 401, 'invalid_credentials');
    }
    
    // Check if user is verified
    if (!$user['verified']) {
        log_auth_action('client_login_failed', $user['id'], 'Account not verified', ['email' => $email]);
        error_response('Account not verified. Please check your email for verification instructions.', 401, 'account_not_verified');
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        log_auth_action('client_login_failed', $user['id'], 'Invalid password', ['email' => $email]);
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
    try {
        error_log("CLIENT-LOGIN: Generating access token for user ID: {$user['id']}");
        $access_token = generate_access_token(
            $user['id'],
            $user['email'],
            $user['role'],
            $user['token_version']
        );
        
        if (!$access_token) {
            throw new Exception("Failed to generate access token");
        }
        
        // First try to generate refresh token with database operations
        error_log("CLIENT-LOGIN: Attempting to generate refresh token with database operations");
        $refresh_token = generate_refresh_token(
            $user['id'],
            $user['email'],
            $user['role'],
            $user['token_version']
        );
        
        // If refresh token generation fails, try with a simplified approach
        if (!$refresh_token) {
            error_log("CLIENT-LOGIN: Regular refresh token generation failed, creating simple token");
            
            // Create a simple refresh token directly
            $refresh_secret = getenv('JWT_REFRESH_SECRET') ?: $jwt_secret;
            $issued_at = time();
            $expiration = $issued_at + 604800; // 7 days
            $token_id = bin2hex(random_bytes(16));
            
            $payload = [
                'iss' => 'charterhub',
                'aud' => 'charterhub-refresh',
                'iat' => $issued_at,
                'exp' => $expiration,
                'jti' => $token_id,
                'sub' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'token_version' => $user['token_version'],
                'type' => 'refresh',
                'simplified' => true
            ];
            
            try {
                $jwt = \Firebase\JWT\JWT::encode($payload, $refresh_secret, 'HS256');
                $refresh_token = [
                    'token' => $jwt,
                    'token_id' => $token_id,
                    'expires' => $expiration
                ];
                error_log("CLIENT-LOGIN: Created simplified refresh token successfully");
            } catch (Exception $e) {
                error_log("CLIENT-LOGIN: Failed to create simplified token: " . $e->getMessage());
                throw $e; // Re-throw for outer try/catch
            }
        }
        
        error_log("CLIENT-LOGIN: Successfully generated both tokens");
    } catch (Exception $tokenError) {
        log_auth_action('token_generation_failed', $user['id'], 'Failed to generate tokens: ' . $tokenError->getMessage());
        error_log("CLIENT-LOGIN ERROR: Token generation failed: " . $tokenError->getMessage());
        error_response('Authentication error. Please try again later.', 500, 'token_generation_failed');
    }
    
    if (!$access_token || !$refresh_token) {
        log_auth_action('token_generation_failed', $user['id'], 'Failed to generate tokens');
        error_log("CLIENT-LOGIN ERROR: Access token or refresh token is empty after generation");
        error_response('Authentication error. Please try again later.', 500, 'token_generation_failed');
    }
    
    // Set refresh token as HTTP-only cookie
    error_log("CLIENT-LOGIN: Setting refresh token cookie with expiry in 30 days");
    set_refresh_token_cookie($refresh_token['token'], time() + (86400 * 30)); // 30 days
    
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
    log_auth_action('client_login_success', $user['id'], 'Client login successful');
    
    // Prepare response to include access_token
    global $jwt_expiration;
    
    // If $jwt_expiration is not defined or null, set a default value (30 minutes)
    if (!isset($jwt_expiration) || $jwt_expiration === null) {
        $jwt_expiration = 1800;
    }
    
    error_log("CLIENT-LOGIN: Sending successful response with token for user: {$user['email']}");
    json_response([
        'success' => true,
        'message' => 'Login successful',
        'user' => $user_data,
        'access_token' => $access_token,
        'expires_in' => $jwt_expiration,
        'token_type' => 'Bearer'
    ]);
    
} catch (Exception $e) {
    log_auth_action('client_login_error', 0, 'Client login error: ' . $e->getMessage());
    error_response('Authentication error. Please try again later.', 500, 'server_error');
}
?> 