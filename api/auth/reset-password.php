<?php
/**
 * Password Reset Endpoint
 * 
 * This endpoint handles password reset requests and token validation.
 */

// Include configuration
require_once 'config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (!$data) {
    error_response('Invalid JSON data');
}

// Check if this is a reset request or a token validation
if (isset($data['email']) && !isset($data['token']) && !isset($data['password'])) {
    // This is a reset request (step 1)
    handleResetRequest($data);
} elseif (isset($data['token']) && !isset($data['password'])) {
    // This is a token validation (step 2)
    validateResetToken($data);
} elseif (isset($data['token']) && isset($data['password'])) {
    // This is a password update (step 3)
    updatePassword($data);
} else {
    error_response('Invalid request parameters');
}

/**
 * Handle a password reset request
 */
function handleResetRequest($data) {
    $email = isset($data['email']) ? sanitize_input($data['email']) : '';
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_response('Invalid email format');
    }
    
    try {
        global $pdo;
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Find the user with this email
        $stmt = $pdo->prepare('SELECT id, email, verified FROM wp_charterhub_users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        // Check if user exists and is verified
        if (!$user) {
            // Don't reveal if the email exists or not for security
            $pdo->rollBack();
            json_response([
                'success' => true,
                'message' => 'If your email is registered, you will receive password reset instructions.'
            ]);
            return;
        }
        
        if (!$user['verified']) {
            $pdo->rollBack();
            error_response('Please verify your email address first');
        }
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Update user with reset token
        $stmt = $pdo->prepare('
            UPDATE wp_charterhub_users 
            SET reset_password_token = ?, reset_password_expires = ?, updated_at = NOW() 
            WHERE id = ?
        ');
        $stmt->execute([$token, $expires, $user['id']]);
        
        // Commit transaction
        $pdo->commit();
        
        // TODO: Send reset email with token
        // This is a placeholder for email sending functionality
        $resetUrl = "http://localhost:3000/reset-password?token=$token";
        
        // Return success response
        json_response([
            'success' => true,
            'message' => 'If your email is registered, you will receive password reset instructions.',
            'reset_url' => $resetUrl // In a production environment, this would not be returned
        ]);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        error_log('Password reset request error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
}

/**
 * Validate a password reset token
 */
function validateResetToken($data) {
    $token = isset($data['token']) ? sanitize_input($data['token']) : '';
    
    if (empty($token)) {
        error_response('Token is required');
    }
    
    try {
        global $pdo;
        
        // Find the user with this reset token
        $stmt = $pdo->prepare('
            SELECT id, email, reset_password_expires 
            FROM wp_charterhub_users 
            WHERE reset_password_token = ?
        ');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        // Check if user exists
        if (!$user) {
            error_response('Invalid reset token', 400);
        }
        
        // Check if token has expired
        if ($user['reset_password_expires'] && strtotime($user['reset_password_expires']) < time()) {
            error_response('Reset token has expired', 400);
        }
        
        // Return success response
        json_response([
            'success' => true,
            'message' => 'Token is valid',
            'email' => $user['email']
        ]);
        
    } catch (PDOException $e) {
        error_log('Token validation error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
}

/**
 * Update password with a valid reset token
 */
function updatePassword($data) {
    $token = isset($data['token']) ? sanitize_input($data['token']) : '';
    $password = isset($data['password']) ? $data['password'] : '';
    
    if (empty($token)) {
        error_response('Token is required');
    }
    
    if (empty($password)) {
        error_response('Password is required');
    }
    
    // Validate password strength
    if (strlen($password) < 8) {
        error_response('Password must be at least 8 characters');
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        error_response('Password must contain at least one uppercase letter');
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        error_response('Password must contain at least one lowercase letter');
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        error_response('Password must contain at least one number');
    }
    
    try {
        global $pdo;
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Find the user with this reset token
        $stmt = $pdo->prepare('
            SELECT id, email, reset_password_expires 
            FROM wp_charterhub_users 
            WHERE reset_password_token = ?
        ');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        // Check if user exists
        if (!$user) {
            $pdo->rollBack();
            error_response('Invalid reset token', 400);
        }
        
        // Check if token has expired
        if ($user['reset_password_expires'] && strtotime($user['reset_password_expires']) < time()) {
            $pdo->rollBack();
            error_response('Reset token has expired', 400);
        }
        
        // Hash the new password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Update user's password and clear reset token
        $stmt = $pdo->prepare('
            UPDATE wp_charterhub_users 
            SET password = ?, reset_password_token = NULL, reset_password_expires = NULL, updated_at = NOW() 
            WHERE id = ?
        ');
        $stmt->execute([$passwordHash, $user['id']]);
        
        // Commit transaction
        $pdo->commit();
        
        // Return success response
        json_response([
            'success' => true,
            'message' => 'Password updated successfully. You can now log in with your new password.'
        ]);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        error_log('Password update error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
} 