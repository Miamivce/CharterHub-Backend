<?php
/**
 * Customers Endpoint
 * 
 * This endpoint serves customer data for the admin interface.
 * It includes proper CORS handling with origin-specific headers.
 */

// Define CHARTERHUB_LOADED constant for included files
define('CHARTERHUB_LOADED', true);

// Include auth helper
require_once __DIR__ . '/../api/admin/direct-auth-helper.php';

// Start output buffering to prevent header issues
if (!ob_get_level()) {
    ob_start();
}

// Log request details for debugging
error_log("CUSTOMERS: Request received from origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'unknown') . ", method: " . $_SERVER['REQUEST_METHOD']);

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
error_log("CUSTOMERS: Request headers: " . json_encode(getallheaders()));

// Check if the origin is allowed
$originIsAllowed = in_array($origin, $allowed_origins);
$isDev = strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false;

// Set the appropriate CORS headers based on the origin
if ($originIsAllowed || $isDev) {
    // Important: Set specific origin, not wildcard, when credentials are included
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires");
    header("Access-Control-Max-Age: 86400"); // 24 hours
} else {
    error_log("CUSTOMERS: Disallowed origin: $origin");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Return 200 OK for preflight requests
    http_response_code(200);
    exit;
}

// Handle the main request with admin authentication
handle_admin_request(function($admin_user) {
    // Get database connection
    $conn = get_database_connection();
    
    // Fetch all clients (customers)
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
        'success' => true,
        'message' => 'Customers retrieved successfully',
        'customers' => $customers,
        'meta' => [
            'total' => count($customers)
        ]
    ];
});
?> 