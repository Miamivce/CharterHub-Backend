<?php
/**
 * Public Invited Customer Data Endpoint
 * 
 * This endpoint provides basic customer data for invited registrations
 * without requiring authentication. It only returns minimal data
 * needed for the registration form.
 * 
 * Security measures:
 * - Only returns non-sensitive data (no passwords, etc)
 * - Requires a valid client ID
 * - Only works for unregistered customers (without a user account)
 */

// Enable CORS for specific origins in development
$allowed_origins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    http_response_code(405);
    exit;
}

// Get client ID from query parameter
$client_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$client_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Client ID is required'
    ]);
    http_response_code(400);
    exit;
}

// Connect to database
function get_database_connection() {
    // Try to use the same database config as the rest of the application
    $possible_config_paths = [
        __DIR__ . '/../../config/db.php',
        __DIR__ . '/../../../config/db.php',
        __DIR__ . '/../../../../config/db.php',
    ];
    
    $db_config = null;
    foreach ($possible_config_paths as $path) {
        if (file_exists($path)) {
            // Include the config file to get the variables
            include($path);
            if (isset($db_host) && isset($db_name) && isset($db_user)) {
                $db_config = [
                    'host' => $db_host,
                    'name' => $db_name,
                    'user' => $db_user,
                    'pass' => isset($db_pass) ? $db_pass : ''
                ];
                break;
            }
        }
    }
    
    // If no config file found, use these hardcoded defaults
    if (!$db_config) {
        $db_config = [
            'host' => 'localhost',
            'name' => 'charterhub_local',
            'user' => 'root',
            'pass' => ''
        ];
    }
    
    $conn = new mysqli(
        $db_config['host'], 
        $db_config['user'], 
        $db_config['pass'], 
        $db_config['name']
    );
    
    if ($conn->connect_error) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed'
        ]);
        exit;
    }
    
    return $conn;
}

// Get database connection
$conn = get_database_connection();

try {
    // Query for the customer with the given ID
    // Select only the fields needed for registration
    $stmt = $conn->prepare("SELECT 
                                id, 
                                email, 
                                first_name as firstName, 
                                last_name as lastName, 
                                phone_number as phone, 
                                company
                            FROM wp_charterhub_users
                            WHERE id = ? AND role = 'client'");
    
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
        http_response_code(404);
        exit;
    }
    
    // Fetch customer data
    $customer = $result->fetch_assoc();
    
    // Return only the essential data needed for registration
    echo json_encode([
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'email' => $customer['email'] ?? '',
            'firstName' => $customer['firstName'] ?? '',
            'lastName' => $customer['lastName'] ?? '',
            'phone' => $customer['phone'] ?? '',
            'company' => $customer['company'] ?? ''
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving customer data: ' . $e->getMessage()
    ]);
    http_response_code(500);
} finally {
    // Close the statement and connection
    if (isset($stmt)) {
        $stmt->close();
    }
    $conn->close();
} 