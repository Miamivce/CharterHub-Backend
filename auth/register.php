<?php
/**
 * CharterHub Registration API Endpoint
 * 
 * This endpoint registers a new client into the wp_charterhub_users table.
 * It stores user data (username, email, hashed password, registration date,
 * display name, first name, and last name). Upon successful registration, it returns
 * a verification token and URL to verify the email address.
 */

// Define CHARTERHUB_LOADED constant and enable DEBUG mode
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Increase execution time limit for this endpoint
set_time_limit(90); // Set to 90 seconds

// Include configuration and CORS handling
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/global-cors.php';
require_once __DIR__ . '/../utils/database.php';  // Include the database abstraction layer
require_once __DIR__ . '/jwt-core.php';
// Apply CORS headers explicitly
apply_global_cors(['POST', 'OPTIONS']);

header('Content-Type: application/json; charset=UTF-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(['success' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed'], 405);
}

// Read and validate input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

error_log("REGISTER.PHP: Received registration data: " . json_encode($data));

if (!is_array($data)) {
    error_log("REGISTER.PHP: Invalid input format");
    send_json_response(['success' => false, 'error' => 'invalid_input', 'message' => 'Invalid input format'], 400);
}

// Validate required fields
$required_fields = ['email', 'password', 'firstName', 'lastName'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (!isset($data[$field]) || empty($data[$field])) {
        $missing_fields[] = $field;
    }
}

if (!empty($missing_fields)) {
    error_log("REGISTER.PHP: Missing required fields: " . implode(', ', $missing_fields));
    send_json_response([
        'success' => false,
        'error' => 'missing_fields',
        'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
    ], 400);
}

// Check if this is an invited registration
$isInvited = isset($data['isInvited']) && $data['isInvited'] === true;
$clientId = isset($data['clientId']) ? intval($data['clientId']) : null;
$invitationToken = isset($data['invitationToken']) ? trim($data['invitationToken']) : null;

if ($isInvited) {
    error_log("REGISTER.PHP: Processing invited registration");
    if ($clientId) {
        error_log("REGISTER.PHP: Client ID provided: " . $clientId);
    } else {
        error_log("REGISTER.PHP: No client ID provided in invitation data");
    }
    
    if ($invitationToken) {
        error_log("REGISTER.PHP: Invitation token provided: " . substr($invitationToken, 0, 8) . "...");
        
        // Verify the invitation token exists and is valid
        $invitation = fetchRow(
            "SELECT * FROM {$db_config['table_prefix']}charterhub_invitations WHERE token = ? AND used = 0",
            [$invitationToken]
        );
        
        if (!$invitation) {
            error_log("REGISTER.PHP: Invalid or already used invitation token: " . substr($invitationToken, 0, 8) . "...");
            send_json_response([
                'success' => false,
                'error' => 'invalid_invitation',
                'message' => 'Invalid or already used invitation token'
            ], 400);
            exit;
        }
        
        // If clientId was not provided or doesn't match, use the one from the invitation
        if (!$clientId || $clientId != $invitation['customer_id']) {
            error_log("REGISTER.PHP: Using customer_id from invitation: " . $invitation['customer_id'] . " instead of provided ID: " . $clientId);
            $clientId = $invitation['customer_id'];
        }
    }
}

