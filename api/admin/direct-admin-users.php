<?php
/**
 * Direct Admin Users CRUD Endpoint
 * 
 * This endpoint handles all admin user operations without relying on
 * external JWT libraries or middleware.
 * 
 * Supports:
 * - GET: List all admin users
 * - POST: Create or update an admin user
 * 
 * FOR DEVELOPMENT USE ONLY - NOT FOR PRODUCTION
 */

// Include auth helper
require_once __DIR__ . '/direct-auth-helper.php';

// Enable CORS - Must be called before any output or processing
apply_cors_headers();

// Initialize response
$response = [
    'success' => false,
    'message' => 'Initializing request',
];

// Ensure admin access
ensure_admin_access();

// Handle different HTTP methods
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        handle_get_request();
        break;
    case 'POST':
        handle_post_request();
        break;
    case 'DELETE':
        handle_delete_request();
        break;
    default:
        json_response([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
}

/**
 * Handle GET request - List admin users
 */
function handle_get_request() {
    $conn = get_database_connection();
    
    // Get query parameters
    $role = isset($_GET['role']) ? sanitize_input($_GET['role']) : null;
    
    // Build query
    $query = "SELECT id, email, username, first_name, last_name, phone_number, company, role, verified 
              FROM wp_charterhub_users";
    
    // Add conditions
    $conditions = [];
    $params = [];
    $types = "";
    
    if ($role) {
        $conditions[] = "role = ?";
        $params[] = $role;
        $types .= "s";
    }
    
    if (count($conditions) > 0) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }
    
    // Prepare and execute query
    $stmt = $conn->prepare($query);
    
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Fetch users
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => $row['id'],
            'email' => $row['email'],
            'username' => $row['username'],
            'firstName' => $row['first_name'],
            'lastName' => $row['last_name'],
            'phone' => $row['phone_number'],
            'company' => $row['company'],
            'role' => $row['role'],
            'verified' => (bool)$row['verified']
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    // Return users
    json_response([
        'success' => true,
        'message' => 'Users retrieved successfully',
        'users' => $users,
        'meta' => [
            'total' => count($users),
            'role_filter' => $role
        ]
    ]);
}

/**
 * Handle POST request - Create or update admin user
 */
