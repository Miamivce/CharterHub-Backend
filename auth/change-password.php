<?php
/**
 * CharterHub Change Password API Endpoint
 * 
 * This file handles secure password changes and invalidates all refresh tokens.
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
 * Validate password strength
 * 
 * @param string $password The password to validate
 * @return array Validation result with 'valid' and 'message' keys
 */
function validate_password_strength($password) {
    global $password_min_length, $password_require_special, $password_require_uppercase, $password_require_number;
    
    $result = [
        'valid' => true,
        'message' => 'Password is valid'
    ];
    
    // Check length
    if (strlen($password) < $password_min_length) {
        $result['valid'] = false;
        $result['message'] = "Password must be at least $password_min_length characters long";
        return $result;
    }
    
    // Check for uppercase letter
    if ($password_require_uppercase && !preg_match('/[A-Z]/', $password)) {
        $result['valid'] = false;
        $result['message'] = 'Password must contain at least one uppercase letter';
        return $result;
    }
    
    // Check for number
    if ($password_require_number && !preg_match('/[0-9]/', $password)) {
        $result['valid'] = false;
        $result['message'] = 'Password must contain at least one number';
        return $result;
    }
    
    // Check for special character
    if ($password_require_special && !preg_match('/[^a-zA-Z0-9]/', $password)) {
        $result['valid'] = false;
        $result['message'] = 'Password must contain at least one special character';
        return $result;
    }
    
    return $result;
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

// Validate input
if (!$data || !isset($data['current_password']) || !isset($data['new_password'])) {
    error_response('Current password and new password are required', 400, 'missing_fields');
}

$current_password = $data['current_password'];
$new_password = $data['new_password'];

// Get user's current password from database
$conn = get_db_connection_from_config();
$stmt = $conn->prepare("SELECT password FROM wp_charterhub_users WHERE id = ?");
$stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$stmt->execute();
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_data) {
    try {
        log_auth_action('password_change_failed', $user['id'], 'User not found in database');
    } catch (Exception $logEx) {
        error_log("WARNING: Could not log authentication failure: " . $logEx->getMessage());
    }
    error_response('User not found', 404, 'user_not_found');
}

// Verify current password
if (!password_verify($current_password, $user_data['password'])) {
    try {
        log_auth_action('password_change_failed', $user['id'], 'Current password is incorrect');
    } catch (Exception $logEx) {
        error_log("WARNING: Could not log authentication failure: " . $logEx->getMessage());
    }
    error_response('Current password is incorrect', 401, 'invalid_password');
}

// Validate new password strength
$password_validation = validate_password_strength($new_password);
if (!$password_validation['valid']) {
    try {
        log_auth_action('password_change_failed', $user['id'], 'Password strength validation failed', [
            'reason' => $password_validation['message']
        ]);
    } catch (Exception $logEx) {
        error_log("WARNING: Could not log password validation failure: " . $logEx->getMessage());
    }
    error_response($password_validation['message'], 400, 'weak_password');
}

// Hash new password
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

// Start transaction
$conn->beginTransaction();