try {
    error_log("REGISTER.PHP: Attempting database operations");
    
    // For ALL registration types, check if email exists
    $existing_email_user = fetchRow(
        "SELECT id FROM {$db_config['table_prefix']}charterhub_users WHERE email = ?",
        [strtolower($data['email'])]
    );
    
    // For normal registrations, any existing email is an error
    if ($existing_email_user && !$isInvited) {
        error_log("REGISTER.PHP: Email already exists for normal registration: " . $data['email']);
        send_json_response([
            'success' => false,
            'error' => 'email_exists',
            'message' => 'Email already exists'
        ], 400);
        exit;
    }
    
    // For invited registrations, verify the email belongs to the correct user
    if ($existing_email_user && $isInvited && $clientId) {
        // If email exists but for a different user than the invited one
        if ($existing_email_user['id'] !== $clientId) {
            error_log("REGISTER.PHP: Email already exists for a different user (ID: " . $existing_email_user['id'] . ")");
            send_json_response([
                'success' => false,
                'error' => 'email_exists',
                'message' => 'This email is already associated with another account. Please use a different email address.'
            ], 400);
            exit;
        }
    }
    
    // Check if this is a special handling path for invited registrations
    if ($isInvited && $clientId) {
        error_log("REGISTER.PHP: This is an invited registration with client ID: " . $clientId);
        
        // Check if there's an existing customer record - try both table names for compatibility
        $existingCustomer = null;
        
        try {
            $existingCustomer = fetchRow(
                "SELECT * FROM {$db_config['table_prefix']}charterhub_users WHERE id = ?",
                [$clientId]
            );
            error_log("REGISTER.PHP: Looked up customer in main table: " . ($existingCustomer ? "Found" : "Not found"));
        } catch (Exception $e) {
            error_log("REGISTER.PHP: Error looking up customer in main table: " . $e->getMessage());
        }
        
        // If not found in main table, try legacy table
        if (!$existingCustomer) {
            try {
                $existingCustomer = fetchRow(
                    "SELECT * FROM {$db_config['table_prefix']}users WHERE ID = ?",
                    [$clientId]
                );
                error_log("REGISTER.PHP: Looked up customer in legacy table: " . ($existingCustomer ? "Found" : "Not found"));
            } catch (Exception $e) {
                error_log("REGISTER.PHP: Error looking up customer in legacy table: " . $e->getMessage());
            }
        }

        if ($existingCustomer) {
            error_log("REGISTER.PHP: Found existing customer record for client ID: " . $clientId);
            
            // For invited registrations, update the existing customer record directly
            beginTransaction();
            try {
                // Hash the password
                $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
                
                // Generate verification token
                $verification_token = bin2hex(random_bytes(32));
                
                // Normalize phone number field - handle both phoneNumber and phone_number
                $phone_number = null;
                if (isset($data['phoneNumber']) && !empty($data['phoneNumber'])) {
                    $phone_number = $data['phoneNumber'];
                } elseif (isset($data['phone_number']) && !empty($data['phone_number'])) {
                    $phone_number = $data['phone_number'];
                }
                error_log("REGISTER.PHP: (UPDATE) Normalized phone number: " . ($phone_number ?? 'NULL'));
                
                // Update the existing customer record
                executeUpdate(
                    "UPDATE {$db_config['table_prefix']}charterhub_users 
                    SET password = ?, first_name = ?, last_name = ?, display_name = ?, 
                    phone_number = ?, company = ?, verified = 0, verification_token = ?, 
                    email = ?, updated_at = NOW()
                    WHERE id = ?",
                    [
                        $hashed_password,
                        $data['firstName'],
                        $data['lastName'],
                        $data['firstName'] . ' ' . $data['lastName'],
                        $phone_number, // Use normalized phone number
                        isset($data['company']) ? $data['company'] : null,
                        $verification_token,
                        strtolower($data['email']),
                        $clientId
                    ]
                );
                
                error_log("REGISTER.PHP: Updated existing customer with ID: " . $clientId);
                
                // Log the update action
                executeUpdate(
                    "INSERT INTO {$db_config['table_prefix']}charterhub_auth_logs 
                    (user_id, action, status, ip_address, user_agent, details) 
                    VALUES (?, 'invited_registration_update', 'success', ?, ?, ?)",
                    [
                        $clientId,
                        $_SERVER['REMOTE_ADDR'] ?? '::1',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                        json_encode([
                            'email' => $existingCustomer['email'],
                            'firstName' => $data['firstName'],
                            'lastName' => $data['lastName'],
                            'clientId' => $clientId
                        ])
                    ]
                );
                
                // Mark the invitation as used if it exists
                try {
                    // Find any active invitations for this client
                    $invitation = fetchRow(
                        "SELECT id FROM {$db_config['table_prefix']}charterhub_invitations 
                         WHERE email = ? AND used = 0 AND expires_at > NOW()",
                        [strtolower($data['email'])]
                    );
                    
                    // If found, mark as used
                    if ($invitation && isset($invitation['id'])) {
                        executeUpdate(
                            "UPDATE {$db_config['table_prefix']}charterhub_invitations 
                             SET used = 1, used_at = NOW() 
                             WHERE id = ?",
                            [$invitation['id']]
                        );
                        error_log("REGISTER.PHP: Marked invitation as used: " . $invitation['id']);
                    }
                } catch (Exception $e) {
                    error_log("REGISTER.PHP WARNING: Could not mark invitation as used: " . $e->getMessage());
                    // Non-critical error, continue with registration
                }
                
                commitTransaction();
                
                // Generate verification URL
                $verification_url = "/verify-email?token=" . $verification_token;
                
                // Return success response with user ID
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log("REGISTER.PHP: Sending success response for updated invited customer");
                    send_json_response([
                        'success' => true,
                        'message' => 'Account updated successfully. Please verify your email.',
                        'user_id' => $clientId,
                        'dev_mode' => true,
                        'verification' => [
                            'url' => $verification_url,
                            'token' => $verification_token
                        ]
                    ]);
                } else {
                    // In production, send verification email
                    $email_subject = "Verify your CharterHub account";
                    $email_body = "Hello {$data['firstName']},\n\n";
                    $email_body .= "Thank you for completing your registration with CharterHub. Please click the link below to verify your email address:\n\n";
                    $email_body .= $frontend_urls['base_url'] . "/verify-email?token=" . $verification_token . "\n\n";
                    $email_body .= "This link will expire in 24 hours.\n\n";
                    $email_body .= "Best regards,\nThe CharterHub Team";
                    
                    send_email($data['email'], $email_subject, $email_body);
                    
                    send_json_response([
                        'success' => true,
                        'message' => 'Registration completed successfully. Please check your email for verification instructions.'
                    ]);
                }
                
                exit; // Stop further processing
            } catch (Exception $e) {
                rollbackTransaction();
                error_log("REGISTER.PHP ERROR: Transaction rolled back during invited customer update: " . $e->getMessage());
                error_log("REGISTER.PHP ERROR: Stack trace: " . $e->getTraceAsString());
                throw $e;
            }
        } else {
            error_log("REGISTER.PHP: No existing customer found for client ID: " . $clientId . ". Proceeding with normal registration.");
        }
    }
    
    // Continue with the normal registration flow if we didn't handle an invited registration above
    
    // Log what fields we're inserting for debugging
    error_log("REGISTER.PHP: Preparing to insert user with email: " . $data['email'] . ", first_name: " . $data['firstName'] . ", last_name: " . $data['lastName']);
    
    // Start transaction
    beginTransaction();
    error_log("REGISTER.PHP: Started database transaction");
    
    try {
        error_log("REGISTER.PHP: Starting normal registration process");
        
        // Hash the password
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Generate verification token and set verified=1 directly (skipping verification)
        // $verification_token = bin2hex(random_bytes(32));
        // error_log("REGISTER.PHP: Generated verification token for " . $data['email']);
        
        // DEBUG: Show the SQL that will be executed
        $sql = "
            INSERT INTO {$db_config['table_prefix']}charterhub_users 
            (email, password, first_name, last_name, display_name, 
            phone_number, company, role, verified, country, address, notes,
            created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'client', 1, ?, ?, ?,
            NOW(), NOW())
        ";
        
        error_log("REGISTER.PHP: About to execute SQL: " . $sql);
        error_log("REGISTER.PHP: With parameters: " . json_encode([
            strtolower($data['email']),
            '[HASHED PASSWORD]',
            $data['firstName'],
            $data['lastName'],
            $data['firstName'] . ' ' . $data['lastName'],
            isset($data['phoneNumber']) ? $data['phoneNumber'] : null,
            isset($data['company']) ? $data['company'] : null,
            isset($data['country']) ? $data['country'] : null,
            isset($data['address']) ? $data['address'] : null,
            isset($data['notes']) ? $data['notes'] : null
        ]));
        
        // Normalize phone number field - handle both phoneNumber and phone_number
        $phone_number = null;
        if (isset($data['phoneNumber']) && !empty($data['phoneNumber'])) {
            $phone_number = $data['phoneNumber'];
        } elseif (isset($data['phone_number']) && !empty($data['phone_number'])) {
            $phone_number = $data['phone_number'];
        }
        error_log("REGISTER.PHP: (INSERT) Normalized phone number: " . ($phone_number ?? 'NULL'));
        
        // Get the company name if provided
        $company = isset($data['company']) ? $data['company'] : null;
        
        // Insert new user
        executeUpdate($sql, [
            strtolower($data['email']),
            $hashed_password,
            $data['firstName'],
            $data['lastName'],
            $data['firstName'] . ' ' . $data['lastName'],
            $phone_number, // Use the normalized phone number
            $company,
            isset($data['country']) ? $data['country'] : null,
            isset($data['address']) ? $data['address'] : null,
            isset($data['notes']) ? $data['notes'] : null
        ]);
        
        // Get the ID of the new user
        $user_id = lastInsertId();
        error_log("REGISTER.PHP: User registered with ID: " . $user_id);

        // In development mode, return a simple success response
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("REGISTER.PHP: Sending success response");
            send_json_response([
                'success' => true,
                'message' => 'Registration successful. Your account is now active.',
                'user_id' => $user_id,
                'dev_mode' => true
            ]);
        } else {
            // In production, send welcome email
            $email_subject = "Welcome to CharterHub";
            $email_body = "Hello {$data['firstName']},\n\n";
            $email_body .= "Thank you for registering with CharterHub. Your account is now active.\n\n";
            $email_body .= "You can login at " . $frontend_urls['base_url'] . "/login\n\n";
            $email_body .= "Best regards,\nThe CharterHub Team";
            
            send_email($data['email'], $email_subject, $email_body);
            
            send_json_response([
                'success' => true,
                'message' => 'Registration successful. Your account is now active.'
            ]);
        }
        
    } catch (Exception $e) {
        rollbackTransaction();
        error_log("REGISTER.PHP ERROR: Transaction rolled back due to error: " . $e->getMessage());
        error_log("REGISTER.PHP ERROR: Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("REGISTER.PHP ERROR: " . $e->getMessage());
    error_log("REGISTER.PHP ERROR: Stack trace: " . $e->getTraceAsString());
    send_json_response([
        'success' => false,
        'error' => 'server_error',
        'message' => DEBUG_MODE ? $e->getMessage() : 'An error occurred during registration'
    ], 500);
} 