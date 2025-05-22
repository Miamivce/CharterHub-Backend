<?php
/**
 * Direct Customers API Endpoint
 * 
 * Handles customer data operations for the admin API.
 * Supported HTTP methods: GET, POST, DELETE
 */

// Define constant to prevent direct access
define('CHARTERHUB_LOADED', true);

// Start output buffering to prevent header issues
ob_start();

// Define allowed origins for CORS
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001', 
    'http://localhost:5173',
    'http://localhost:8080',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:8080',
    'https://charterhub.app',
    'https://staging.charterhub.app',
    'https://dev.charterhub.app',
    'https://charterhub.yachtstory.com',
    'https://staging-charterhub.yachtstory.com',
    'https://app.yachtstory.be',
    'https://admin.yachtstory.be',
    'https://www.admin.yachtstory.be',
    'http://admin.yachtstory.be',
    'https://yachtstory.be',
    'https://www.yachtstory.be',
    'https://charter-hub.vercel.app/'
];

// Get the request origin
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Log CORS check for debugging
error_log("DIRECT-CUSTOMERS.PHP - Request received from origin: $origin, method: " . $_SERVER['REQUEST_METHOD']);
error_log("DIRECT-CUSTOMERS.PHP - Checking CORS allowed origins. Origin=$origin, isAllowed=" . (in_array($origin, $allowed_origins) ? '1' : '0'));

// Set CORS headers directly for immediate handling
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours
    
    error_log("DIRECT-CUSTOMERS.PHP - Set CORS headers for origin: $origin");
} else {
    error_log("DIRECT-CUSTOMERS.PHP - Origin not allowed: $origin");
}

// Handle preflight OPTIONS requests immediately before any other processing
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    error_log("DIRECT-CUSTOMERS.PHP - Handling OPTIONS preflight request directly");
    http_response_code(200);
    exit;
}

// Now include auth helper after handling OPTIONS requests
require_once __DIR__ . '/direct-auth-helper.php';

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Process the request through the secure handler
handle_admin_request(function($admin_user) {
    // Process request based on HTTP method
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // Handle GET request to fetch customers
            return handleGetCustomers();
            
        case 'POST':
            // Handle POST request to create/update customer
            return handlePostCustomer();
            
        case 'DELETE':
            // Handle DELETE request to delete customer
            return handleDeleteCustomer();
            
        default:
            // Method not allowed
            throw new Exception("Method not allowed", 405);
    }
});

/**
 * Handle GET requests
 */
function handleGetCustomers() {
    // Get database connection
    $db = get_database_connection();
    
    // Check if a specific customer ID is requested
    $customerId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if ($customerId) {
        // Fetch a specific customer
        $stmt = $db->prepare("SELECT * FROM wp_charterhub_users WHERE id = ? AND role = 'customer'");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $customer = $result->fetch_assoc();
        
        if (!$customer) {
            return [
                'success' => false,
                'message' => "Customer not found",
                'data' => null
            ];
        }
        
        return [
            'success' => true,
            'message' => "Customer retrieved successfully",
            'data' => $customer
        ];
    } else {
        // Fetch all customers with pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = ($page - 1) * $limit;
        
        $stmt = $db->prepare("SELECT * FROM wp_charterhub_users WHERE role = 'customer' ORDER BY created_at DESC LIMIT ?, ?");
        $stmt->bind_param("ii", $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $customers = [];
        
        while ($row = $result->fetch_assoc()) {
            // Format the data to match frontend expectations
            $customers[] = [
                'id' => (int)$row['id'],
                'email' => $row['email'],
                'username' => $row['username'] ?? '',
                'firstName' => $row['first_name'] ?? '',
                'lastName' => $row['last_name'] ?? '',
                'phone' => $row['phone'] ?? '',
                'company' => $row['company'] ?? '',
                'country' => $row['country'] ?? '',
                'address' => $row['address'] ?? '',
                'notes' => $row['notes'] ?? '',
                'role' => $row['role'],
                'verified' => (bool)($row['verified'] ?? false),
                'createdAt' => $row['created_at'],
                'createdBy' => $row['created_by'] ?? 'admin',
                'bookings' => 0 // Default value since we don't join with bookings table
            ];
        }
        
        // Get total count for pagination
        $countResult = $db->query("SELECT COUNT(*) as total FROM wp_charterhub_users WHERE role = 'customer'");
        $countRow = $countResult->fetch_assoc();
        $totalCount = $countRow['total'];
        
        // Format the response in the structure expected by the frontend
        return [
            'success' => true,
            'message' => "Customers retrieved successfully",
            'customers' => $customers,
            'meta' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalCount / $limit)
            ]
        ];
    }
}

