<?php
/**
 * Development Mode Verification Status Check Endpoint
 * This endpoint checks if a user account is verified
 */

define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../db-config.php';

// Set CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed']);
    exit;
}

try {
    // Check if email parameter is provided
    if (!isset($_GET['email']) || empty($_GET['email'])) {
        error_log("CHECK-VERIFICATION.PHP: Missing email parameter");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'missing_email',
            'message' => 'Email parameter is required'
        ]);
        exit;
    }
    
    $email = strtolower(trim($_GET['email']));
    error_log("CHECK-VERIFICATION.PHP: Checking verification status for email: " . $email);
    
    // Get database connection
    $pdo = get_db_connection();
    
    // Find user by email (case-insensitive)
    $stmt = $pdo->prepare("
        SELECT id, email, verified, verification_token 
        FROM {$db_config['table_prefix']}charterhub_users 
        WHERE LOWER(email) = LOWER(?)
    ");
    $stmt->execute([$email]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        error_log("CHECK-VERIFICATION.PHP: No user found for email: " . $email);
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'user_not_found',
            'message' => "No account found with email: {$email}"
        ]);
        exit;
    }
    
    error_log("CHECK-VERIFICATION.PHP: Found user with verification status: " . json_encode($result));
    
    // Return verification status
    echo json_encode([
        'success' => true,
        'verified' => (bool)$result['verified'],
        'message' => (bool)$result['verified'] 
            ? 'Account is verified' 
            : 'Account is not verified',
        'email' => $result['email'], // Return the email as stored in the database
        'has_token' => !empty($result['verification_token'])
    ]);
    
} catch (Exception $e) {
    error_log("CHECK-VERIFICATION.PHP ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => DEBUG_MODE ? $e->getMessage() : 'An error occurred while checking verification status'
    ]);
} 