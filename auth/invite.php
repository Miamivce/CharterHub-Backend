<?php
/**
 * CharterHub Invitation API Endpoint
 * 
 * This file handles creating and sending invitations to new users
 * Only administrators can send invitations
 */

// Include configuration
require_once __DIR__ . '/config.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // In production, limit this to your frontend domain
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check for authorization header (JWT token)
if (!isset($_SERVER['HTTP_AUTHORIZATION']) || empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Extract JWT token
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    $token = null;
    
    // Check if token has "Bearer " prefix
    if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        $token = $matches[1];
    } else {
        throw new Exception('Invalid authorization format');
    }
    
    // Verify JWT token - in a real implementation, use a proper JWT library
    // This is a placeholder for demonstration
    list($header, $payload, $signature) = explode('.', $token);
    $decoded_payload = json_decode(base64_decode($payload), true);
    
    if (!$decoded_payload) {
        throw new Exception('Invalid token format');
    }
    
    // Check token expiration
    if (isset($decoded_payload['exp']) && $decoded_payload['exp'] < time()) {
        throw new Exception('Token has expired');
    }
    
    // Verify user role
    if (!isset($decoded_payload['role']) || $decoded_payload['role'] !== 'administrator') {
        throw new Exception('Insufficient permissions');
    }
    
    // Get user ID from token
    $user_id = $decoded_payload['sub'];
    
    // Get JSON request data
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Validate required fields
    if (empty($data['email'])) {
        throw new Exception('Email is required');
    }
    
    // Validate email format
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Optional booking ID
    $booking_id = isset($data['bookingId']) && !empty($data['bookingId']) ? $data['bookingId'] : null;
    
    // Get database connection
    $pdo = get_db_connection();
    
    // Check if user already exists
    $stmt = $pdo->prepare("
        SELECT ID FROM {$db_config['table_prefix']}users 
        WHERE user_email = :email
    ");
    $stmt->execute(['email' => $data['email']]);
    $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If booking ID is provided, verify it exists
    if ($booking_id) {
        $stmt = $pdo->prepare("
            SELECT id FROM {$db_config['table_prefix']}charterhub_bookings 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $booking_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid booking ID');
        }
        
        // If user exists, check if already associated with this booking
        if ($existing_user) {
            $stmt = $pdo->prepare("
                SELECT id FROM {$db_config['table_prefix']}charterhub_booking_guests 
                WHERE booking_id = :booking_id AND user_id = :user_id
            ");
            $stmt->execute(['booking_id' => $booking_id, 'user_id' => $existing_user['ID']]);
            
            if ($stmt->fetch()) {
                throw new Exception('User is already associated with this booking');
            }
        }
    }
    
    // Generate invitation token
    $token = generate_token();
    
    // Calculate expiration date
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$auth_config['invitation_expiration']} days"));
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Insert invitation
        $stmt = $pdo->prepare("
            INSERT INTO {$db_config['table_prefix']}charterhub_invitations (
                token, email, booking_id, created_by, created_at, expires_at
            ) VALUES (
                :token, :email, :booking_id, :created_by, NOW(), :expires_at
            )
        ");
        
        $stmt->execute([
            'token' => $token,
            'email' => $data['email'],
            'booking_id' => $booking_id,
            'created_by' => $user_id,
            'expires_at' => $expires_at
        ]);
        
        $invitation_id = $pdo->lastInsertId();
        
        // If user exists and booking ID is provided, add user to booking guests directly
        if ($existing_user && $booking_id) {
            $stmt = $pdo->prepare("
                INSERT INTO {$db_config['table_prefix']}charterhub_booking_guests (
                    booking_id, user_id, created_at
                ) VALUES (
                    :booking_id, :user_id, NOW()
                )
            ");
            $stmt->execute(['booking_id' => $booking_id, 'user_id' => $existing_user['ID']]);
            
            // Mark invitation as used since we've already processed it
            $stmt = $pdo->prepare("
                UPDATE {$db_config['table_prefix']}charterhub_invitations 
                SET used = 1 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $invitation_id]);
        }
        
        // Log invitation action
        log_auth_action(
            $user_id,
            'invitation',
            'success',
            [
                'invited_email' => $data['email'],
                'booking_id' => $booking_id,
                'user_exists' => $existing_user ? true : false
            ]
        );
        
        // Commit transaction
        $pdo->commit();
        
        // Generate invitation URL
        $invitation_url = "{$frontend_urls['base_url']}/register?invited=true&token={$token}";
        
        // Send invitation email
        $email_subject = "You've been invited to CharterHub";
        $email_body = "Hello,\n\n";
        $email_body .= "You have been invited to join CharterHub";
        
        if ($booking_id) {
            $email_body .= " and access your yacht charter booking";
        }
        
        $email_body .= ".\n\n";
        $email_body .= "To accept this invitation, please click the link below to create your account:\n\n";
        $email_body .= "{$invitation_url}\n\n";
        $email_body .= "This invitation link will expire in {$auth_config['invitation_expiration']} days.\n\n";
        
        if ($existing_user && $booking_id) {
            $email_body .= "We noticed you already have an account. You've been automatically added to the booking.\n";
            $email_body .= "You can log in directly at {$frontend_urls['login_url']} to view your bookings.\n\n";
        }
        
        $email_body .= "Best regards,\nThe CharterHub Team";
        
        // Send email
        if (function_exists('sendgrid_email')) {
            sendgrid_email($data['email'], $email_subject, $email_body);
        } else if (function_exists('legacy_send_email')) {
            legacy_send_email($data['email'], $email_subject, $email_body);
        } else {
            // Fallback to direct mail function as last resort
            mail($data['email'], $email_subject, $email_body);
        }
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Invitation sent successfully',
            'invitation_id' => $invitation_id,
            'invitation_url' => $invitation_url,
            'already_processed' => ($existing_user && $booking_id) ? true : false
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log the error
    error_log("Invitation error: " . $e->getMessage());
    
    // Log failed invitation if admin user ID is available
    if (isset($user_id)) {
        log_auth_action(
            $user_id,
            'invitation',
            'failure',
            [
                'email' => $data['email'] ?? null,
                'booking_id' => $booking_id ?? null,
                'reason' => $e->getMessage()
            ]
        );
    }
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 