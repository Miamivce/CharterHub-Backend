<?php
/**
 * CharterHub Password Reset Request API Endpoint
 * 
 * This file handles requesting a password reset by sending a token via email
 */

// Increase execution time limit for this endpoint
set_time_limit(90); // Set to 90 seconds

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
    if (empty($data['email'])) {
        throw new Exception('Email is required');
    }
    
    // Validate email format
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Get database connection
    $pdo = get_db_connection();
    
    // Find user by email
    $stmt = $pdo->prepare("
        SELECT ID, user_email, first_name 
        FROM {$db_config['table_prefix']}users 
        WHERE user_email = :email
    ");
    $stmt->execute(['email' => $data['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // For security reasons, always return success even if user not found
    // This prevents email enumeration attacks
    if (!$user) {
        // We'll log this but still return a success message
        log_auth_action(
            null,
            'password_reset',
            'failure',
            [
                'email' => $data['email'],
                'reason' => 'User not found'
            ]
        );
        
        // Return a success message even though we didn't send an email
        echo json_encode([
            'success' => true,
            'message' => 'If your email exists in our system, you will receive a password reset link shortly.'
        ]);
        exit;
    }
    
    // Generate reset token and set expiration time
    $reset_token = generate_token();
    $expires = date('Y-m-d H:i:s', strtotime("+{$auth_config['verification_expiration']} hours"));
    
    // Update user with reset token and expiration
    $stmt = $pdo->prepare("
        UPDATE {$db_config['table_prefix']}users 
        SET reset_password_token = :token,
            reset_password_expires = :expires
        WHERE ID = :user_id
    ");
    $stmt->execute([
        'token' => $reset_token,
        'expires' => $expires,
        'user_id' => $user['ID']
    ]);
    
    // Generate reset URL
    $reset_url = "{$frontend_urls['password_reset_url']}?token={$reset_token}";
    
    // Send password reset email
    $email_subject = "CharterHub Password Reset";
    $email_body = "Hello " . ($user['first_name'] ?: 'there') . ",\n\n";
    $email_body .= "We received a request to reset your password for your CharterHub account. ";
    $email_body .= "To reset your password, please click on the link below:\n\n";
    $email_body .= "{$reset_url}\n\n";
    $email_body .= "This link will expire in {$auth_config['verification_expiration']} hours.\n\n";
    $email_body .= "If you did not request a password reset, please ignore this email or contact support.\n\n";
    $email_body .= "Best regards,\nThe CharterHub Team";
    
    send_email($user['user_email'], $email_subject, $email_body);
    
    // Log password reset request
    log_auth_action(
        $user['ID'],
        'password_reset',
        'success',
        [
            'action' => 'request',
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'expires' => $expires
        ]
    );
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'If your email exists in our system, you will receive a password reset link shortly.'
    ]);
    
} catch (Exception $e) {
    // Log the error
    error_log("Password reset request error: " . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 