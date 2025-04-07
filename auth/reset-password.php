<?php
/**
 * CharterHub Password Reset API Endpoint
 * 
 * This file handles setting a new password with a valid reset token
 */

// Include configuration
require_once __DIR__ . '/config.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // In production, limit this to your frontend domain
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON request data
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Validate required fields
    $required_fields = ['token', 'newPassword'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    // Validate password strength
    $password_validation = validate_password($data['newPassword']);
    if ($password_validation !== true) {
        throw new Exception($password_validation);
    }
    
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Find user with this reset token
    $stmt = $pdo->prepare("
        SELECT ID, user_email, reset_password_expires
        FROM {$db_config['table_prefix']}users 
        WHERE reset_password_token = :token
    ");
    $stmt->execute(['token' => $data['token']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Invalid or expired reset token');
    }
    
    // Check if token has expired
    if (strtotime($user['reset_password_expires']) < time()) {
        // Log failed reset - token expired
        log_auth_action(
            $user['ID'],
            'password_reset',
            'failure',
            [
                'reason' => 'Token expired',
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ]
        );
        
        throw new Exception('Reset token has expired. Please request a new password reset.');
    }
    
    // Hash new password
    $hashed_password = password_hash($data['newPassword'], PASSWORD_DEFAULT);
    
    // Update user record with new password and clear the token
    $stmt = $pdo->prepare("
        UPDATE {$db_config['table_prefix']}users 
        SET user_pass = :new_password,
            reset_password_token = NULL,
            reset_password_expires = NULL
        WHERE ID = :user_id
    ");
    $stmt->execute([
        'new_password' => $hashed_password,
        'user_id' => $user['ID']
    ]);
    
    // Log successful password reset
    log_auth_action(
        $user['ID'],
        'password_reset',
        'success',
        [
            'action' => 'reset',
            'ip_address' => $_SERVER['REMOTE_ADDR']
        ]
    );
    
    // Send confirmation email
    $email_subject = "Your CharterHub Password Has Been Reset";
    $email_body = "Hello,\n\n";
    $email_body .= "Your password for CharterHub has been successfully reset.\n\n";
    $email_body .= "If you did not make this change, please contact support immediately.\n\n";
    $email_body .= "You can log in with your new password at: {$frontend_urls['login_url']}\n\n";
    $email_body .= "Best regards,\nThe CharterHub Team";
    
    send_email($user['user_email'], $email_subject, $email_body);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Password has been reset successfully. You can now log in with your new password.',
        'login_url' => $frontend_urls['login_url']
    ]);
    
} catch (Exception $e) {
    // Log the error
    error_log("Password reset error: " . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 