<?php
/**
 * Direct Customers API Endpoint
 * 
 * This endpoint handles customer data management for the admin interface.
 * Supports GET, POST, DELETE methods.
 */

// Define CHARTERHUB_LOADED constant to prevent direct access
define('CHARTERHUB_LOADED', true);

// Include auth helper
require_once __DIR__ . '/direct-auth-helper.php';

// Start output buffering to prevent header issues
if (!ob_get_level()) {
    ob_start();
}

// Log request details for debugging
error_log("DIRECT-CUSTOMERS.PHP - Request received from origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'unknown') . ", method: " . $_SERVER['REQUEST_METHOD']);

// Define allowed origins (identical to the list in global-cors.php)
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:5173', 
    'http://localhost:8080',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:8080',
    'https://charterhub.yachtstory.com',
    'https://staging-charterhub.yachtstory.com',
    'https://app.yachtstory.be',
    'https://admin.yachtstory.be',
    'https://www.admin.yachtstory.be',
    'http://admin.yachtstory.be',
    'https://yachtstory.be',
    'https://www.yachtstory.be',
    'https://charter-hub.vercel.app/',
    'https://app.yachtstory.be',
    'https://admin.yachtstory.be'
];

// Get the request origin
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Log all request headers for debugging
error_log("DIRECT-CUSTOMERS.PHP - Request headers: " . json_encode(getallheaders()));

// Check if the origin is allowed
$originIsAllowed = in_array($origin, $allowed_origins);
$isDev = strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false;

// Set the appropriate CORS headers based on the origin
if ($originIsAllowed || $isDev) {
    // Important: Set specific origin, not wildcard, when credentials are included
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires");
    header("Access-Control-Max-Age: 86400"); // 24 hours
    
    error_log("DIRECT-CUSTOMERS.PHP - Debug/Test mode: Setting CORS headers for origin: $origin");
} else {
    error_log("DIRECT-CUSTOMERS.PHP - Warning: Disallowed origin: $origin");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Return 200 OK for preflight requests
    http_response_code(200);
    exit;
}

// Initialize response data structure
$response = [
    'success' => false,
    'message' => '',
    'customers' => []
];

