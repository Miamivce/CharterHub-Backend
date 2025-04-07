<?php
// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Include required files
require_once dirname(__FILE__) . '/config.php';

// Log full request details
$log_file = 'login_debug.log';
$timestamp = date('Y-m-d H:i:s');

// Get the raw POST data
$raw_data = file_get_contents('php://input');
$json_data = json_decode($raw_data, true);

$log_data = [
    'timestamp' => $timestamp,
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'post_data' => $_POST,
    'raw_data' => $raw_data,
    'json_data' => $json_data
];

// Write to log file
file_put_contents($log_file, $timestamp . ' - ' . json_encode($log_data, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

// Test database connection
try {
    $conn = get_db_connection_from_config();
    file_put_contents($log_file, $timestamp . ' - DATABASE: Connection successful' . "\n", FILE_APPEND);
    
    // Check if the required tables exist
    $tables_query = "SHOW TABLES";
    $tables_result = $conn->query($tables_query);
    $tables = [];
    while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    file_put_contents($log_file, $timestamp . ' - DATABASE: Tables in database: ' . implode(', ', $tables) . "\n", FILE_APPEND);
    
    // Check for specific tables
    if (in_array('wp_charterhub_jwt_tokens', $tables)) {
        file_put_contents($log_file, $timestamp . ' - DATABASE: wp_charterhub_jwt_tokens table exists' . "\n", FILE_APPEND);
    } else {
        file_put_contents($log_file, $timestamp . ' - DATABASE: wp_charterhub_jwt_tokens table DOES NOT exist' . "\n", FILE_APPEND);
    }
    
    if (in_array('wp_jwt_tokens', $tables)) {
        file_put_contents($log_file, $timestamp . ' - DATABASE: wp_jwt_tokens table exists' . "\n", FILE_APPEND);
    } else {
        file_put_contents($log_file, $timestamp . ' - DATABASE: wp_jwt_tokens table DOES NOT exist' . "\n", FILE_APPEND);
    }
    
    // Test user credentials if provided
    if (!empty($json_data['email'])) {
        $email = $json_data['email'];
        file_put_contents($log_file, $timestamp . ' - USER: Testing login for email: ' . $email . "\n", FILE_APPEND);
        
        $stmt = $conn->prepare('SELECT id, email, password, role, verified FROM wp_charterhub_users WHERE email = ?');
        $stmt->bindParam(1, $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            file_put_contents($log_file, $timestamp . ' - USER: User found with ID: ' . $user['id'] . ', Role: ' . $user['role'] . "\n", FILE_APPEND);
            
            if (!empty($json_data['password'])) {
                if (password_verify($json_data['password'], $user['password'])) {
                    file_put_contents($log_file, $timestamp . ' - USER: Password verification SUCCESSFUL' . "\n", FILE_APPEND);
                } else {
                    file_put_contents($log_file, $timestamp . ' - USER: Password verification FAILED' . "\n", FILE_APPEND);
                }
            }
        } else {
            file_put_contents($log_file, $timestamp . ' - USER: No user found with email: ' . $email . "\n", FILE_APPEND);
        }
    }
} catch (Exception $e) {
    file_put_contents($log_file, $timestamp . ' - ERROR: Database connection failed: ' . $e->getMessage() . "\n", FILE_APPEND);
}

// Send a simple response
echo json_encode([
    'status' => 'debug_only',
    'message' => 'Login request logged for debugging',
    'received_data' => $json_data,
    'check_log' => 'See login_debug.log for details'
]);
