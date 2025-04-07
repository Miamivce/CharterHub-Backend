<?php
/**
 * Check Client Invitation Status Endpoint
 * 
 * This endpoint checks if a client has any associated invitations and returns their status.
 * It's designed for use in the admin dashboard to show if invitations have been used.
 */

// Apply CORS headers first
require_once __DIR__ . '/../../includes/cors-headers.php';
apply_cors_headers();

// Include necessary files
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth-functions.php';
require_once __DIR__ . '/../../includes/response-helpers.php';
require_once __DIR__ . '/direct-auth-helper.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only GET requests are allowed'
    ], 405);
    exit;
}

// Ensure the user is authenticated as an admin
if (!is_admin_user()) {
    send_json_response([
        'success' => false,
        'error' => 'unauthorized',
        'message' => 'Unauthorized. Admin access required.'
    ], 401);
    exit;
}

// Check if client ID is provided
if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
    send_json_response([
        'success' => false,
        'error' => 'missing_client_id',
        'message' => 'Client ID is required'
    ], 400);
    exit;
}

$client_id = trim($_GET['client_id']);

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // First get the client's email
    $stmt = $pdo->prepare("
        SELECT email FROM {$db_config['table_prefix']}charterhub_users 
        WHERE id = ?
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        send_json_response([
            'success' => false,
            'error' => 'client_not_found',
            'message' => 'Client not found'
        ], 404);
        exit;
    }
    
    // Find invitations for this client's email
    $stmt = $pdo->prepare("
        SELECT id, token, email, created_at, expires_at, used, used_at
        FROM {$db_config['table_prefix']}charterhub_invitations 
        WHERE email = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([strtolower($client['email'])]);
    $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    send_json_response($response);
    
} catch (Exception $e) {
    error_log("CHECK-INVITATION-STATUS.PHP ERROR: " . $e->getMessage());
    error_log("CHECK-INVITATION-STATUS.PHP ERROR: Stack trace: " . $e->getTraceAsString());
    
    send_json_response([
        'success' => false,
        'error' => 'server_error',
        'message' => 'An error occurred while checking invitation status'
    ], 500);
} 