<?php
/**
 * Direct Customers CRUD Endpoint
 * 
 * This endpoint handles admin access to customer data without relying on
 * external JWT libraries or middleware.
 * 
 * Supports:
 * - GET: List all customers/clients or get a single customer by ID
 * - POST: Create or update a customer
 * - DELETE: Delete a customer
 */

// Define CHARTERHUB_LOADED constant for included files
define('CHARTERHUB_LOADED', true);

// Include auth helper
require_once __DIR__ . '/direct-auth-helper.php';

// Use the admin request handler for CORS and authentication
handle_admin_request(function($admin_user) {
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
            return handle_get_request();
        case 'POST':
        case 'PUT':
            return handle_post_request();
        case 'DELETE':
            return handle_delete_request();
        default:
            throw new Exception('Method not allowed');
    }
});

/**
 * Handle GET request - List all customers (clients) or a single customer by ID
 * 
 * @return array Results of the operation
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
            
            return [
                'success' => false,
                'message' => 'Customer not found'
            ];
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
        return [
            'message' => 'Customer retrieved successfully',
            'customer' => $customer
        ];
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
        return [
            'message' => 'Customers retrieved successfully',
            'customers' => $customers,
            'meta' => [
                'total' => count($customers)
            ]
        ];
    }
}

/**
 * Handle POST request - Create or update a customer
 * 
 * @return array Results of the operation
 */
function handle_post_request() {
    // Get database connection
    $conn = get_database_connection();
    
    // Read and decode request body
    $input_raw = file_get_contents('php://input');
    $input = json_decode($input_raw, true);
    
    if (!$input) {
        return [
            'success' => false,
            'message' => 'Invalid request body - unable to parse JSON'
        ];
    }
    
    // Check if this is an update (ID provided) or create
    $is_update = isset($input['id']) && !empty($input['id']);
    
    if ($is_update) {
        // UPDATE EXISTING CUSTOMER
        $user_id = intval($input['id']);
        
        // Build update query based on provided fields
        $update_fields = [];
        $params = [];
        $types = "";
        
        // Map input fields to database fields
        $field_mappings = [
            'email' => 'email',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'phone' => 'phone_number',
            'company' => 'company',
            'country' => 'country',
            'address' => 'address',
            'notes' => 'notes'
        ];
        
        // Add display_name calculation
        $has_name_fields = false;
        
        foreach ($field_mappings as $input_field => $db_field) {
            if (isset($input[$input_field])) {
                $update_fields[] = "$db_field = ?";
                $params[] = $input[$input_field];
                $types .= "s";
                
                if ($input_field === 'firstName' || $input_field === 'lastName') {
                    $has_name_fields = true;
                }
            }
        }
        
        // If first_name or last_name was updated, update display_name
        if ($has_name_fields) {
            // Get current values for any name field not being updated
            $stmt = $conn->prepare("SELECT first_name, last_name FROM wp_charterhub_users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $current_user = $result->fetch_assoc();
            $stmt->close();
            
            $first_name = isset($input['firstName']) ? $input['firstName'] : $current_user['first_name'];
            $last_name = isset($input['lastName']) ? $input['lastName'] : $current_user['last_name'];
            
            // Generate display name
            $display_name = trim("$first_name $last_name");
            if (!empty($display_name)) {
                $update_fields[] = "display_name = ?";
                $params[] = $display_name;
                $types .= "s";
            }
        }
        
        if (empty($update_fields)) {
            return [
                'success' => false,
                'message' => 'No fields provided for update'
            ];
        }
        
        // Add the ID parameter
        $params[] = $user_id;
        $types .= "i";
        
        // Execute update
        $update_query = "UPDATE wp_charterhub_users SET " . implode(", ", $update_fields) . " WHERE id = ? AND role = 'client'";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0 && $stmt->errno === 0) {
            // No rows were updated, but no error occurred (might be updating with the same values)
            $stmt->close();
            
            // Get the current user data to return
            $get_stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, country, address, notes, role, verified FROM wp_charterhub_users WHERE id = ? AND role = 'client'");
            $get_stmt->bind_param("i", $user_id);
            $get_stmt->execute();
            $result = $get_stmt->get_result();
            
            if ($result->num_rows === 0) {
                $get_stmt->close();
                $conn->close();
                return [
                    'success' => false,
                    'message' => 'Customer not found'
                ];
            }
            
            $user = $result->fetch_assoc();
            $get_stmt->close();
            $conn->close();
            
            return [
                'message' => 'No changes made to customer',
                'customer' => [
                    'id' => (int)$user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'] ?? '',
                    'firstName' => $user['first_name'] ?? '',
                    'lastName' => $user['last_name'] ?? '',
                    'phone' => $user['phone_number'] ?? '',
                    'company' => $user['company'] ?? '',
                    'country' => $user['country'] ?? '',
                    'address' => $user['address'] ?? '',
                    'notes' => $user['notes'] ?? '',
                    'role' => $user['role'],
                    'verified' => (bool)$user['verified']
                ]
            ];
        } else if ($stmt->errno !== 0) {
            // An error occurred
            $error = $stmt->error;
            $stmt->close();
            $conn->close();
            
            return [
                'success' => false,
                'message' => 'Database error: ' . $error
            ];
        }
        
        $stmt->close();
        
        // Get the updated user data
        $get_stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, country, address, notes, role, verified FROM wp_charterhub_users WHERE id = ? AND role = 'client'");
        $get_stmt->bind_param("i", $user_id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        
        if ($result->num_rows === 0) {
            $get_stmt->close();
            $conn->close();
            
            return [
                'success' => false,
                'message' => 'Customer updated but failed to retrieve updated data'
            ];
        }
        
        $user = $result->fetch_assoc();
        $get_stmt->close();
        $conn->close();
        
        return [
            'message' => 'Customer updated successfully',
            'customer' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'] ?? '',
                'firstName' => $user['first_name'] ?? '',
                'lastName' => $user['last_name'] ?? '',
                'phone' => $user['phone_number'] ?? '',
                'company' => $user['company'] ?? '',
                'country' => $user['country'] ?? '',
                'address' => $user['address'] ?? '',
                'notes' => $user['notes'] ?? '',
                'role' => $user['role'],
                'verified' => (bool)$user['verified']
            ]
        ];
    } else {
        // CREATE NEW CUSTOMER
        // For new customers, email is required
        if (empty($input['email'])) {
            return [
                'success' => false,
                'message' => 'Email is required for new customers'
            ];
        }
        
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE email = ?");
        $stmt->bind_param("s", $input['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $stmt->close();
            $conn->close();
            
            return [
                'success' => false,
                'message' => 'Email already exists'
            ];
        }
        $stmt->close();
        
        // Set defaults for optional fields
        $first_name = isset($input['firstName']) ? $input['firstName'] : '';
        $last_name = isset($input['lastName']) ? $input['lastName'] : '';
        $phone = isset($input['phone']) ? $input['phone'] : '';
        $company = isset($input['company']) ? $input['company'] : '';
        $country = isset($input['country']) ? $input['country'] : '';
        $address = isset($input['address']) ? $input['address'] : '';
        $notes = isset($input['notes']) ? $input['notes'] : '';
        
        // Generate display name
        $display_name = trim("$first_name $last_name");
        if (empty($display_name)) {
            $display_name = $input['email'];
        }
        
        // Insert new customer
        $stmt = $conn->prepare("
            INSERT INTO wp_charterhub_users 
            (email, username, display_name, first_name, last_name, phone_number, company, country, address, notes, role, verified) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'client', 1)
        ");
        
        $username = $input['email']; // Use email as username
        
        $stmt->bind_param(
            "ssssssssss", 
            $input['email'], 
            $username, 
            $display_name, 
            $first_name, 
            $last_name, 
            $phone, 
            $company,
            $country,
            $address,
            $notes
        );
        
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            $error = $stmt->error;
            $stmt->close();
            $conn->close();
            
            return [
                'success' => false,
                'message' => 'Failed to create customer: ' . $error
            ];
        }
        
        $new_id = $stmt->insert_id;
        $stmt->close();
        
        // Get the newly created customer
        $get_stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, phone_number, company, country, address, notes, role, verified FROM wp_charterhub_users WHERE id = ?");
        $get_stmt->bind_param("i", $new_id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        
        if ($result->num_rows === 0) {
            $get_stmt->close();
            $conn->close();
            
            return [
                'success' => false,
                'message' => 'Customer created but failed to retrieve data'
            ];
        }
        
        $user = $result->fetch_assoc();
        $get_stmt->close();
        $conn->close();
        
        return [
            'message' => 'Customer created successfully',
            'customer' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'] ?? '',
                'firstName' => $user['first_name'] ?? '',
                'lastName' => $user['last_name'] ?? '',
                'phone' => $user['phone_number'] ?? '',
                'company' => $user['company'] ?? '',
                'country' => $user['country'] ?? '',
                'address' => $user['address'] ?? '',
                'notes' => $user['notes'] ?? '',
                'role' => $user['role'],
                'verified' => (bool)$user['verified']
            ]
        ];
    }
}

