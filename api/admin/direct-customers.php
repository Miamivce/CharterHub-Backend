<?php
/**
 * Direct Customers CRUD Endpoint
 * 
 * This endpoint handles admin access to customer data without relying on
 * external JWT libraries or middleware.
 * 
 * Supports:
 * - GET: List all customers/clients
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

// Check for X-HTTP-Method-Override header
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $override = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    if (in_array($override, ['PUT', 'DELETE'])) {
        error_log("Using method override: $override instead of POST");
        $method = $override;
    }
}

// Handle different HTTP methods
switch ($method) {
    case 'GET':
        handle_get_request();
        break;
    case 'POST':
        handle_post_request();
        break;
    case 'PUT':
        handle_post_request(); // Reuse the post handler for PUT
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
 * Handle GET request - List all customers (clients) or a single customer by ID
 */
function handle_get_request() {
    $conn = get_database_connection();
    
    // Check if an ID was provided for a single customer lookup
    $single_customer_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if ($single_customer_id) {
        // Build query for a single customer
        $query = "SELECT 
                    id, 
                    email, 
                    username, 
                    display_name,
                    first_name, 
                    last_name, 
                    phone_number, 
                    company, 
                    country,
                    address,
                    notes,
                    role, 
                    verified,
                    created_at
                  FROM wp_charterhub_users
                  WHERE id = ? AND role = 'client'";
        
        // Prepare and execute query
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $single_customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $conn->close();
            
            json_response([
                'success' => false,
                'message' => 'Customer not found',
            ], 404);
        }
        
        // Fetch the customer data
        $row = $result->fetch_assoc();
        $customer = [
            'id' => (int)$row['id'],
            'email' => $row['email'],
            'username' => $row['username'] ?? '',
            'firstName' => $row['first_name'] ?? '',
            'lastName' => $row['last_name'] ?? '',
            'phone' => $row['phone_number'] ?? '',
            'company' => $row['company'] ?? '',
            'country' => $row['country'] ?? '',
            'address' => $row['address'] ?? '',
            'notes' => $row['notes'] ?? '',
            'role' => $row['role'],
            'verified' => (bool)$row['verified'],
            'createdAt' => $row['created_at'],
            'selfRegistered' => true // Assuming all clients are self-registered
        ];
        
        $stmt->close();
        $conn->close();
        
        // Return the single customer
        json_response([
            'success' => true,
            'message' => 'Customer retrieved successfully',
            'customer' => $customer
        ]);
    } else {
        // Original logic for fetching all customers
        $query = "SELECT 
                    id, 
                    email, 
                    username, 
                    display_name,
                    first_name, 
                    last_name, 
                    phone_number, 
                    company, 
                    country,
                    address,
                    notes,
                    role, 
                    verified,
                    created_at
                  FROM wp_charterhub_users
                  WHERE role = 'client'
                  ORDER BY created_at DESC";
        
        // Prepare and execute query
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Fetch customers
        $customers = [];
        while ($row = $result->fetch_assoc()) {
            $customers[] = [
                'id' => (int)$row['id'],
                'email' => $row['email'],
                'username' => $row['username'] ?? '',
                'firstName' => $row['first_name'] ?? '',
                'lastName' => $row['last_name'] ?? '',
                'phone' => $row['phone_number'] ?? '',
                'company' => $row['company'] ?? '',
                'country' => $row['country'] ?? '',
                'address' => $row['address'] ?? '',
                'notes' => $row['notes'] ?? '',
                'role' => $row['role'],
                'verified' => (bool)$row['verified'],
                'createdAt' => $row['created_at'],
                'selfRegistered' => true, // Assuming all clients are self-registered
                'bookings' => 0 // Default value - would need a JOIN to get actual count
            ];
        }
        
        $stmt->close();
        $conn->close();
        
        // Return customers
        json_response([
            'success' => true,
            'message' => 'Customers retrieved successfully',
            'customers' => $customers,
            'meta' => [
                'total' => count($customers)
            ]
        ]);
    }
}

/**
 * Handle POST request - Create or update a customer
 */
