<?php
/**
 * Direct Invitations API Endpoint
 * 
 * This endpoint provides direct access to invitation functions without relying on
 * external JWT libraries. It supports both checking invitation status and generating
 * new invitations.
 * 
 * FOR DEVELOPMENT USE ONLY - NOT FOR PRODUCTION
 */

// Manually handle CORS for this endpoint
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

// Set CORS headers based on origin
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma");
    header("Access-Control-Max-Age: 86400"); // 24 hours
} else if (empty($origin)) {
    // Default fallback if no origin is provided
    header("Access-Control-Allow-Origin: http://localhost:3000");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma");
    header("Access-Control-Max-Age: 86400"); // 24 hours
} else {
    // Log if origin is not allowed
    error_log("DIRECT-INVITATIONS: Request from non-allowed origin: $origin");
    // Don't set CORS headers for non-allowed origins
}

// Set JSON content type
header('Content-Type: application/json');

// Handle preflight requests immediately before other processing
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require authentication and include necessary files here
require_once dirname(__FILE__) . '/../../config/database.php';
require_once dirname(__FILE__) . '/../../includes/auth-functions.php';
require_once dirname(__FILE__) . '/../../includes/response-helpers.php';
require_once dirname(__FILE__) . '/direct-auth-helper.php';

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Ensure the user is authenticated as an admin
try {
    $admin_info = ensure_admin_access();
} catch (Exception $e) {
    $response['message'] = 'Unauthorized. Admin access required.';
    echo json_encode($response);
    exit;
}

// Determine which function to call based on the HTTP method and parameters
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handle_check_invitation_status();
} else if ($method === 'POST') {
    handle_generate_invitation();
} else {
    $response['message'] = 'Unsupported method';
    echo json_encode($response);
    exit;
}

/**
 * Handle checking the status of invitations for a client
 */
