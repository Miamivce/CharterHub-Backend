<?php
/**
 * Generate Invitation Link API Endpoint
 * 
 * This endpoint creates a new invitation link for a client.
 * Only administrators can generate invitation links.
 * Links automatically expire after 7 days and become inactive once used.
 */

// Apply CORS headers first
require_once __DIR__ . '/../../includes/cors-headers.php';
apply_cors_headers();

// Include necessary files
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth-functions.php';
require_once __DIR__ . '/../../includes/response-helpers.php';
require_once __DIR__ . '/direct-auth-helper.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Only POST requests are allowed'
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

// Get JSON data from request body
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// Check if client ID is provided
if (!isset($data['clientId']) || empty($data['clientId'])) {
    send_json_response([
        'success' => false,
        'error' => 'missing_client_id',
        'message' => 'Client ID is required'
    ], 400);
    exit;
}

$client_id = trim($data['clientId']);

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // First get the client's email
    $stmt = $pdo->prepare("
        SELECT id, email FROM {$db_config['table_prefix']}charterhub_users 
        WHERE id = ?
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        // Try looking up in WordPress users table
        $stmt = $pdo->prepare("
            SELECT ID as id, user_email as email 
            FROM {$db_config['table_prefix']}users 
            WHERE ID = ?
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
    }
    
    // NEW IMPROVED CHECK: Check more thoroughly if the client is already registered and has actually completed account setup
    $stmt = $pdo->prepare("
        SELECT id, verified, password, last_login
        FROM {$db_config['table_prefix']}charterhub_users 
        WHERE id = ? OR email = ?
    ");
    $stmt->execute([$client_id, strtolower($client['email'])]);
    $clientAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user has login history - a better indicator of completed setup
    $hasLoginHistory = false;
    if ($clientAccount) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as login_count
            FROM {$db_config['table_prefix']}charterhub_auth_logs 
            WHERE user_id = ? AND action = 'client_login_success'
        ");
        $stmt->execute([$clientAccount['id']]);
        $loginCount = $stmt->fetch(PDO::FETCH_ASSOC);
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
        if (isset($data['force']) && $data['force']) {
            // Log that we're forcing invitation for a registered user
            error_log("GENERATE-INVITATION: Force-generating invitation for registered client ID: {$client_id}");
        } else {
            send_json_response([
                'success' => false,
                'error' => 'client_already_registered',
                'message' => 'This client has already registered and completed account setup. Invitation links cannot be generated for registered clients.'
            ], 400);
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
    
    // Check for existing active invitations - use customer_id instead of email
    $stmt = $pdo->prepare("
        SELECT id, token, email, expires_at 
        FROM {$db_config['table_prefix']}charterhub_invitations 
        WHERE customer_id = ? AND expires_at > NOW() AND used = 0 
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$client_id]);
    $existingInvitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If active invitation exists and force flag is not set, return that invitation
    if ($existingInvitation && (!isset($data['force']) || !$data['force'])) {
        $frontend_url = isset($config['frontend_url']) ? $config['frontend_url'] : $base_url;
        $invitation_url = "{$frontend_url}/register?invited=true&token={$existingInvitation['token']}";
        
        $pdo->commit();
        send_json_response([
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
    
    // Get the current admin user ID
    $admin_id = get_current_user_id();
    
    // Insert the new invitation
    $stmt = $pdo->prepare("
        INSERT INTO {$db_config['table_prefix']}charterhub_invitations (
            token, email, customer_id, created_by, created_at, expires_at
        ) VALUES (
            ?, ?, ?, ?, NOW(), ?
        )
    ");
    $stmt->execute([
        $token,
        strtolower($client['email']),
        $client_id,
        $admin_id,
        $expires_at
    ]);
    
    $invitation_id = $pdo->lastInsertId();
    
    // Log the invitation creation
    $stmt = $pdo->prepare("
        INSERT INTO {$db_config['table_prefix']}charterhub_auth_logs (
            user_id, action, status, ip_address, details, created_at
        ) VALUES (
            ?, 'invitation', 'success', ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $admin_id,
        $_SERVER['REMOTE_ADDR'],
        json_encode([
            'client_id' => $client_id,
            'email' => $client['email'],
            'invitation_id' => $invitation_id,
            'expires_at' => $expires_at
        ])
    ]);
    
    // Commit the transaction
    $pdo->commit();
    
    // Generate the invitation URL
    $frontend_url = isset($config['frontend_url']) ? $config['frontend_url'] : $base_url;
    
    // If using referer-based URL, ensure port is included if needed
    if (isset($_SERVER['HTTP_REFERER']) && strpos($frontend_url, 'localhost') !== false) {
        $port = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PORT);
        if ($port) {
            // Check if the URL already has a port
            $parsedUrl = parse_url($frontend_url);
            if (!isset($parsedUrl['port'])) {
                // Format URL with the port from referer
                $frontend_url = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . ':' . $port;
            }
        }
    }
    
    // Update URL to include the invited=true parameter for consistency with direct-invitations.php
    $invitation_url = "{$frontend_url}/register?invited=true&token={$token}";
    
    // Return the invitation details
    send_json_response([
        'success' => true,
        'message' => 'Invitation generated successfully',
        'invitation_id' => $invitation_id,
        'invitation_url' => $invitation_url,
        'expires_at' => $expires_at,
        'is_new' => true
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    error_log("GENERATE-INVITATION.PHP ERROR: " . $e->getMessage());
    error_log("GENERATE-INVITATION.PHP ERROR TRACE: " . $e->getTraceAsString());
    
    send_json_response([
        'success' => false,
        'error' => 'server_error',
        'message' => 'An error occurred while generating the invitation'
    ], 500);
} 