function handle_post_request() {
    // Read and decode request body
    $input_raw = file_get_contents('php://input');
    error_log("Received create/update customer request body: " . $input_raw);
    
    $input = json_decode($input_raw, true);
    
    if (!$input) {
        error_log("Failed to parse JSON request body");
        json_response([
            'success' => false,
            'message' => 'Invalid request body - unable to parse JSON'
        ], 400);
    }
    
    error_log("Parsed create/update customer request data: " . print_r($input, true));
    
    $conn = get_database_connection();
    
    // Check if this is an update (ID provided) or create
    $is_update = isset($input['id']) && !empty($input['id']);
    error_log("Customer operation: " . ($is_update ? "UPDATE customer ID {$input['id']}" : "CREATE new customer"));
    
    // Validate required fields
    if (!$is_update) {
        // For new customer, email and password are required
        if (empty($input['email']) || empty($input['password'])) {
            error_log("Missing required fields for new customer: " . 
                      (empty($input['email']) ? "email " : "") . 
                      (empty($input['password']) ? "password" : ""));
            
            json_response([
                'success' => false,
                'message' => 'Email and password are required for new customers'
            ], 400);
        }
        
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE email = ?");
        $stmt->bind_param("s", $input['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $stmt->close();
            error_log("Customer creation failed - email already exists: {$input['email']}");
            json_response([
                'success' => false,
                'message' => 'Email already exists'
            ], 400);
        }
        $stmt->close();
    }
    
    // Ensure role is client
    $role = 'client';
    
    if ($is_update) {
        // Update existing customer
        $update_fields = [];
        $params = [];
        $types = "";
        
        // Only update fields that are provided
        // Removing email update capability for admins - email field cannot be modified
        // if (isset($input['email'])) {
        //     $update_fields[] = "email = ?";
        //     $params[] = sanitize_input($input['email']);
        //     $types .= "s";
        // }
        
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
        
        if (isset($input['country'])) {
            $update_fields[] = "country = ?";
            $params[] = sanitize_input($input['country']);
            $types .= "s";
        }
        
        if (isset($input['address'])) {
            $update_fields[] = "address = ?";
            $params[] = sanitize_input($input['address']);
            $types .= "s";
        }
        
        if (isset($input['notes'])) {
            $update_fields[] = "notes = ?";
            $params[] = sanitize_input($input['notes']);
            $types .= "s";
        }
        
        if (isset($input['password']) && !empty($input['password'])) {
            $update_fields[] = "password = ?";
            $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
            $params[] = $hashed_password;
            $types .= "s";
        }
        
        // Always set role to client
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
                    'message' => 'Customer not found or no changes made'
                ], 404);
            }
            
            $stmt->close();
        }
        
        // Get updated customer
        $stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, country, address, role, verified, created_at, notes 
                              FROM wp_charterhub_users WHERE id = ?");
        $stmt->bind_param("i", $input['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            json_response([
                'success' => false,
                'message' => 'Failed to retrieve updated customer'
            ], 500);
        }
        
        $customer = $result->fetch_assoc();
        $stmt->close();
        
        json_response([
            'success' => true,
            'message' => 'Customer updated successfully',
            'customer' => [
                'id' => (int)$customer['id'],
                'email' => $customer['email'],
                'username' => $customer['username'] ?? '',
                'firstName' => $customer['first_name'] ?? '',
                'lastName' => $customer['last_name'] ?? '',
                'phone' => $customer['phone_number'] ?? '',
                'company' => $customer['company'] ?? '',
                'country' => $customer['country'] ?? '',
                'address' => $customer['address'] ?? '',
                'notes' => $customer['notes'] ?? '',
                'role' => $customer['role'],
                'verified' => (bool)$customer['verified'],
                'createdAt' => $customer['created_at'],
                'selfRegistered' => false
            ]
        ]);
    } else {
        // Create new customer
        $email = sanitize_input($input['email']);
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        $username = isset($input['username']) ? sanitize_input($input['username']) : '';
        $first_name = isset($input['firstName']) ? sanitize_input($input['firstName']) : '';
        $last_name = isset($input['lastName']) ? sanitize_input($input['lastName']) : '';
        $phone = isset($input['phone']) ? sanitize_input($input['phone']) : '';
        $company = isset($input['company']) ? sanitize_input($input['company']) : '';
        $country = isset($input['country']) ? sanitize_input($input['country']) : '';
        $address = isset($input['address']) ? sanitize_input($input['address']) : '';
        $notes = isset($input['notes']) ? sanitize_input($input['notes']) : '';
        $verified = isset($input['verified']) ? (int)$input['verified'] : 1;
        
        // Create display name from first and last name if not provided
        $display_name = isset($input['display_name']) ? sanitize_input($input['display_name']) : 
                        trim($first_name . ' ' . $last_name);
        
        // If display_name is still empty, use email as fallback
        if (empty(trim($display_name))) {
            $display_name = $email;
        }
        
        // Debugging values
        error_log("Creating new customer with data:");
        error_log("Email: $email");
        error_log("Username: $username");
        error_log("First Name: $first_name");
        error_log("Last Name: $last_name");
        error_log("Display Name: $display_name");
        error_log("Phone: $phone");
        error_log("Company: $company");
        error_log("Country: $country");
        error_log("Address: $address");
        error_log("Role: $role");
        error_log("Verified: $verified");
        
        // Prepare the query - now including display_name
        $insert_query = "INSERT INTO wp_charterhub_users 
                        (email, password, username, first_name, last_name, display_name, phone_number, company, country, address, notes, role, verified, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        try {
            $stmt = $conn->prepare($insert_query);
            
            if (!$stmt) {
                error_log("Failed to prepare insert statement: " . $conn->error);
                json_response([
                    'success' => false,
                    'message' => 'Database error: Failed to prepare statement - ' . $conn->error
                ], 500);
            }
            
            $stmt->bind_param("ssssssssssssi", $email, $password, $username, $first_name, $last_name, $display_name, $phone, $company, $country, $address, $notes, $role, $verified);
            $result = $stmt->execute();
            
            if (!$result) {
                error_log("Failed to execute insert statement: " . $stmt->error);
                $stmt->close();
                json_response([
                    'success' => false,
                    'message' => 'Database error: Failed to create customer - ' . $stmt->error
                ], 500);
            }
            
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                error_log("No rows affected by insert statement");
                json_response([
                    'success' => false,
                    'message' => 'Failed to create customer: No rows inserted'
                ], 500);
            }
            
            $customer_id = $stmt->insert_id;
            $stmt->close();
            
            error_log("Successfully created customer with ID: $customer_id");
            
            // Get created customer
            $stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, country, address, role, verified, created_at, notes 
                                FROM wp_charterhub_users WHERE id = ?");
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $stmt->close();
                error_log("Failed to retrieve created customer with ID: $customer_id");
                json_response([
                    'success' => false,
                    'message' => 'Customer created but failed to retrieve customer details'
                ], 500);
            }
            
            $customer = $result->fetch_assoc();
            $stmt->close();
            
            json_response([
                'success' => true,
                'message' => 'Customer created successfully',
                'customer' => [
                    'id' => (int)$customer['id'],
                    'email' => $customer['email'],
                    'username' => $customer['username'] ?? '',
                    'firstName' => $customer['first_name'] ?? '',
                    'lastName' => $customer['last_name'] ?? '',
                    'phone' => $customer['phone_number'] ?? '',
                    'company' => $customer['company'] ?? '',
                    'country' => $customer['country'] ?? '',
                    'address' => $customer['address'] ?? '',
                    'notes' => $customer['notes'] ?? '',
                    'role' => $customer['role'],
                    'verified' => (bool)$customer['verified'],
                    'createdAt' => $customer['created_at'],
                    'selfRegistered' => false
                ]
            ]);
        } catch (Exception $e) {
            error_log("Exception during customer creation: " . $e->getMessage());
            json_response([
                'success' => false,
                'message' => 'Exception during customer creation: ' . $e->getMessage()
            ], 500);
        }
    }
}

/**
 * Handle DELETE request - Delete a customer
 */
function handle_delete_request() {
    // Get customer ID from query string
    $customer_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if (!$customer_id) {
        json_response([
            'success' => false,
            'message' => 'Customer ID is required'
        ], 400);
    }
    
    $conn = get_database_connection();
    
    // Check if customer exists and is a client
    $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE id = ? AND role = 'client'");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        json_response([
            'success' => false,
            'message' => 'Customer not found'
        ], 404);
    }
    $stmt->close();
    
    // Delete customer
    $stmt = $conn->prepare("DELETE FROM wp_charterhub_users WHERE id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        $stmt->close();
        json_response([
            'success' => false,
            'message' => 'Failed to delete customer'
        ], 500);
    }
    
    $stmt->close();
    $conn->close();
    
    json_response([
        'success' => true,
        'message' => 'Customer deleted successfully'
    ]);
}
?> 