/**
 * Handle DELETE request - Delete a customer
 * 
 * @return array Results of the operation
 */
function handle_delete_request() {
    // Get customer ID from request
    $customer_id = null;
    
    // Check if ID is in URL parameters
    if (isset($_GET['id'])) {
        $customer_id = intval($_GET['id']);
    } else {
        // Check if ID is in JSON body
        $input_raw = file_get_contents('php://input');
        $input = json_decode($input_raw, true);
        
        if (isset($input['id'])) {
            $customer_id = intval($input['id']);
        }
    }
    
    if (!$customer_id) {
        return [
            'success' => false,
            'message' => 'Customer ID is required'
        ];
    }
    
    // Get database connection
    $conn = get_database_connection();
    
    // First check if customer exists and is a client
    $stmt = $conn->prepare("SELECT id FROM wp_charterhub_users WHERE id = ? AND role = 'client'");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        
        return [
            'success' => false,
            'message' => 'Customer not found or not a client'
        ];
    }
    $stmt->close();
    
    // Delete customer
    $stmt = $conn->prepare("DELETE FROM wp_charterhub_users WHERE id = ? AND role = 'client'");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        $stmt->close();
        $conn->close();
        
        return [
            'success' => false,
            'message' => 'Failed to delete customer'
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    return [
        'message' => 'Customer deleted successfully',
        'customer_id' => $customer_id
    ];
}
?> 