function handle_post_request() {
    // Read and decode request body
    $input_raw = file_get_contents('php://input');
    error_log("Received create/update admin user request body: " . $input_raw);
    
    $input = json_decode($input_raw, true);
    
    if (!$input) {
        error_log("Failed to parse JSON request body");
        json_response([
            'success' => false,
            'message' => 'Invalid request body - unable to parse JSON'
        ], 400);
    }
    
    error_log("Parsed create/update admin user request data: " . print_r($input, true));
    
    $conn = get_database_connection();
    
    // Check if this is an update (ID provided) or create
    $is_update = isset($input['id']) && !empty($input['id']);
    error_log("Admin user operation: " . ($is_update ? "UPDATE user ID {$input['id']}" : "CREATE new user"));
    
    // For new admin user creation, verify the creator's identity
    if (!$is_update && isset($input['creatorEmail']) && isset($input['creatorPassword'])) {
        $creator_email = sanitize_input($input['creatorEmail']);
        $creator_password = $input['creatorPassword'];
        
        error_log("Verifying creator identity: " . $creator_email);
        
        // Try to find creator by email
        $stmt = $conn->prepare("SELECT id, password FROM wp_charterhub_users WHERE email = ? AND role = 'admin'");
        $stmt->bind_param("s", $creator_email);
        $stmt->execute();
        $result = $stmt->get_result();
        $creator = $result->fetch_assoc();
        $stmt->close();
        
        if (!$creator) {
            error_log("Creator not found or not an admin: " . $creator_email);
            json_response([
                'success' => false,
                'message' => 'Creator verification failed: Admin not found'
            ], 401);
        }
        
        // Verify password
        if (!password_verify($creator_password, $creator['password'])) {
            error_log("Creator password verification failed for: " . $creator_email);
            json_response([
                'success' => false,
                'message' => 'Creator verification failed: Invalid password'
            ], 401);
        }
        
        error_log("Creator verified successfully: " . $creator_email);
    } else if (!$is_update) {
        // For new user creation without creator verification, require admin auth check
        if (!is_admin_user()) {
            error_log("Attempt to create admin without verification or valid admin session");
            json_response([
                'success' => false,
                'message' => 'Admin verification required to create new admin users'
            ], 401);
        }
    }
    
    // Validate required fields
    if (!$is_update) {
        // For new user, email and password are required
        if (empty($input['email']) || empty($input['password'])) {
            error_log("Missing required fields for new user: " . 
                      (empty($input['email']) ? "email " : "") . 
                      (empty($input['password']) ? "password" : ""));
            
            json_response([
                'success' => false,
                'message' => 'Email and password are required for new users'
            ], 400);
        }
        
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE email = ?");
        $stmt->bind_param("s", $input['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $stmt->close();
            error_log("User creation failed - email already exists: {$input['email']}");
            json_response([
                'success' => false,
                'message' => 'Email already exists'
            ], 400);
        }
        $stmt->close();
    }
    
    // Ensure role is admin
    $role = 'admin';
    
    if ($is_update) {
        // Update existing user
        $update_fields = [];
        $params = [];
        $types = "";
        
        // Only update fields that are provided
        if (isset($input['email'])) {
            $update_fields[] = "email = ?";
            $params[] = sanitize_input($input['email']);
            $types .= "s";
        }
        
        if (isset($input['username'])) {
            $update_fields[] = "username = ?";
            $params[] = sanitize_input($input['username']);
            $types .= "s";
        }
        
        if (isset($input['firstName'])) {
            $update_fields[] = "first_name = ?";
            $params[] = sanitize_input($input['firstName']);
            $types .= "s";
        }
        
        if (isset($input['lastName'])) {
            $update_fields[] = "last_name = ?";
            $params[] = sanitize_input($input['lastName']);
            $types .= "s";
        }
        
        // Calculate display_name if first or last name was updated
        if (isset($input['firstName']) || isset($input['lastName'])) {
            // Get current user data to create display name with new + existing name components
            $stmt = $conn->prepare("SELECT first_name, last_name FROM wp_charterhub_users WHERE id = ?");
            $stmt->bind_param("i", $input['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $current_user = $result->fetch_assoc();
            $stmt->close();
            
            $first = isset($input['firstName']) ? $input['firstName'] : $current_user['first_name'];
            $last = isset($input['lastName']) ? $input['lastName'] : $current_user['last_name'];
            
            // Update display_name based on new name values
            $display_name = trim($first . ' ' . $last);
            if (!empty($display_name)) {
                $update_fields[] = "display_name = ?";
                $params[] = $display_name;
                $types .= "s";
            }
        }
        
        if (isset($input['display_name'])) {
            $update_fields[] = "display_name = ?";
            $params[] = sanitize_input($input['display_name']);
            $types .= "s";
        }
        
        if (isset($input['phone'])) {
            $update_fields[] = "phone_number = ?";
            $params[] = sanitize_input($input['phone']);
            $types .= "s";
        }
        
        if (isset($input['company'])) {
            $update_fields[] = "company = ?";
            $params[] = sanitize_input($input['company']);
            $types .= "s";
        }
        
        if (isset($input['password']) && !empty($input['password'])) {
            $update_fields[] = "password = ?";
            $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
            $params[] = $hashed_password;
            $types .= "s";
        }
        
        // Always set role to admin
        $update_fields[] = "role = ?";
        $params[] = $role;
        $types .= "s";
        
        // Add ID to params
        $params[] = $input['id'];
        $types .= "i";
        
        if (count($update_fields) > 0) {
            $query = "UPDATE wp_charterhub_users SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                json_response([
                    'success' => false,
                    'message' => 'User not found or no changes made'
                ], 404);
            }
            
            $stmt->close();
        }
        
        // Get updated user
        $stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, role, verified 
                              FROM wp_charterhub_users WHERE id = ?");
        $stmt->bind_param("i", $input['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            json_response([
                'success' => false,
                'message' => 'Failed to retrieve updated user'
            ], 500);
        }
        
        $user = $result->fetch_assoc();
        $stmt->close();
        
        json_response([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'],
                'phone' => $user['phone_number'],
                'company' => $user['company'],
                'role' => $user['role'],
                'verified' => (bool)$user['verified']
            ]
        ]);
    } else {
        // Create new user
        $email = sanitize_input($input['email']);
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        
        // Generate a username if one is not provided
        if (isset($input['username']) && !empty($input['username'])) {
            $username = sanitize_input($input['username']);
        } else {
            // Generate a username from email (part before @) with a random suffix
            $email_parts = explode('@', $email);
            $base_username = $email_parts[0];
            $random_suffix = substr(md5(time() . rand(1000, 9999)), 0, 8);
            $username = $base_username . '_' . $random_suffix;
            
            // Ensure username is unique
            $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // If username exists, add another random suffix
                $random_suffix = substr(md5(time() . rand(10000, 99999)), 0, 10);
                $username = $base_username . '_' . $random_suffix;
            }
            $stmt->close();
        }
        
        $first_name = isset($input['firstName']) ? sanitize_input($input['firstName']) : '';
        $last_name = isset($input['lastName']) ? sanitize_input($input['lastName']) : '';
        $phone = isset($input['phone']) ? sanitize_input($input['phone']) : '';
        $company = isset($input['company']) ? sanitize_input($input['company']) : '';
        
        // Always set verified to 1 for admin users created by another admin
        $verified = 1;
        
        // Create display name from first and last name if not provided
        $display_name = isset($input['display_name']) ? sanitize_input($input['display_name']) : 
                        trim($first_name . ' ' . $last_name);
        
        // If display_name is still empty, use email as fallback
        if (empty(trim($display_name))) {
            $display_name = $email;
        }
        
        // Debugging values
        error_log("Creating new admin user with data:");
        error_log("Email: $email");
        error_log("Username: $username");
        error_log("First Name: $first_name");
        error_log("Last Name: $last_name");
        error_log("Display Name: $display_name");
        error_log("Phone: $phone");
        error_log("Company: $company");
        error_log("Role: $role");
        error_log("Verified: $verified");
        
        // Prepare the query - now including display_name
        $insert_query = "INSERT INTO wp_charterhub_users 
                        (email, password, username, first_name, last_name, display_name, phone_number, company, role, verified, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        try {
            $stmt = $conn->prepare($insert_query);
            
            if (!$stmt) {
                error_log("Failed to prepare insert statement: " . $conn->error);
                json_response([
                    'success' => false,
                    'message' => 'Database error: Failed to prepare statement - ' . $conn->error
                ], 500);
            }
            
            $stmt->bind_param("sssssssssi", $email, $password, $username, $first_name, $last_name, $display_name, $phone, $company, $role, $verified);
            $result = $stmt->execute();
            
            if (!$result) {
                error_log("Failed to execute insert statement: " . $stmt->error);
                $stmt->close();
                json_response([
                    'success' => false,
                    'message' => 'Database error: Failed to create user - ' . $stmt->error
                ], 500);
            }
            
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                error_log("No rows affected by insert statement");
                json_response([
                    'success' => false,
                    'message' => 'Failed to create user: No rows inserted'
                ], 500);
            }
            
            $user_id = $stmt->insert_id;
            $stmt->close();
            
            error_log("Successfully created user with ID: $user_id");
            
            // Get created user
            $stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, role, verified 
                                FROM wp_charterhub_users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $stmt->close();
                error_log("Failed to retrieve created user with ID: $user_id");
                json_response([
                    'success' => false,
                    'message' => 'User created but failed to retrieve user details'
                ], 500);
            }
            
            $user = $result->fetch_assoc();
            $stmt->close();
            
            json_response([
                'success' => true,
                'message' => 'User created successfully',
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'firstName' => $user['first_name'],
                    'lastName' => $user['last_name'],
                    'phone' => $user['phone_number'],
                    'company' => $user['company'],
                    'role' => $user['role'],
                    'verified' => (bool)$user['verified']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Exception during user creation: " . $e->getMessage());
            json_response([
                'success' => false,
                'message' => 'Exception during user creation: ' . $e->getMessage()
            ], 500);
        }
    }
}

/**
 * Handle DELETE request - Delete admin user
 */
function handle_delete_request() {
    // Get user ID from query string
    $user_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if (!$user_id) {
        json_response([
            'success' => false,
            'message' => 'User ID is required'
        ], 400);
    }
    
    $conn = get_database_connection();
    
    // Check if user exists and is an admin
    $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE id = ? AND role = 'admin'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        json_response([
            'success' => false,
            'message' => 'Admin user not found'
        ], 404);
    }
    $stmt->close();
    
    // Delete user
    $stmt = $conn->prepare("DELETE FROM wp_charterhub_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        $stmt->close();
        json_response([
            'success' => false,
            'message' => 'Failed to delete user'
        ], 500);
    }
    
    $stmt->close();
    $conn->close();
    
    json_response([
        'success' => true,
        'message' => 'User deleted successfully'
    ]);
}
?> 