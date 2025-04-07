<?php
/**
 * Simple Verification Endpoint
 * 
 * A simplified version of the verification endpoint to isolate issues.
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
error_log("SIMPLE-VERIFY: Received token: " . $token);

try {
    error_log("SIMPLE-VERIFY: Attempting to get database connection");
    $pdo = get_db_connection();
    error_log("SIMPLE-VERIFY: Database connection successful");
    
    // Find user by verification token
    error_log("SIMPLE-VERIFY: Looking for user with token");
    $stmt = $pdo->prepare("
        SELECT id, email, first_name FROM {$db_config['table_prefix']}charterhub_users 
        WHERE verification_token = ? AND verified = 0
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        error_log("SIMPLE-VERIFY: No user found with token");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired verification token'
        ]);
        exit;
    }
    
    error_log("SIMPLE-VERIFY: Found user with ID: " . $user['id']);
    
    // Update user verification status
    error_log("SIMPLE-VERIFY: Updating user verification status");
    $stmt = $pdo->prepare("
        UPDATE {$db_config['table_prefix']}charterhub_users 
        SET verified = 1, 
            verification_token = NULL,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user['id']]);
    error_log("SIMPLE-VERIFY: User verification status updated");
    
    // Return success response
    error_log("SIMPLE-VERIFY: Sending success response");
    echo json_encode([
        'success' => true,
        'message' => 'Email verified successfully. You can now log in to your account.',
        'redirectUrl' => '/login'
    ]);
    
} catch (Exception $e) {
    error_log("SIMPLE-VERIFY ERROR: " . $e->getMessage());
    error_log("SIMPLE-VERIFY ERROR: Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred during email verification',
        'error' => $e->getMessage()
    ]);
} 