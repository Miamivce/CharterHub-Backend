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
        
        // Removing verification token as the column doesn't exist
        error_log("REGISTER.PHP: Skipping verification token generation since column doesn't exist");
        
        // DEBUG: Show the SQL that will be executed - removed verification_token
        $sql = "
            INSERT INTO {$db_config['table_prefix']}charterhub_users 
            (email, password, first_name, last_name, display_name, 
            phone_number, company, role, verified, 
            created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'client', 1, 
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
            isset($data['company']) ? $data['company'] : null
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
        
        // Insert new user using the database abstraction layer
        executeUpdate($sql, [
            strtolower($data['email']),
            $hashed_password,
            $data['firstName'],
            $data['lastName'],
            $data['firstName'] . ' ' . $data['lastName'],
            $phone_number, // Use the normalized phone number
            $company,
        ]);
        
        // Log the phone number value for debugging
        error_log("REGISTER.PHP: Phone number value: " . (isset($data['phoneNumber']) ? $data['phoneNumber'] : 'NULL') . 
                  ", Phone number alternative field: " . (isset($data['phone_number']) ? $data['phone_number'] : 'NULL') .
                  ", Normalized value used: " . ($phone_number ?? 'NULL'));
        
        $user_id = lastInsertId();
        error_log("REGISTER.PHP: New user inserted with ID: " . $user_id);
        
        // Log registration with full action name - UPDATED to use wp_charterhub_auth_logs
        executeUpdate(
            "INSERT INTO {$db_config['table_prefix']}charterhub_auth_logs 
            (user_id, action, status, ip_address, user_agent, details) 
            VALUES (?, 'register', 'success', ?, ?, ?)",
            [
                $user_id,
                $_SERVER['REMOTE_ADDR'] ?? '::1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                json_encode([
                    'email' => $data['email'],
                    'firstName' => $data['firstName'],
                    'lastName' => $data['lastName'],
                ])
            ]
        );
        error_log("REGISTER.PHP: Auth log created for new user");
        
        // Now mark the invitation as used in the database
        if ($isInvited && isset($data['invitationToken']) && !empty($data['invitationToken'])) {
            try {
                $invitationToken = trim($data['invitationToken']);
                error_log("REGISTER.PHP: Marking invitation token as used: " . substr($invitationToken, 0, 8) . "...");
                
                // Update the invitation record
                $updateResult = executeUpdate(
                    "UPDATE {$db_config['table_prefix']}charterhub_invitations 
                     SET used = 1, used_at = NOW(), used_by_user_id = ? 
                     WHERE token = ? AND used = 0",
                    [$user_id, $invitationToken]
                );
                
                if ($updateResult) {
                    error_log("REGISTER.PHP: Successfully marked invitation as used. Rows affected: " . $updateResult);
                } else {
                    error_log("REGISTER.PHP: No rows affected when marking invitation as used. Token: " . substr($invitationToken, 0, 8) . "...");
                }
            } catch (Exception $inviteError) {
                // Log but don't fail registration if invitation update fails
                error_log("REGISTER.PHP ERROR: Failed to mark invitation as used: " . $inviteError->getMessage());
            }
        }
        
        // Commit the transaction
        commitTransaction();
        error_log("REGISTER.PHP: Transaction committed successfully");
        
        // If this is an invited registration, link the user to the customer record
        if ($isInvited && $clientId) {
            try {
                error_log("REGISTER.PHP: Linking new user ID {$user_id} to customer ID {$clientId}");
                
                // Start a new transaction for customer linking
                beginTransaction();
                
                // First try to update the customer record in charterhub_customers table
                $customerUpdateCount = executeUpdate(
                    "UPDATE {$db_config['table_prefix']}charterhub_customers 
                    SET user_id = ?, updated_at = NOW(), status = 'active'
                    WHERE id = ?",
                    [$user_id, $clientId]
                );
                
                error_log("REGISTER.PHP: Updated {$customerUpdateCount} rows in charterhub_customers table");
                
                // If no rows were updated in charterhub_customers, this might be a client record
                // stored directly in charterhub_users table with a link through ID
                if ($customerUpdateCount == 0) {
                    error_log("REGISTER.PHP: No rows updated in customers table, checking if client exists in users table");
                    
                    // Check if the client record exists in wp_charterhub_users
                    $clientRecord = fetchRow(
                        "SELECT id, role FROM {$db_config['table_prefix']}charterhub_users WHERE id = ?",
                        [$clientId]
                    );
                    
                    if ($clientRecord) {
                        error_log("REGISTER.PHP: Found client record in charterhub_users table with ID {$clientId}");
                        
                        // This is a special case - we might want to merge the records or update metadata
                        // For now, just log this situation
                        error_log("REGISTER.PHP: Registration was for an existing user record. Creating special link record.");
                        
                        // Check if the charterhub_user_links table exists, create it if not
                        $tableExists = fetchColumn(
                            "SELECT COUNT(*) 
                            FROM information_schema.tables 
                            WHERE table_schema = DATABASE() 
                            AND table_name = '{$db_config['table_prefix']}charterhub_user_links'",
                            []
                        );
                        
                        if ($tableExists == 0) {
                            error_log("REGISTER.PHP: Creating charterhub_user_links table");
                            
                            executeUpdate("
                                CREATE TABLE IF NOT EXISTS {$db_config['table_prefix']}charterhub_user_links (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    user_id INT NOT NULL,
                                    linked_user_id INT NOT NULL,
                                    link_type VARCHAR(50) NOT NULL,
                                    created_at DATETIME NOT NULL,
                                    updated_at DATETIME NULL,
                                    metadata TEXT NULL
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                            ", []);
                        }
                        
                        // Add a link record in a metadata table 
                        executeUpdate(
                            "INSERT INTO {$db_config['table_prefix']}charterhub_user_links 
                            (user_id, linked_user_id, link_type, created_at) 
                            VALUES (?, ?, 'invited_registration', NOW())",
                            [$user_id, $clientId]
                        );
                    } else {
                        error_log("REGISTER.PHP: Client ID {$clientId} not found in either customers or users table");
                    }
                }
                
                // Commit the customer linking transaction
                commitTransaction();
                error_log("REGISTER.PHP: Successfully completed linking process for user ID {$user_id} and client ID {$clientId}");
            } catch (Exception $e) {
                // If linking fails, log the error but don't abort the registration
                error_log("REGISTER.PHP ERROR: Failed to link user to customer: " . $e->getMessage());
                error_log("REGISTER.PHP ERROR: Stack trace: " . $e->getTraceAsString());
                rollbackTransaction();
            }
        }

        // Generate verification URL
        $verification_url = "/verify-email?token=" . $verification_token;
        
        // In development mode, return the verification URL directly
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("REGISTER.PHP: Sending success response with verification token");
            send_json_response([
                'success' => true,
                'message' => 'Registration successful. Please verify your email.',
                'user_id' => $user_id,
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
            $email_body .= "Thank you for registering with CharterHub. Please click the link below to verify your email address:\n\n";
            $email_body .= $frontend_urls['base_url'] . "/verify-email?token=" . $verification_token . "\n\n";
            $email_body .= "This link will expire in 24 hours.\n\n";
            $email_body .= "Best regards,\nThe CharterHub Team";
            
            send_email($data['email'], $email_subject, $email_body);
            
            send_json_response([
                'success' => true,
                'message' => 'Registration successful. Please check your email for verification instructions.'
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