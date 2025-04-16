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

// Define the CHARTERHUB_LOADED constant to grant access to included files
define('CHARTERHUB_LOADED', true);

// Include auth helper with the handle_admin_request function
require_once __DIR__ . '/direct-auth-helper.php';

// Use the admin request handler to properly handle CORS and authentication
handle_admin_request(function($admin_user) {
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return handle_check_invitation_status();
        case 'POST':
            return handle_generate_invitation();
        default:
            throw new Exception('Method not allowed');
    }
});

/**
 * Handle checking the status of invitations for a client
 * 
 * @return array Invitation status data
 */
function handle_check_invitation_status() {
    // Check if client ID is provided
    if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
        return [
            'success' => false,
            'error' => 'missing_client_id',
            'message' => 'Client ID is required'
        ];
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
            return [
                'success' => false,
                'error' => 'client_not_found',
                'message' => 'Client not found'
            ];
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
        
        return $response;
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'exception',
            'message' => 'Error checking invitation status: ' . $e->getMessage()
        ];
    }
}

/**
 * Handle generating a new invitation for a client
 * 
 * @return array Generated invitation data
 */
function handle_generate_invitation() {
    // Get JSON data from request body
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    // Check if client ID is provided
    if (!isset($data['clientId']) || empty($data['clientId'])) {
        return [
            'success' => false,
            'error' => 'missing_client_id',
            'message' => 'Client ID is required'
        ];
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
                return [
                    'success' => false,
                    'error' => 'client_not_found',
                    'message' => 'Client not found'
                ];
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
                return [
                    'success' => false,
                    'error' => 'client_already_registered',
                    'message' => 'This client has already registered and completed account setup. Invitation links cannot be generated for registered clients.'
                ];
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
            return [
                'success' => true,
                'message' => 'Active invitation already exists',
                'invitation_id' => $existingInvitation['id'],
                'invitation_url' => $invitation_url,
                'expires_at' => $existingInvitation['expires_at'],
                'is_new' => false
            ];
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
        return [
            'success' => true,
            'message' => 'Invitation generated successfully',
            'invitation_id' => $invitation_id,
            'invitation_url' => $invitation_url,
            'expires_at' => $expires_at,
            'is_new' => true
        ];
        
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn)) {
            $conn->rollback();
        }
        
        error_log("GENERATE-INVITATION ERROR: " . $e->getMessage());
        error_log("GENERATE-INVITATION ERROR TRACE: " . $e->getTraceAsString());
        
        return [
            'success' => false,
            'error' => 'server_error',
            'message' => 'An error occurred while generating the invitation: ' . $e->getMessage()
        ];
    }
}
?> 