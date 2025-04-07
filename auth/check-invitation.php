<?php
/**
 * Check Invitation Status Endpoint
 * 
 * This endpoint checks if a given invitation token is still valid and hasn't been used.
 * It verifies that the token exists, hasn't expired, and hasn't been previously used.
 * Enhanced security checks ensure no fallbacks are allowed.
 */

// Ensure no PHP warnings/errors are displayed in the output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set the CHARTERHUB_LOADED constant to allow include files to run
define('CHARTERHUB_LOADED', true);

// Include the global CORS handler
require_once dirname(__FILE__) . '/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Include database config and functions
require_once dirname(__FILE__) . '/../db-config.php';

// Set strict no-caching headers to prevent browser from caching results
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Set proper content type header
header('Content-Type: application/json; charset=UTF-8');

// Return a standardized error response
function error_response($message, $error_code = 'error', $status_code = 400) {
    http_response_code($status_code);
    
    // Log error details for debugging
    error_log("CHECK-INVITATION.PHP: Returning error response: $error_code ($status_code) - $message");
    
    echo json_encode([
        'success' => false,
        'valid' => false,
        'error' => $error_code,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Return a standardized success response
function success_response($data) {
    error_log("CHECK-INVITATION.PHP: Returning success response");
    
    echo json_encode(array_merge([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s')
    ], $data));
    exit;
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Ensure this endpoint only handles GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_log("CHECK-INVITATION.PHP: Rejected non-GET request method: " . $_SERVER['REQUEST_METHOD']);
    error_response('Method not allowed', 'method_not_allowed', 405);
}

// Get the token from the query parameters
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// Log the request with timestamp
error_log("CHECK-INVITATION.PHP: [" . date('Y-m-d H:i:s') . "] Checking invitation token: " . substr($token, 0, 8) . "...");

// Validate token format
if (empty($token)) {
    error_log("CHECK-INVITATION.PHP: Empty token provided");
    error_response('No invitation token provided', 'missing_token');
}

// Enhanced security: Validate token format (accept both 32 and 64 character tokens)
// Many systems use either 32 or 64 character tokens
if (!(strlen($token) === 32 || strlen($token) === 64) || !ctype_alnum($token)) {
    error_log("CHECK-INVITATION.PHP: Invalid token format: " . substr($token, 0, 8) . "... Length: " . strlen($token));
    error_response('Invalid invitation token format', 'invalid_token_format');
}

try {
    // Get database connection with robust error handling
    try {
        $dsn = "mysql:host=localhost;dbname=charterhub_local;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, 'root', '', $options);
        error_log("CHECK-INVITATION.PHP: Successfully connected to database");
    } catch (PDOException $e) {
        error_log("CHECK-INVITATION.PHP DATABASE CONNECTION ERROR: " . $e->getMessage());
        error_response('Database connection error', 'database_error', 500);
    }
    
    // Check invitation status with robust error handling
    try {
        // Improved query to get more comprehensive data for validation
        $query = "
            SELECT i.id, i.token, i.email, i.customer_id, 
                   i.used, i.used_at, i.created_at, i.expires_at, 
                   c.email as customer_email, c.display_name as customer_name,
                   c.verified as customer_verified,
                   u.id as used_by_user_id
            FROM wp_charterhub_invitations i
            LEFT JOIN wp_charterhub_users c ON i.customer_id = c.id
            LEFT JOIN wp_charterhub_users u ON i.used_by_user_id = u.id
            WHERE i.token = ?
            LIMIT 1
        ";
        
        error_log("CHECK-INVITATION.PHP: Preparing query for token: " . substr($token, 0, 8) . "...");
        $stmt = $pdo->prepare($query);
        
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            error_log("CHECK-INVITATION.PHP PREPARE ERROR: " . json_encode($errorInfo));
            error_response('Failed to prepare database query', 'database_error', 500);
        }
        
        $stmt->execute([$token]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if invitation exists
        if (!$invitation) {
            error_log("CHECK-INVITATION.PHP: Token not found: " . substr($token, 0, 8) . "...");
            error_response('Invitation not found', 'token_not_found', 404);
        }
        
        // Enhanced check: Double-verify token matches
        if ($invitation['token'] !== $token) {
            error_log("CHECK-INVITATION.PHP: Token mismatch. Expected: " . $token . ", Got: " . $invitation['token']);
            error_response('Invalid invitation token', 'token_mismatch', 400);
        }
        
        // Log the invitation details
        error_log("CHECK-INVITATION.PHP: Found token: " . substr($token, 0, 8) . "... Used: " . ($invitation['used'] ? 'Yes' : 'No'));
        
        // Enhanced check: Strict used status checking
        // Check multiple indicators that the invitation might be used
        $isUsed = false;
        $usedReasons = [];
        
        // 1. Check the 'used' boolean flag
        if ($invitation['used'] == 1) {
            $isUsed = true;
            $usedReasons[] = 'boolean_flag';
            error_log("CHECK-INVITATION.PHP: Token marked as used via boolean flag: " . substr($token, 0, 8) . "...");
        }
        
        // 2. Check the used_at timestamp
        if (!empty($invitation['used_at'])) {
            $isUsed = true;
            $usedReasons[] = 'used_at_timestamp';
            error_log("CHECK-INVITATION.PHP: Token has used_at timestamp: " . $invitation['used_at']);
        }
        
        // 3. Check if user_by_user_id is set
        if (!empty($invitation['used_by_user_id'])) {
            $isUsed = true;
            $usedReasons[] = 'used_by_user_id';
            error_log("CHECK-INVITATION.PHP: Token has used_by_user_id: " . $invitation['used_by_user_id']);
        }
        
        // Removing the customer verification check since admin-created accounts 
        // are verified by default and should still be able to use invitation links
        // Log that we're skipping this check
        if (!empty($invitation['customer_verified']) && $invitation['customer_verified'] == 1) {
            error_log("CHECK-INVITATION.PHP: Customer is verified, but we're allowing this invitation to be used");
        }
        
        // Return error if the invitation is used
        if ($isUsed) {
            error_log("CHECK-INVITATION.PHP: Token is already used: " . substr($token, 0, 8) . "... Reasons: " . implode(', ', $usedReasons));
            http_response_code(410); // 410 Gone is appropriate for a used resource
            echo json_encode([
                'success' => false,
                'valid' => false,
                'status' => 'used',
                'error' => 'token_used',
                'message' => 'This invitation has already been used',
                'reasons' => $usedReasons,
                'timestamp' => date('Y-m-d H:i:s'),
                'customer' => [
                    'id' => $invitation['customer_id'],
                    'email' => $invitation['customer_email'] ?? $invitation['email'] ?? ''
                ]
            ]);
            exit;
        }
        
        // Check if token has expired
        $now = new DateTime();
        $expires_at = new DateTime($invitation['expires_at']);
        
        if ($now > $expires_at) {
            error_log("CHECK-INVITATION.PHP: Token has expired: " . substr($token, 0, 8) . "... Expired at: " . $invitation['expires_at']);
            error_response('This invitation has expired', 'token_expired', 400);
        }
        
        // Check if customer_id is set (required for valid invitations)
        if (empty($invitation['customer_id'])) {
            error_log("CHECK-INVITATION.PHP: Token missing customer ID: " . substr($token, 0, 8) . "...");
            error_response('Invalid invitation: missing customer ID', 'missing_customer_id', 400);
        }
        
        // Format the customer data - Prioritize ID over email
        $customer = [
            'id' => $invitation['customer_id'],
            // Include email as reference only, not for identification
            'email' => $invitation['customer_email'] ?? $invitation['email'] ?? '',
            'name' => $invitation['customer_name'] ?? ''
        ];
        
        // Return successful response with all necessary data
        error_log("CHECK-INVITATION.PHP: Token is valid: " . substr($token, 0, 8) . "... for customer ID: " . $invitation['customer_id']);
        success_response([
            'valid' => true,
            'message' => 'Invitation is valid',
            'customer' => $customer,
            'invitation' => [
                'id' => $invitation['id'],
                'customer_id' => $invitation['customer_id'], // Explicitly include customer_id
                'created_at' => $invitation['created_at'],
                'expires_at' => $invitation['expires_at']
            ]
        ]);
    } catch (PDOException $queryError) {
        error_log("CHECK-INVITATION.PHP QUERY ERROR: " . $queryError->getMessage());
        error_log("CHECK-INVITATION.PHP QUERY ERROR TRACE: " . $queryError->getTraceAsString());
        error_response('Database query error', 'database_error', 500);
    }
} catch (Exception $e) {
    // Log the error but don't expose details to client
    error_log("CHECK-INVITATION.PHP ERROR: " . $e->getMessage());
    error_log("CHECK-INVITATION.PHP ERROR TRACE: " . $e->getTraceAsString());
    
    // Return a generic error
    error_response('An error occurred while checking the invitation', 'server_error', 500);
} 