/**
 * Handle POST requests
 */
function handlePostCustomer() {
    // Get database connection
    $db = get_database_connection();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        return [
            'success' => false,
            'message' => "Invalid input data",
            'data' => null
        ];
    }
    
    // Sanitize input
    $input = sanitize_input($input);
    
    // Check if updating existing customer
    $customerId = isset($input['id']) ? (int)$input['id'] : null;
    
    if ($customerId) {
        // Update existing customer
        $stmt = $db->prepare(
            "UPDATE wp_charterhub_users SET 
                first_name = ?, 
                last_name = ?, 
                email = ?, 
                phone = ?, 
                company = ?,
                address = ?, 
                country = ?, 
                notes = ?,
                updated_at = NOW()
            WHERE id = ? AND role = 'customer'"
        );
        
        $stmt->bind_param("ssssssssi", 
            $input['firstName'], 
            $input['lastName'],
            $input['email'], 
            $input['phone'],
            $input['company'],
            $input['address'],
            $input['country'],
            $input['notes'],
            $customerId
        );
        
        $result = $stmt->execute();
        
        return [
            'success' => $result,
            'message' => $result ? "Customer updated successfully" : "Failed to update customer",
            'data' => $result ? ['id' => $customerId] : null
        ];
    } else {
        // Create new customer
        // Generate username and password if not provided
        if (!isset($input['username']) || empty($input['username'])) {
            $input['username'] = strtolower($input['firstName'] . $input['lastName'] . '_' . rand(10000, 99999));
        }
        
        if (!isset($input['password']) || empty($input['password'])) {
            $input['password'] = generate_random_password();
        }
        
        // Hash password
        $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
        
        // Set role to customer
        $role = 'customer';
        $created_by = 'admin'; // Mark as admin created
        
        $stmt = $db->prepare(
            "INSERT INTO wp_charterhub_users 
                (username, password, email, first_name, last_name, phone, company, address, country, notes, role, created_by, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        
        $stmt->bind_param("ssssssssssss", 
            $input['username'],
            $hashed_password,
            $input['email'],
            $input['firstName'],
            $input['lastName'],
            $input['phone'],
            $input['company'],
            $input['address'],
            $input['country'],
            $input['notes'],
            $role,
            $created_by
        );
        
        $result = $stmt->execute();
        $newId = $db->insert_id;
        
        return [
            'success' => $result,
            'message' => $result ? "Customer created successfully" : "Failed to create customer",
            'data' => $result ? [
                'id' => $newId,
                'username' => $input['username'],
                'password' => $input['password'] // Return plain password for notifying customer
            ] : null
        ];
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteCustomer() {
    // Get database connection
    $db = get_database_connection();
    
    // Get customer ID from URL parameter
    $customerId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    
    if (!$customerId) {
        return [
            'success' => false,
            'message' => "Customer ID is required",
            'data' => null
        ];
    }
    
    // Delete the customer
    $stmt = $db->prepare("DELETE FROM wp_charterhub_users WHERE id = ? AND role = 'customer'");
    $stmt->bind_param("i", $customerId);
    $result = $stmt->execute();
    
    return [
        'success' => $result && $stmt->affected_rows > 0,
        'message' => $result && $stmt->affected_rows > 0 ? "Customer deleted successfully" : "Customer not found or could not be deleted",
        'data' => null
    ];
}

/**
 * Sanitize input data
 */
function sanitize_input($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? sanitize_input($value) : sanitize_value($value);
        }
    }
    return $data;
}

/**
 * Sanitize a single value
 */
function sanitize_value($value) {
    if (is_string($value)) {
        $value = trim($value);
        $value = stripslashes($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    return $value;
}

/**
 * Generate a random password
 */
function generate_random_password($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    return $password;
}
?> 