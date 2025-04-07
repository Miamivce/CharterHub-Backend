<?php
/**
 * Direct Admin Users Endpoint
 * 
 * This file directly queries the database for admin users without 
 * relying on authentication middleware.
 * FOR DEVELOPMENT USE ONLY - NOT FOR PRODUCTION
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enable CORS for local development
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize response
$response = [
    'success' => false,
    'message' => 'Initializing connection',
    'admin_users' => [],
    'debug' => [
        'connection' => null,
        'query' => null,
        'auth_header' => null
    ]
];

// Capture auth header for debugging
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $response['debug']['auth_header'] = substr($_SERVER['HTTP_AUTHORIZATION'], 0, 20) . '...';
}

// Database connection settings (hardcoded for development only)
// In production, these would come from environment variables or a config file
$db_host = 'localhost'; // Most common for local development
$db_name = 'charterhub_local'; // Based on naming convention in your project
$db_user = 'root'; // Common default for local development
$db_pass = ''; // Empty password is common for local dev

try {
    // Attempt to connect to the database
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Check connection
    if ($conn->connect_error) {
        $response['message'] = 'Database connection failed';
        $response['debug']['connection'] = $conn->connect_error;
    } else {
        $response['debug']['connection'] = 'Connected successfully';
        
        // Query to get admin users
        $query = "SELECT id, email, username, first_name, last_name, role, verified 
                 FROM wp_charterhub_users 
                 WHERE role = 'admin'";
        
        $result = $conn->query($query);
        
        if ($result === false) {
            $response['message'] = 'Query failed';
            $response['debug']['query'] = $conn->error;
        } else {
            $response['success'] = true;
            $response['message'] = 'Admin users retrieved successfully';
            
            // Fetch users
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = [
                    'id' => $row['id'],
                    'email' => $row['email'],
                    'username' => $row['username'],
                    'firstName' => $row['first_name'],
                    'lastName' => $row['last_name'],
                    'role' => $row['role'],
                    'verified' => (bool)$row['verified']
                ];
            }
            
            $response['admin_users'] = $users;
            $response['count'] = count($users);
        }
        
        // Close connection
        $conn->close();
    }
} catch (Exception $e) {
    $response['message'] = 'Exception occurred';
    $response['debug']['error'] = $e->getMessage();
}

// Return response
echo json_encode($response, JSON_PRETTY_PRINT);
?> 