function handle_check_invitation_status() {
    // Check if client ID is provided
    if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
        echo json_encode([
            'success' => false,
            'error' => 'missing_client_id',
            'message' => 'Client ID is required'
        ]);
        exit;
    }

    $client_id = trim($_GET['client_id']);
    
    try {
        // Get database connection
        $conn = get_database_connection();
        
        // First get the client's email
        $stmt = $conn->prepare("SELECT email FROM wp_charterhub_users WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $client = $result->fetch_assoc();
        $stmt->close();
        
        if (!$client) {
            echo json_encode([
                'success' => false,
                'error' => 'client_not_found',
                'message' => 'Client not found'
            ]);
            exit;
        }
        
        // Find invitations for this client's email
        $stmt = $conn->prepare("
            SELECT id, token, email, created_at, expires_at, used, used_at
            FROM wp_charterhub_invitations 
            WHERE email = ?
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("s", $client['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $invitations = [];
        while ($row = $result->fetch_assoc()) {
            $invitations[] = $row;
        }
        $stmt->close();
        
        // Prepare response structure
        $response = [
            'success' => true,
            'client_id' => $client_id,
            'email' => $client['email'],
            'invitations' => []
        ];
        
        // If no invitations found
        if (empty($invitations)) {
            $response['has_invitations'] = false;
            $response['message'] = 'No invitations found for this client';
        } else {
            $response['has_invitations'] = true;
            $response['message'] = 'Invitations found for this client';
            
            // Check invitation statuses
            foreach ($invitations as $invitation) {
                $now = new DateTime();
                $expiryDate = new DateTime($invitation['expires_at']);
                $isExpired = $expiryDate < $now;
                
                $invitationStatus = [
                    'id' => $invitation['id'],
                    'token' => $invitation['token'],
                    'created_at' => $invitation['created_at'],
                    'expires_at' => $invitation['expires_at'],
                    'is_expired' => $isExpired,
                    'is_used' => (bool)$invitation['used'],
                    'used_at' => $invitation['used_at']
                ];
                
                // Add overall status field
                if ($invitation['used']) {
                    $invitationStatus['status'] = 'used';
                } elseif ($isExpired) {
                    $invitationStatus['status'] = 'expired';
                } else {
                    $invitationStatus['status'] = 'active';
                }
                
                $response['invitations'][] = $invitationStatus;
            }
            
            // Add a field to indicate if any invitation is still active
            $response['has_active_invitation'] = false;
            foreach ($response['invitations'] as $invitation) {
                if ($invitation['status'] === 'active') {
                    $response['has_active_invitation'] = true;
                    break;
                }
            }
        }
        
        $conn->close();
        echo json_encode($response);
        
    } catch (Exception $e) {
        error_log("CHECK-INVITATION-STATUS ERROR: " . $e->getMessage());
        error_log("CHECK-INVITATION-STATUS ERROR TRACE: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false,
            'error' => 'server_error',
            'message' => 'An error occurred while checking invitation status'
        ]);
    }
}

/**
 * Handle generating a new invitation for a client
 */
function handle_generate_invitation() {
    // Get JSON data from request body
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    // Check if client ID is provided
    if (!isset($data['clientId']) || empty($data['clientId'])) {
        echo json_encode([
            'success' => false,
            'error' => 'missing_client_id',
            'message' => 'Client ID is required'
        ]);
        exit;
    }
    
    $client_id = trim($data['clientId']);
    $force = isset($data['force']) && $data['force'] ? true : false;
    
    try {
        // Get database connection
        $conn = get_database_connection();
        
        // Begin transaction
        $conn->begin_transaction();
        
        // First get the client's email
        $stmt = $conn->prepare("SELECT id, email FROM wp_charterhub_users WHERE id = ?");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $client = $result->fetch_assoc();
        $stmt->close();
        
        if (!$client) {
            // Try looking up in WordPress users table as fallback
            $stmt = $conn->prepare("SELECT ID as id, user_email as email FROM wp_users WHERE ID = ?");
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $client = $result->fetch_assoc();
            $stmt->close();
            
            if (!$client) {
                $conn->rollback();
                echo json_encode([
                    'success' => false,
                    'error' => 'client_not_found',
                    'message' => 'Client not found'
                ]);
                exit;
            }
        }
        
        // NEW: Check if the client is already registered and has completed account setup
        $stmt = $conn->prepare("
            SELECT id, verified, password, last_login 
            FROM wp_charterhub_users 
            WHERE id = ? OR email = ?
        ");
        $email = strtolower($client['email']);
        $stmt->bind_param("is", $client_id, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $clientAccount = $result->fetch_assoc();
        $stmt->close();
        
        // Check if user has login history - a better indicator of completed setup
        $hasLoginHistory = false;
        if ($clientAccount) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as login_count
                FROM wp_charterhub_auth_logs 
                WHERE user_id = ? AND action = 'client_login_success'
            ");
            $stmt->bind_param("i", $clientAccount['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $loginCount = $result->fetch_assoc();
            $stmt->close();
            if ($loginCount && $loginCount['login_count'] > 0) {
                $hasLoginHistory = true;
            }
        }
        
        // Only block invitation if all criteria for a complete account are met
        $isCompletelyRegistered = $clientAccount && 
                                !empty($clientAccount['password']) && 
                                $clientAccount['verified'] == 1 && 
                                ($hasLoginHistory || !empty($clientAccount['last_login']));
        
        // If client has a completely registered account, prevent invitation generation
        if ($isCompletelyRegistered) {
            // Check if the force flag is set to override this check
            if ($force) {
                // Log that we're forcing invitation for a registered user
                error_log("DIRECT-INVITATION: Force-generating invitation for registered client ID: {$client_id}");
            } else {
                $conn->rollback();
                echo json_encode([
                    'success' => false,
                    'error' => 'client_already_registered',
                    'message' => 'This client has already registered and completed account setup. Invitation links cannot be generated for registered clients.'
                ]);
                exit;
            }
        } else if ($clientAccount && !empty($clientAccount['password']) && $clientAccount['verified'] == 1) {
            // Client is registered and verified but hasn't logged in yet
            // We'll allow an invitation but include a warning
            $warning = "This client has registered and verified their account but hasn't logged in yet. An invitation link can still be generated.";
        } else if ($clientAccount && !empty($clientAccount['password'])) {
            // Client is registered but not verified
            $warning = "This client has started registration but hasn't verified their account yet. An invitation link can still be generated.";
        }
        
        // Check if there's already an active invitation for this client
        $stmt = $conn->prepare("
            SELECT id, token, email, created_at, expires_at 
            FROM wp_charterhub_invitations 
            WHERE email = ? AND used = 0 AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ");
        $email = strtolower($client['email']);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $existingInvitation = $result->fetch_assoc();
        $stmt->close();
        
        // If active invitation exists and force flag is not set, return that invitation
        if ($existingInvitation && !$force) {
            // Get base URL for frontend
            $frontend_url = isset($_SERVER['HTTP_REFERER']) 
                ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_SCHEME) . '://' . parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST)
                : 'http://localhost:3000'; // Fallback for development
            
            // Add port if it exists in the referrer
            if (isset($_SERVER['HTTP_REFERER'])) {
                $port = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PORT);
                if ($port) {
                    $frontend_url .= ":" . $port;
                }
            }
            
            // Update to include 'invited=true' parameter to match new invitations
            $invitation_url = "{$frontend_url}/register?invited=true&token={$existingInvitation['token']}";
            
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Active invitation already exists',
                'invitation_id' => $existingInvitation['id'],
                'invitation_url' => $invitation_url,
                'expires_at' => $existingInvitation['expires_at'],
                'is_new' => false
            ]);
            exit;
        }
        
        // Generate a new invitation token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Get the current admin user information from the token
        $admin_info = ensure_admin_access();
        $admin_id = $admin_info['user_id'];
        
        // Insert the new invitation
        $stmt = $conn->prepare("
            INSERT INTO wp_charterhub_invitations (
                token, email, customer_id, created_by, created_at, expires_at
            ) VALUES (
                ?, ?, ?, ?, NOW(), ?
            )
        ");
        $stmt->bind_param("ssiis", $token, $email, $client_id, $admin_id, $expires_at);
        $stmt->execute();
        $invitation_id = $conn->insert_id;
        $stmt->close();
        
        // Log the invitation creation
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $details = json_encode([
            'client_id' => $client_id,
            'email' => $client['email'],
            'invitation_id' => $invitation_id,
            'expires_at' => $expires_at,
            'forced' => $force
        ]);
        
        $stmt = $conn->prepare("
            INSERT INTO wp_charterhub_auth_logs (
                user_id, action, status, ip_address, details, created_at
            ) VALUES (
                ?, 'invitation', 'success', ?, ?, NOW()
            )
        ");
        $stmt->bind_param("iss", $admin_id, $ip_address, $details);
        $success = $stmt->execute();
        
        if (!$success) {
            error_log("Warning: Failed to log invitation action: " . $stmt->error);
        }
        $stmt->close();
        
        // Commit the transaction
        $conn->commit();
        
        // Generate the invitation URL
        $frontend_url = isset($_SERVER['HTTP_REFERER']) 
            ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_SCHEME) . '://' . parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST)
            : 'http://localhost:3000'; // Fallback for development
            
        // Add port if it exists in the referrer
        if (isset($_SERVER['HTTP_REFERER'])) {
            $port = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PORT);
            if ($port) {
                $frontend_url .= ":" . $port;
            }
        }
            
        // Update to include 'invited=true' parameter
        $invitation_url = "{$frontend_url}/register?invited=true&token={$token}";
        
        // Return the invitation details
        echo json_encode([
            'success' => true,
            'message' => 'Invitation generated successfully',
            'invitation_id' => $invitation_id,
            'invitation_url' => $invitation_url,
            'expires_at' => $expires_at,
            'is_new' => true
        ]);
        
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn)) {
            $conn->rollback();
        }
        
        error_log("GENERATE-INVITATION ERROR: " . $e->getMessage());
        error_log("GENERATE-INVITATION ERROR TRACE: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false,
            'error' => 'server_error',
            'message' => 'An error occurred while generating the invitation: ' . $e->getMessage()
        ]);
    }
}
?> 