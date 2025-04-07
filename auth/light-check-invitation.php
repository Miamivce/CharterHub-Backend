<?php
/**
 * Lightweight Invitation Check
 * 
 * This is a fallback endpoint for checking invitation status without complex database operations.
 * It provides basic validation by checking the token format and using local data where possible.
 */

// Set the CHARTERHUB_LOADED constant to allow include files to run
define('CHARTERHUB_LOADED', true);

// Include the global CORS handler
require_once dirname(__FILE__) . '/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Include required files
require_once dirname(__FILE__) . '/../db-config.php';

// Set proper content type header
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'valid' => false,
        'error' => 'method_not_allowed',
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Get the token from the request
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    echo json_encode([
        'success' => false,
        'valid' => false,
        'error' => 'missing_token',
        'message' => 'No invitation token provided'
    ]);
    exit;
}

error_log("LIGHT-CHECK-INVITATION: Checking token " . substr($token, 0, 8) . "...");

// Development mode fallback
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE && isset($_GET['fallback']) && $_GET['fallback'] === 'true') {
    echo json_encode([
        'success' => true,
        'valid' => true,
        'message' => 'Development mode: Token assumed valid',
        'dev_mode' => true
    ]);
    exit;
}

try {
    // Get database connection with direct PDO initialization
    try {
        $dsn = "mysql:host=localhost;dbname=charterhub_local;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, 'root', '', $options);
        error_log("LIGHT-CHECK-INVITATION: Successfully connected to database");
    } catch (PDOException $e) {
        error_log("LIGHT-CHECK-INVITATION DATABASE CONNECTION ERROR: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'valid' => false,
            'error' => 'database_error',
            'message' => 'Database connection error'
        ]);
        exit;
    }
    
    // Query only the minimal information needed with robust error handling
    try {
        error_log("LIGHT-CHECK-INVITATION: Preparing query for token: " . substr($token, 0, 8) . "...");
        $stmt = $pdo->prepare("
            SELECT id, used, used_at, expires_at 
            FROM wp_charterhub_invitations 
            WHERE token = ?
        ");
        
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            error_log("LIGHT-CHECK-INVITATION PREPARE ERROR: " . json_encode($errorInfo));
            throw new Exception("Failed to prepare statement");
        }
        
        $stmt->execute([$token]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invitation) {
            error_log("LIGHT-CHECK-INVITATION: Token not found: " . substr($token, 0, 8) . "...");
            echo json_encode([
                'success' => false,
                'valid' => false,
                'error' => 'token_not_found',
                'message' => 'The provided invitation token was not found'
            ]);
            exit;
        }
        
        // Check if invitation is used (check both used flag and used_at timestamp)
        if ($invitation['used'] || !empty($invitation['used_at'])) {
            error_log("LIGHT-CHECK-INVITATION: Token is already used: " . substr($token, 0, 8) . "...");
            http_response_code(410); // 410 Gone is appropriate for a used resource
            echo json_encode([
                'success' => false,
                'valid' => false,
                'status' => 'used',
                'error' => 'token_used',
                'message' => 'This invitation has already been used'
            ]);
            exit;
        }
        
        // Check if invitation is expired
        $now = new DateTime();
        $expires = new DateTime($invitation['expires_at']);
        
        if ($now > $expires) {
            error_log("LIGHT-CHECK-INVITATION: Token has expired: " . substr($token, 0, 8) . "...");
            echo json_encode([
                'success' => false,
                'valid' => false, 
                'error' => 'token_expired',
                'message' => 'This invitation has expired'
            ]);
            exit;
        }
        
        // If we get here, the invitation is valid
        error_log("LIGHT-CHECK-INVITATION: Token is valid: " . substr($token, 0, 8) . "...");
        echo json_encode([
            'success' => true,
            'valid' => true,
            'message' => 'Invitation is valid',
            'used' => false
        ]);
    } catch (PDOException $e) {
        error_log("LIGHT-CHECK-INVITATION QUERY ERROR: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'valid' => false,
            'error' => 'database_error',
            'message' => 'Database query error'
        ]);
        exit;
    }
} catch (Exception $e) {
    // Log the error for debugging
    error_log("LIGHT-CHECK-INVITATION ERROR: " . $e->getMessage());
    error_log("LIGHT-CHECK-INVITATION ERROR TRACE: " . $e->getTraceAsString());
    
    // For errors, return a simple error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'valid' => false,
        'error' => 'server_error',
        'message' => 'An error occurred while checking the invitation'
    ]);
} 