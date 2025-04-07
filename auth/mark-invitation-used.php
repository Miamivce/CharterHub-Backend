<?php
/**
 * Mark Invitation As Used API Endpoint
 * 
 * This endpoint marks an invitation token as used.
 * It is only called after successful registration completion to ensure the token can't be used again.
 * DO NOT CALL THIS DURING INVITATION CHECKING - only during finalization of registration.
 */

// Disable direct PHP errors from being displayed
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Include necessary files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/auth-functions.php';
require_once __DIR__ . '/../utils/database.php';

// Set strict no-caching headers to prevent browser from caching results
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Set CORS headers FIRST - before any potential errors can occur
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No content needed for preflight
    exit;
}

// Function to return standardized responses
function return_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

// Security: ONLY allow POST requests - removing GET method support
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return_response([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Method not allowed. Only POST is supported for this security-sensitive operation.'
    ], 405);
}

// Get JSON data from request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate token
if (!isset($data['token']) || empty($data['token'])) {
    return_response([
        'success' => false,
        'error' => 'missing_token',
        'message' => 'Invitation token is required'
    ], 400);
}

// Required parameters to ensure this is only called after successful registration
if (!isset($data['registration_completed']) || !$data['registration_completed']) {
    return_response([
        'success' => false,
        'error' => 'incomplete_registration',
        'message' => 'Invitations can only be marked as used after registration is completed'
    ], 400);
}

// If user_id is not provided, this isn't a proper registration completion
if (!isset($data['user_id']) || empty($data['user_id'])) {
    return_response([
        'success' => false,
        'error' => 'missing_user_id',
        'message' => 'User ID is required to mark invitation as used'
    ], 400);
}

$token = trim($data['token']);
$email = isset($data['email']) ? trim($data['email']) : null;
$user_id = isset($data['user_id']) ? (int)$data['user_id'] : null;

