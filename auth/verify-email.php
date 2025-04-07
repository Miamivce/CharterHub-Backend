<?php
/**
 * CharterHub Email Verification Endpoint
 * 
 * This file handles email verification for new user accounts
 */

define('CHARTERHUB_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../db-config.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Require GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification token is required']);
    exit;
}

$token = $_GET['token'];
error_log("VERIFY-EMAIL.PHP: Received token: " . $token);

try {
    error_log("VERIFY-EMAIL.PHP: Attempting to get database connection");
    $pdo = get_db_connection();
    error_log("VERIFY-EMAIL.PHP: Database connection successful");
    
    // Find user by verification token
    error_log("VERIFY-EMAIL.PHP: Looking for user with token");
    $stmt = $pdo->prepare("
        SELECT * FROM {$db_config['table_prefix']}charterhub_users 
        WHERE verification_token = ? AND verified = 0
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        error_log("VERIFY-EMAIL.PHP: No user found with token: " . $token);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired verification token'
        ]);
        exit;
    }
    
    error_log("VERIFY-EMAIL.PHP: Found user with ID: " . $user['id'] . ", Email: " . $user['email']);
    
    // Start transaction
    $pdo->beginTransaction();
    error_log("VERIFY-EMAIL.PHP: Started transaction");
    
    try {
        // Update user verification status
        error_log("VERIFY-EMAIL.PHP: Updating user verification status");
        $stmt = $pdo->prepare("
            UPDATE {$db_config['table_prefix']}charterhub_users 
            SET verified = 1, 
                verification_token = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        error_log("VERIFY-EMAIL.PHP: User verification status updated");
        
        // Log verification success
        error_log("VERIFY-EMAIL.PHP: Logging verification success");
        $stmt = $pdo->prepare("
            INSERT INTO {$db_config['table_prefix']}charterhub_auth_logs 
            (user_id, action, status, ip_address, user_agent, details) 
            VALUES (?, 'email_verification', 'success', ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? '::1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            json_encode([
                'email' => $user['email'],
                'verification_time' => date('Y-m-d H:i:s')
            ])
        ]);
        error_log("VERIFY-EMAIL.PHP: Verification success logged");
        
        $pdo->commit();
        error_log("VERIFY-EMAIL.PHP: Transaction committed");
        
        // Return success response
        error_log("VERIFY-EMAIL.PHP: Sending success response");
        echo json_encode([
            'success' => true,
            'message' => 'Email verified successfully. You can now log in to your account.',
            'redirectUrl' => $frontend_urls['login_url'] ?? '/login'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("VERIFY-EMAIL.PHP ERROR: Transaction rolled back due to error: " . $e->getMessage());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("VERIFY-EMAIL.PHP ERROR: " . $e->getMessage());
    error_log("VERIFY-EMAIL.PHP ERROR: Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during email verification',
        'error' => $e->getMessage()
    ]);
} 