try {
    // Add debug logging
    error_log("BEGIN: Password change transaction for user: " . $user['id']);

    // Update password
    $stmt = $conn->prepare("UPDATE wp_charterhub_users SET password = ? WHERE id = ?");
    $stmt->bindValue(1, $hashed_password, PDO::PARAM_STR);
    $stmt->bindValue(2, $user['id'], PDO::PARAM_INT);
    $stmt->execute();
    error_log("SUCCESS: Password updated in database for user: " . $user['id']);

    // Increment token version to invalidate all refresh tokens
    $stmt = $conn->prepare("UPDATE wp_charterhub_users SET token_version = token_version + 1 WHERE id = ?");
    $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
    $stmt->execute();
    error_log("SUCCESS: Token version incremented for user: " . $user['id']);

    // Get the new token version
    $stmt = $conn->prepare("SELECT token_version FROM wp_charterhub_users WHERE id = ?");
    $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
    $stmt->execute();
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("SUCCESS: New token version retrieved: " . ($user_data['token_version'] ?? 'unknown'));

    // Blacklist all user tokens
    try {
        blacklist_all_user_tokens($user['id']);
        error_log("SUCCESS: All tokens blacklisted for user: " . $user['id']);
    } catch (Exception $blacklistEx) {
        // Log but continue with transaction
        error_log("WARNING: Error in blacklist_all_user_tokens: " . $blacklistEx->getMessage());
        // Don't throw so transaction can continue
    }

    // Log the password change
    try {
        // Check if auth_log table exists
        $checkStmt = $conn->prepare("SHOW TABLES LIKE 'wp_charterhub_auth_log'");
        $checkStmt->execute();
        $logTableExists = $checkStmt->rowCount() > 0;
        
        if ($logTableExists) {
            $stmt = $conn->prepare("INSERT INTO wp_charterhub_auth_log (user_id, action, details) VALUES (?, 'password_change', 'Password changed successfully')");
            $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
            $stmt->execute();
            error_log("SUCCESS: Password change logged for user: " . $user['id']);
        } else {
            error_log("WARNING: Table wp_charterhub_auth_log doesn't exist, skipping action logging");
        }
    } catch (Exception $logEx) {
        // Log error but continue with transaction
        error_log("WARNING: Could not log password change: " . $logEx->getMessage());
        // Don't throw the exception - we still want the password change to succeed
    }

    // Invalidate all existing tokens for this user
    try {
        // Check if table exists before attempting to update
        $checkStmt = $conn->prepare("SHOW TABLES LIKE 'wp_charterhub_tokens'");
        $checkStmt->execute();
        $tableExists = $checkStmt->rowCount() > 0;
        
        if ($tableExists) {
            $stmt = $conn->prepare("UPDATE wp_charterhub_tokens SET is_revoked = 1 WHERE user_id = ? AND token_type = 'refresh'");
            $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
            $stmt->execute();
            error_log("SUCCESS: All refresh tokens invalidated for user: " . $user['id']);
        } else {
            error_log("WARNING: Table wp_charterhub_tokens doesn't exist, skipping token invalidation");
        }
    } catch (Exception $tokenEx) {
        // Log but continue with transaction 
        error_log("WARNING: Error invalidating tokens: " . $tokenEx->getMessage());
        // This table might not exist in some setups, so don't fail the transaction
    }
    
    // Commit transaction
    $conn->commit();
    error_log("SUCCESS: Transaction committed for password change - user: " . $user['id']);
    
    // Generate new tokens with the new token version
    $access_token = generate_access_token(
        $user['id'],
        $user['email'],
        $user['role'],
        $user_data['token_version']
    );
    
    $refresh_token_data = generate_refresh_token(
        $user['id'],
        $user['email'],
        $user['role'],
        $user_data['token_version']
    );
    
    if (!$access_token || !$refresh_token_data) {
        try {
            log_auth_action('token_generation_failed', $user['id'], 'Failed to generate new tokens after password change');
        } catch (Exception $logEx) {
            error_log("WARNING: Could not log token generation failure: " . $logEx->getMessage());
        }
        error_response('Failed to generate new tokens', 500, 'token_generation_failed');
    }
    
    // Set new refresh token as HTTP-only cookie
    try {
        set_refresh_token_cookie($refresh_token_data['token'], $refresh_token_data['expires']);
        error_log("SUCCESS: Refresh token cookie set for user: " . $user['id']);
    } catch (Exception $cookieEx) {
        error_log("WARNING: Error setting refresh token cookie: " . $cookieEx->getMessage());
        // Continue anyway since we return the access token
    }
    
    // Log successful password change
    try {
        log_auth_action('password_changed', $user['id'], 'Password changed successfully');
    } catch (Exception $logEx) {
        error_log("WARNING: Could not log successful password change: " . $logEx->getMessage());
        // Continue anyway - the password was still successfully changed
    }
    
    // Return success response with new tokens
    json_response([
        'success' => true,
        'message' => 'Password changed successfully',
        'access_token' => $access_token,
        'expires_in' => $jwt_expiration,
        'token_type' => 'Bearer'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    try {
        if ($conn->inTransaction()) {
            $conn->rollBack();
            error_log("Transaction rolled back due to error: " . $e->getMessage());
        }
    } catch (Exception $rollbackEx) {
        error_log("Error during rollback: " . $rollbackEx->getMessage());
    }
    
    // Detailed error logging
    error_log("CRITICAL ERROR in change-password.php: " . $e->getMessage());
    error_log("Error type: " . get_class($e));
    error_log("Error trace: " . $e->getTraceAsString());
    
    try {
        log_auth_action('password_change_failed', $user['id'], 'Database error: ' . $e->getMessage());
    } catch (Exception $logEx) {
        error_log("WARNING: Could not log password change failure: " . $logEx->getMessage());
    }
    
    error_response('Failed to change password: ' . $e->getMessage(), 500, 'database_error');
} 