// Log with timestamp
error_log("MARK-INVITATION-USED: [" . date('Y-m-d H:i:s') . "] Attempting to mark invitation token as used: " . substr($token, 0, 8) . "...");
if ($email) {
    error_log("MARK-INVITATION-USED: Associated with email: " . $email);
}
if ($user_id) {
    error_log("MARK-INVITATION-USED: Associated with user_id: " . $user_id);
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
        error_log("MARK-INVITATION-USED: Successfully connected to database");
    } catch (PDOException $e) {
        error_log("MARK-INVITATION-USED DATABASE CONNECTION ERROR: " . $e->getMessage());
        return_response([
            'success' => false,
            'error' => 'database_error',
            'message' => 'Database connection error'
        ], 500);
    }
    
    // Check if the token exists in the database, regardless of its status
    try {
        // Enhanced query to get more comprehensive data for validation
        $stmt = $pdo->prepare("
            SELECT i.id, i.token, i.email, i.customer_id, i.used, i.used_at, i.used_by_user_id 
            FROM wp_charterhub_invitations i 
            WHERE i.token = ?
        ");
        
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            error_log("MARK-INVITATION-USED PREPARE ERROR: " . json_encode($errorInfo));
            throw new Exception("Failed to prepare statement");
        }
        
        $stmt->execute([$token]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If invitation doesn't exist at all
        if (!$invitation) {
            error_log("MARK-INVITATION-USED: Token not found in database: " . substr($token, 0, 8) . "...");
            return_response([
                'success' => false,
                'error' => 'token_not_found',
                'message' => 'Invitation token not found',
                'status' => 'invalid'
            ], 404);
        }
        
        // Log the customer_id association for verification
        if (!empty($invitation['customer_id'])) {
            error_log("MARK-INVITATION-USED: Token associated with customer_id: " . $invitation['customer_id']);
        } else {
            error_log("MARK-INVITATION-USED WARNING: Token has no associated customer_id!");
        }
        
        // Check if invitation is already used
        if ($invitation['used'] || !empty($invitation['used_at']) || !empty($invitation['used_by_user_id'])) {
            error_log("MARK-INVITATION-USED: Token already marked as used: " . substr($token, 0, 8) . "...");
            
            // If the invitation is not linked to our new user_id, someone else used it
            if (!empty($invitation['used_by_user_id']) && $invitation['used_by_user_id'] != $user_id) {
                error_log("MARK-INVITATION-USED: Token was used by a different user: " . $invitation['used_by_user_id'] . ", not " . $user_id);
                return_response([
                    'success' => false,
                    'error' => 'token_used_by_other',
                    'message' => 'This invitation was used by another user',
                    'status' => 'used'
                ], 403);
            }
            
            return_response([
                'success' => true,
                'already_used' => true,
                'message' => 'Invitation was already marked as used',
                'status' => 'used'
            ], 200);
        }
        
        // Begin transaction to ensure atomic update
        $pdo->beginTransaction();
        
        try {
            // Mark the invitation as used with full details
            $updateStmt = $pdo->prepare("
                UPDATE wp_charterhub_invitations 
                SET used = 1, 
                    used_at = NOW(),
                    used_by_user_id = ?,
                    updated_at = NOW()
                WHERE token = ? AND used = 0
            ");
            
            if (!$updateStmt) {
                $errorInfo = $pdo->errorInfo();
                error_log("MARK-INVITATION-USED UPDATE PREPARE ERROR: " . json_encode($errorInfo));
                throw new Exception("Failed to prepare update statement");
            }
            
            $updateStmt->execute([$user_id, $token]);
            $affected_rows = $updateStmt->rowCount();
            
            if ($affected_rows > 0) {
                // Success - commit transaction
                $pdo->commit();
                
                error_log("MARK-INVITATION-USED: Successfully marked invitation " . substr($token, 0, 8) . "... as used by user " . $user_id);
                return_response([
                    'success' => true,
                    'message' => 'Invitation marked as used successfully',
                    'status' => 'used',
                    'timestamp' => date('Y-m-d H:i:s')
                ], 200);
            } else {
                // No rows affected - check if it's already marked as used
                $checkStmt = $pdo->prepare("SELECT used, used_by_user_id FROM wp_charterhub_invitations WHERE token = ?");
                $checkStmt->execute([$token]);
                $current_status = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($current_status && $current_status['used']) {
                    // Someone else already marked it - commit and return success
                    $pdo->commit();
                    
                    if (!empty($current_status['used_by_user_id']) && $current_status['used_by_user_id'] != $user_id) {
                        error_log("MARK-INVITATION-USED: Token was marked as used by a different user: " . $current_status['used_by_user_id']);
                        return_response([
                            'success' => false,
                            'error' => 'token_used_by_other',
                            'message' => 'This invitation was marked as used by another user',
                            'status' => 'used'
                        ], 403);
                    }
                    
                    error_log("MARK-INVITATION-USED: Invitation was already marked as used");
                    return_response([
                        'success' => true,
                        'already_used' => true,
                        'message' => 'Invitation was already marked as used',
                        'status' => 'used'
                    ], 200);
                } else {
                    // Something else went wrong - rollback
                    $pdo->rollBack();
                    
                    error_log("MARK-INVITATION-USED: Update had no effect but token is not marked as used");
                    return_response([
                        'success' => false,
                        'error' => 'update_failed',
                        'message' => 'Failed to mark invitation as used',
                        'status' => 'error'
                    ], 500);
                }
            }
        } catch (Exception $transactionError) {
            // Any error during transaction - rollback
            $pdo->rollBack();
            throw $transactionError;
        }
    } catch (PDOException $queryError) {
        error_log("MARK-INVITATION-USED QUERY ERROR: " . $queryError->getMessage());
        error_log("MARK-INVITATION-USED QUERY ERROR TRACE: " . $queryError->getTraceAsString());
        return_response([
            'success' => false,
            'error' => 'database_error',
            'message' => 'Database query error',
            'status' => 'error'
        ], 500);
    }
} catch (Exception $e) {
    error_log("MARK-INVITATION-USED ERROR: " . $e->getMessage());
    error_log("MARK-INVITATION-USED ERROR TRACE: " . $e->getTraceAsString());
    
    return_response([
        'success' => false,
        'error' => 'server_error',
        'message' => 'An error occurred while marking the invitation as used',
        'status' => 'error'
    ], 500);
} 