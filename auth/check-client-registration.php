<?php
/**
 * Check Client Registration Status API Endpoint
 * 
 * This endpoint checks if a client has completed all registration steps: 
 * - Created an account with a password
 * - Verified their email
 * - Logged in at least once
 * 
 * It's used by the admin panel to determine if new invitation links can be generated.
 */

// CORS debugging - log the request for troubleshooting
error_log("CHECK-REGISTRATION: Request received from origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'none'));
error_log("CHECK-REGISTRATION: Method: " . $_SERVER['REQUEST_METHOD']);

// Apply CORS headers immediately - must be done before ANY other output
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://localhost:8000',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:8000',
    'https://charterhub.yachtstory.com',
    'https://staging-charterhub.yachtstory.com'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Special handling for OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header("Access-Control-Allow-Origin: http://localhost:3000");
    }
    
    // Essential CORS headers
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-CSRF-Token");
    header("Access-Control-Max-Age: 86400"); // 24 hours cache
    
    http_response_code(200);
    error_log("CHECK-REGISTRATION: Responded to OPTIONS request with 200 OK");
    exit;
}

// For non-OPTIONS requests, still set CORS headers
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    error_log("CHECK-REGISTRATION: Set CORS origin to: $origin");
} else {
    header("Access-Control-Allow-Origin: http://localhost:3000");
    error_log("CHECK-REGISTRATION: Set default CORS origin");
}

// Essential CORS headers for non-OPTIONS requests
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-CSRF-Token");

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Set JSON content type
header('Content-Type: application/json');

// Include necessary configuration and helpers AFTER CORS is handled
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/auth-functions.php';
require_once __DIR__ . '/../utils/database.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Validate client_id or email parameter
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
$email = isset($_GET['email']) ? trim($_GET['email']) : null;

if (!$client_id && !$email) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'missing_parameters',
        'message' => 'Either client_id or email parameter is required'
    ]);
    exit;
}

try {
    // Connect to database
    $pdo = get_db_connection();
    
    // Find client by ID or email
    $clientData = null;
    
    if ($client_id) {
        // Try to find by ID first
        $stmt = $pdo->prepare("
            SELECT id, email, password, verified, first_name, last_name, role, last_login
            FROM {$db_config['table_prefix']}charterhub_users
            WHERE id = ?
        ");
        $stmt->execute([$client_id]);
        $clientData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$clientData && $email) {
        // If not found by ID or no ID provided, try by email
        $stmt = $pdo->prepare("
            SELECT id, email, password, verified, first_name, last_name, role, last_login
            FROM {$db_config['table_prefix']}charterhub_users
            WHERE LOWER(email) = LOWER(?)
        ");
        $stmt->execute([strtolower($email)]);
        $clientData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$clientData) {
        // If still not found, try the WordPress users table (legacy)
        $sql = "
            SELECT ID as id, user_email as email, user_pass as password, 
                   IFNULL(verified, 0) as verified, 
                   IFNULL(meta_firstname.meta_value, '') as first_name,
                   IFNULL(meta_lastname.meta_value, '') as last_name
            FROM {$db_config['table_prefix']}users
            LEFT JOIN {$db_config['table_prefix']}usermeta as meta_firstname 
                ON meta_firstname.user_id = ID AND meta_firstname.meta_key = 'first_name'
            LEFT JOIN {$db_config['table_prefix']}usermeta as meta_lastname 
                ON meta_lastname.user_id = ID AND meta_lastname.meta_key = 'last_name'
            WHERE " . ($client_id ? "ID = ?" : "LOWER(user_email) = LOWER(?)");
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$client_id ? $client_id : strtolower($email)]);
        $clientData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$clientData) {
        // Client not found in either table
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'client_not_found',
            'message' => 'Client not found'
        ]);
        exit;
    }
    
    // Check login history
    $hasLoginHistory = false;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as login_count
        FROM {$db_config['table_prefix']}charterhub_auth_logs
        WHERE user_id = ? AND action = 'client_login_success'
    ");
    $stmt->execute([$clientData['id']]);
    $loginData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($loginData && $loginData['login_count'] > 0) {
        $hasLoginHistory = true;
    }
    
    // Check for active invitations
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as invitation_count
        FROM {$db_config['table_prefix']}charterhub_invitations
        WHERE (customer_id = ? OR LOWER(email) = LOWER(?)) 
        AND used = 0 AND expires_at > NOW()
    ");
    $stmt->execute([$clientData['id'], $clientData['email']]);
    $invitationData = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasActiveInvitations = ($invitationData && $invitationData['invitation_count'] > 0);
    
    // Determine registration status
    $isRegistered = !empty($clientData['password']);
    $isVerified = !empty($clientData['verified']) && $clientData['verified'] == 1;
    $hasLoggedIn = !empty($clientData['last_login']) || $hasLoginHistory;
    $isFullyRegistered = $isRegistered && $isVerified && $hasLoggedIn;
    
    // Return detailed status information
    echo json_encode([
        'success' => true,
        'client_id' => $clientData['id'],
        'email' => $clientData['email'],
        'name' => trim($clientData['first_name'] . ' ' . $clientData['last_name']),
        'registration_status' => [
            'is_registered' => $isRegistered,
            'is_verified' => $isVerified,
            'has_logged_in' => $hasLoggedIn,
            'is_fully_registered' => $isFullyRegistered,
            'has_active_invitations' => $hasActiveInvitations,
            'account_status' => $isFullyRegistered ? 'complete' : 
                               ($isRegistered && $isVerified ? 'pending_login' : 
                               ($isRegistered ? 'pending_verification' : 'needs_registration'))
        ],
        'can_generate_invitation' => !$isFullyRegistered,
        'should_disable_invitation_button' => $isFullyRegistered
    ]);
    
} catch (Exception $e) {
    error_log("CHECK-CLIENT-REGISTRATION ERROR: " . $e->getMessage());
    error_log("CHECK-CLIENT-REGISTRATION ERROR TRACE: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'An error occurred while checking client registration status'
    ]);
} 