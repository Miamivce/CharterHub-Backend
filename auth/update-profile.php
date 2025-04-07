<?php
/**
 * CharterHub Update Profile API Endpoint
 * 
 * This file handles secure profile updates and regenerates tokens for email changes.
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
 * Sanitize input data
 * 
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Options requests should be handled by apply_global_cors
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get authenticated user
$user = get_authenticated_user();

// If get_authenticated_user returns false, it will have already sent an error response
// So we don't need to handle that case here

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Debug logging
error_log("DEBUG: Profile update request received. JSON: " . $json);

// Validate input
if (!$data) {
    error_log("DEBUG: Invalid JSON data in profile update");
    error_response('Invalid JSON data', 400, 'invalid_json');
}

// Extract and sanitize fields
$first_name = isset($data['first_name']) ? sanitize_input($data['first_name']) : null;
$last_name = isset($data['last_name']) ? sanitize_input($data['last_name']) : null;
$email = isset($data['email']) ? sanitize_input($data['email']) : null;
$phone_number = isset($data['phone_number']) ? sanitize_input($data['phone_number']) : null;
$company = isset($data['company']) ? sanitize_input($data['company']) : null;

// Validate email if provided
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_response('Invalid email format', 400, 'invalid_email');
}

// Check if email is already in use by another user
if ($email !== null && strtolower($email) !== strtolower($user['email'])) {
    // Using database abstraction layer
    $existingUsers = fetchAll(
        "SELECT id FROM wp_charterhub_users WHERE LOWER(email) = LOWER(?) AND id != ?",
        [strtolower($email), $user['id']]
    );
    
    if (count($existingUsers) > 0) {
        log_auth_action('profile_update_failed', $user['id'], 'Email already in use', ['email' => $email]);
        error_response('Email address is already in use', 409, 'email_in_use');
    }
}

// Start transaction
beginTransaction();

try {
    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];
    
    if ($first_name !== null) {
        $update_fields[] = "first_name = ?";
        $params[] = $first_name;
    }
    
    if ($last_name !== null) {
        $update_fields[] = "last_name = ?";
        $params[] = $last_name;
    }
    
    if ($email !== null) {
        $update_fields[] = "email = ?";
        $params[] = $email;
    }
    
    if ($phone_number !== null) {
        $update_fields[] = "phone_number = ?";
        $params[] = $phone_number;
    }
    
    if ($company !== null) {
        $update_fields[] = "company = ?";
        $params[] = $company;
    }
    
    error_log("DEBUG: Profile update fields: " . json_encode($update_fields));
    error_log("DEBUG: Profile update params: " . json_encode($params));
    
    // If no fields to update, return success
    if (empty($update_fields)) {
        rollbackTransaction();
        json_response([
            'success' => true,
            'message' => 'No changes to update',
            'user' => $user
        ]);
    }
    
    // Add user ID to params 
    $params[] = $user['id'];
    
    // Execute update query using the database abstraction layer
    $query = "UPDATE wp_charterhub_users SET " . implode(", ", $update_fields) . " WHERE id = ?";
    error_log("DEBUG: Profile update SQL query: " . $query);
    error_log("DEBUG: Profile update params with user ID: " . json_encode($params));
    
    try {
        $updated = executeUpdate($query, $params);
        error_log("DEBUG: Profile update query executed. Result: " . ($updated ? "success" : "failure"));
    } catch (Exception $e) {
        error_log("ERROR: Profile update query failed: " . $e->getMessage());
        throw $e;
    }
    
    // Check if email was changed
    $email_changed = $email !== null && strtolower($email) !== strtolower($user['email']);
    error_log("DEBUG: Email changed: " . ($email_changed ? "yes" : "no"));
    
    // If email changed, increment token version to invalidate all tokens
    if ($email_changed) {
        executeUpdate(
            "UPDATE wp_charterhub_users SET token_version = token_version + 1 WHERE id = ?",
            [$user['id']]
        );
        
        // Blacklist all user tokens
        blacklist_all_user_tokens($user['id']);
    }
    
    // Get updated user data
    $updated_user = fetchRow(
        "SELECT id, email, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE id = ?",
        [$user['id']]
    );
    
    // Commit transaction
    commitTransaction();
    
    // Generate new tokens if email changed
    $new_tokens = null;
    
    if ($email_changed) {
        // Generate new access and refresh tokens with updated email
        error_log("DEBUG: Generating new tokens due to email change");
        $access_token = generate_access_token(
            $updated_user['id'],
            $updated_user['email'],
            $updated_user['role'],
            $updated_user['token_version']
        );
        
        $refresh_token = generate_refresh_token(
            $updated_user['id'],
            $updated_user['email'],
            $updated_user['role'],
            $updated_user['token_version']
        );
        
        error_log("DEBUG: Access token generated: " . ($access_token ? "success" : "failure"));
        error_log("DEBUG: Refresh token generated: " . (is_array($refresh_token) ? "success" : "failure"));
        
        // Store refresh token in HTTP-only cookie
        try {
            set_refresh_token_cookie($refresh_token, time() + (86400 * 30)); // 30 days
            error_log("DEBUG: Refresh token cookie set successfully");
        } catch (Exception $e) {
            error_log("ERROR: Failed to set refresh token cookie: " . $e->getMessage());
        }
        
        $new_tokens = [
            'access_token' => $access_token,
            'token_type' => 'Bearer'
        ];
    }
    
    // Return success response
    json_response([
        'success' => true,
        'message' => 'Profile updated successfully',
        'user' => $updated_user,
        'tokens' => $new_tokens
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    rollbackTransaction();
    
    log_auth_action('profile_update_failed', $user['id'], 'Database error: ' . $e->getMessage());
    error_response('Failed to update profile: ' . $e->getMessage(), 500, 'database_error');
} 