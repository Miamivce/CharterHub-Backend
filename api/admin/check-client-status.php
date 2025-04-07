<?php
/**
 * Check Client Status API Endpoint
 * 
 * This endpoint checks a client's registration status, verification, and login history
 * Used by admin panel to determine if invitation links should be disabled
 */

// CORS debugging - log the request for troubleshooting
error_log("CHECK-CLIENT-STATUS: Request received from origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'none'));
error_log("CHECK-CLIENT-STATUS: Method: " . $_SERVER['REQUEST_METHOD']);

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
    error_log("CHECK-CLIENT-STATUS: Responded to OPTIONS request with 200 OK");
    exit;
}

// For non-OPTIONS requests, still set CORS headers
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    error_log("CHECK-CLIENT-STATUS: Set CORS origin to: $origin");
} else {
    header("Access-Control-Allow-Origin: http://localhost:3000");
    error_log("CHECK-CLIENT-STATUS: Set default CORS origin");
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
require_once dirname(__FILE__) . '/../../config/database.php';
require_once dirname(__FILE__) . '/../../includes/auth-functions.php';
require_once dirname(__FILE__) . '/../../includes/response-helpers.php';

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only GET method is allowed'
    ]);
    exit;
}

// Check if client_id parameter is provided
if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
    echo json_encode([
        'success' => false, 
        'error' => 'missing_parameter',
        'message' => 'Client ID is required'
    ]);
    exit;
}

$client_id = intval($_GET['client_id']);

try {
    // Connect to database
    $mysqli = new mysqli('localhost', 'root', '', 'charterhub_local');
    if ($mysqli->connect_error) {
        error_log("Database connection failed: " . $mysqli->connect_error);
        throw new Exception("Database connection failed");
    }
    
    // First check if the client exists in charterhub_users
    $sql = "SELECT id, email, first_name, last_name, password, verified, last_login 
            FROM wp_charterhub_users 
            WHERE id = ?";
            
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // If not found in charterhub_users, try the WordPress users table
        $sql = "SELECT ID as id, user_email as email, user_pass as password, 
                  IFNULL(meta_firstname.meta_value, '') as first_name,
                  IFNULL(meta_lastname.meta_value, '') as last_name
                FROM wp_users
                LEFT JOIN wp_usermeta as meta_firstname 
                  ON meta_firstname.user_id = ID AND meta_firstname.meta_key = 'first_name'
                LEFT JOIN wp_usermeta as meta_lastname 
                  ON meta_lastname.user_id = ID AND meta_lastname.meta_key = 'last_name'
                WHERE ID = ?";
                
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'client_not_found',
                'message' => 'Client not found'
            ]);
            exit;
        }
    }
    
    // Fetch client data
    $client_data = $result->fetch_assoc();
    $stmt->close();
    
    // Check if the client has logged in based on auth logs
    $sql = "SELECT COUNT(*) as login_count 
            FROM wp_charterhub_auth_logs 
            WHERE user_id = ? AND action = 'client_login_success'";
            
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $login_data = $result->fetch_assoc();
    $has_login_history = ($login_data && $login_data['login_count'] > 0);
    $stmt->close();
    
    // Check for active invitations
    $sql = "SELECT COUNT(*) as invitation_count 
            FROM wp_charterhub_invitations 
            WHERE customer_id = ? AND used = 0 AND expires_at > NOW()";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invitation_data = $result->fetch_assoc();
    $has_active_invitation = ($invitation_data && $invitation_data['invitation_count'] > 0);
    $stmt->close();
    
    // Determine registration status
    $is_registered = !empty($client_data['password']);
    $is_verified = !empty($client_data['verified']) && $client_data['verified'] == 1;
    $has_logged_in = !empty($client_data['last_login']) || $has_login_history;
    $is_fully_registered = $is_registered && $is_verified && $has_logged_in;
    
    // Format response
    $response = [
        'success' => true,
        'client_id' => $client_id,
        'email' => $client_data['email'],
        'name' => trim($client_data['first_name'] . ' ' . $client_data['last_name']),
        'registration_status' => [
            'is_registered' => $is_registered,
            'is_verified' => $is_verified,
            'has_logged_in' => $has_logged_in,
            'is_fully_registered' => $is_fully_registered,
            'has_active_invitations' => $has_active_invitation,
            'account_status' => $is_fully_registered ? 'complete' : 
                              ($is_registered && $is_verified ? 'pending_login' : 
                              ($is_registered ? 'pending_verification' : 'needs_registration'))
        ],
        'can_generate_invitation' => !$is_fully_registered,
        'should_disable_invitation_button' => $is_fully_registered,
        'client_data' => [
            'id' => $client_data['id'],
            'email' => $client_data['email'],
            'first_name' => $client_data['first_name'],
            'last_name' => $client_data['last_name']
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("CHECK-CLIENT-STATUS ERROR: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'An error occurred while checking client status'
    ]);
} 