// Handle the main request based on HTTP method
try {
    handle_admin_request(function($admin_user) {
        global $response;
        
        // Get database connection
        $conn = get_database_connection();
        
        // Handle different HTTP methods
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                // Check if specific customer ID was requested
                if (isset($_GET['id'])) {
                    $customer_id = sanitize_input($_GET['id']);
                    
                    // Fetch single customer
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
                    
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $customer_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($row = $result->fetch_assoc()) {
                        $response['customers'] = [[
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
                        ]];
                        $response['success'] = true;
                        $response['message'] = 'Customer retrieved successfully';
                    } else {
                        $response['message'] = 'Customer not found';
                        http_response_code(404);
                    }
                    
                    $stmt->close();
                } else {
                    // Fetch all customers
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
                    
                    $result = $conn->query($query);
                    
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
                            'selfRegistered' => true // Assuming all clients are self-registered
                        ];
                    }
                    
                    $response['customers'] = $customers;
                    $response['success'] = true;
                    $response['message'] = 'Customers retrieved successfully';
                }
                break;
                
            case 'POST':
                // Handle customer updates or creation
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (isset($data['id'])) {
                    // Update existing customer
                    $id = $data['id'];
                    $updates = [];
                    $types = '';
                    $values = [];
                    
                    // Only include fields that are present in the request
                    if (isset($data['firstName'])) {
                        $updates[] = 'first_name = ?';
                        $types .= 's';
                        $values[] = $data['firstName'];
                    }
                    
                    if (isset($data['lastName'])) {
                        $updates[] = 'last_name = ?';
                        $types .= 's';
                        $values[] = $data['lastName'];
                    }
                    
                    if (isset($data['phone'])) {
                        $updates[] = 'phone_number = ?';
                        $types .= 's';
                        $values[] = $data['phone'];
                    }
                    
                    if (isset($data['company'])) {
                        $updates[] = 'company = ?';
                        $types .= 's';
                        $values[] = $data['company'];
                    }
                    
                    if (isset($data['email'])) {
                        $updates[] = 'email = ?';
                        $types .= 's';
                        $values[] = $data['email'];
                    }
                    
                    if (isset($data['notes'])) {
                        $updates[] = 'notes = ?';
                        $types .= 's';
                        $values[] = $data['notes'];
                    }
                    
                    if (isset($data['country'])) {
                        $updates[] = 'country = ?';
                        $types .= 's';
                        $values[] = $data['country'];
                    }
                    
                    if (isset($data['address'])) {
                        $updates[] = 'address = ?';
                        $types .= 's';
                        $values[] = $data['address'];
                    }
                    
                    if (!empty($updates)) {
                        $query = "UPDATE wp_charterhub_users SET " . implode(', ', $updates) . " WHERE id = ? AND role = 'client'";
                        $types .= 'i';
                        $values[] = $id;
                        
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param($types, ...$values);
                        $stmt->execute();
                        
                        if ($stmt->affected_rows > 0) {
                            // Fetch updated customer data
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
                            
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($row = $result->fetch_assoc()) {
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
                                    'selfRegistered' => true
                                ];
                                
                                $response['success'] = true;
                                $response['message'] = 'Customer updated successfully';
                                $response['customer'] = $customer;
                            } else {
                                $response['message'] = 'Customer updated but could not retrieve updated data';
                            }
                        } else {
                            $response['message'] = 'No changes made or customer not found';
                        }
                        
                        $stmt->close();
                    } else {
                        $response['message'] = 'No update data provided';
                    }
                } else {
                    // Create new customer
                    // Required fields
                    if (!isset($data['email']) || !isset($data['firstName']) || !isset($data['lastName'])) {
                        $response['message'] = 'Missing required fields (email, firstName, lastName)';
                        http_response_code(400);
                        break;
                    }
                    
                    // Check if email already exists
                    $check_query = "SELECT id FROM wp_charterhub_users WHERE email = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("s", $data['email']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $response['message'] = 'Email already exists';
                        http_response_code(409);
                        $check_stmt->close();
                        break;
                    }
                    
                    $check_stmt->close();
                    
                    // Insert new customer
                    $query = "INSERT INTO wp_charterhub_users (
                                email, 
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
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'client', 1, NOW())";
                    
                    $phone = $data['phone'] ?? '';
                    $company = $data['company'] ?? '';
                    $country = $data['country'] ?? '';
                    $address = $data['address'] ?? '';
                    $notes = $data['notes'] ?? '';
                    
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("ssssssss", 
                        $data['email'], 
                        $data['firstName'], 
                        $data['lastName'], 
                        $phone, 
                        $company,
                        $country,
                        $address,
                        $notes
                    );
                    
                    $stmt->execute();
                    
                    if ($stmt->affected_rows > 0) {
                        $new_id = $stmt->insert_id;
                        
                        $customer = [
                            'id' => $new_id,
                            'email' => $data['email'],
                            'firstName' => $data['firstName'],
                            'lastName' => $data['lastName'],
                            'phone' => $phone,
                            'company' => $company,
                            'country' => $country,
                            'address' => $address,
                            'notes' => $notes,
                            'role' => 'client',
                            'verified' => true,
                            'createdAt' => date('Y-m-d H:i:s'),
                            'selfRegistered' => false
                        ];
                        
                        $response['success'] = true;
                        $response['message'] = 'Customer created successfully';
                        $response['customer'] = $customer;
                    } else {
                        $response['message'] = 'Failed to create customer';
                    }
                    
                    $stmt->close();
                }
                break;
                
            case 'DELETE':
                // Delete customer
                if (isset($_GET['id'])) {
                    $id = sanitize_input($_GET['id']);
                    
                    $query = "DELETE FROM wp_charterhub_users WHERE id = ? AND role = 'client'";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    
                    if ($stmt->affected_rows > 0) {
                        $response['success'] = true;
                        $response['message'] = 'Customer deleted successfully';
                    } else {
                        $response['message'] = 'Customer not found or could not be deleted';
                        http_response_code(404);
                    }
                    
                    $stmt->close();
                } else {
                    $response['message'] = 'Customer ID is required';
                    http_response_code(400);
                }
                break;
                
            default:
                $response['message'] = 'Method not allowed';
                http_response_code(405);
                break;
        }
        
        $conn->close();
        return $response;
    });
} catch (Exception $e) {
    error_log("Error in direct-customers.php: " . $e->getMessage());
    
    $response = [
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ];
    
    http_response_code(500);
}
?> 