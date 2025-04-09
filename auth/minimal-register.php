<?php
header('Content-Type: application/json');

// Use global CORS system instead of custom implementation
define('CHARTERHUB_LOADED', true);
include_once __DIR__ . '/config.php';
require_once __DIR__ . '/../utils/database.php';  
require_once __DIR__ . '/global-cors.php';

// Apply CORS headers from the global system
apply_global_cors(['POST', 'OPTIONS']);

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false, 
        'error' => 'method_not_allowed', 
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Read and validate input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

error_log("MINIMAL-REGISTER.PHP: Received registration data: " . json_encode($data));

if (!is_array($data)) {
    error_log("MINIMAL-REGISTER.PHP: Invalid input format");
    echo json_encode([
        'success' => false, 
        'error' => 'invalid_input', 
        'message' => 'Invalid input format'
    ]);
    exit;
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
    error_log("MINIMAL-REGISTER.PHP: Missing required fields: " . implode(', ', $missing_fields));
    echo json_encode([
        'success' => false,
        'error' => 'missing_fields',
        'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
    ]);
    exit;
}

try {
    // Get database connection
    $pdo = get_db_connection();
    error_log("MINIMAL-REGISTER.PHP: Connected to database successfully");
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM wp_charterhub_users WHERE email = ?");
    $stmt->execute([strtolower($data['email'])]);
    $existing_email_user = $stmt->fetch();
    
    if ($existing_email_user) {
        error_log("MINIMAL-REGISTER.PHP: Email already exists: " . $data['email']);
        echo json_encode([
            'success' => false,
            'error' => 'email_exists',
            'message' => 'Email already exists'
        ]);
        exit;
    }
    
    // Insert new user with minimal fields
    $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // Generate a unique wp_user_id (starting from 500)
    $stmt = $pdo->query("SELECT MAX(wp_user_id) FROM wp_charterhub_users");
    $max_wp_user_id = 0;
    if ($stmt) {
        $result = $stmt->fetch(PDO::FETCH_NUM);
        if ($result && isset($result[0])) {
            $max_wp_user_id = intval($result[0]);
        }
    }
    $new_wp_user_id = max(500, $max_wp_user_id + 1);
    
    // Use company name if provided
    $company = isset($data['company']) ? $data['company'] : null;
    
    // Normalize phone number field - handle both phoneNumber and phone_number
    $phone_number = null;
    if (isset($data['phoneNumber']) && !empty($data['phoneNumber'])) {
        $phone_number = $data['phoneNumber'];
    } elseif (isset($data['phone_number']) && !empty($data['phone_number'])) {
        $phone_number = $data['phone_number'];
    }
    
    // Let MySQL handle the ID with AUTO_INCREMENT
    $sql = "INSERT INTO wp_charterhub_users 
            (email, password, first_name, last_name, display_name, 
            phone_number, company, role, verified, token_version,
            wp_user_id, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'client', 1, 0, ?, NOW(), NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        strtolower($data['email']),
        $hashed_password,
        $data['firstName'],
        $data['lastName'],
        $data['firstName'] . ' ' . $data['lastName'],
        $phone_number,
        $company,
        $new_wp_user_id
    ]);
    
    // Get the auto-generated ID
    $user_id = $pdo->lastInsertId();
    error_log("MINIMAL-REGISTER.PHP: New user inserted with ID: " . $user_id);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful. You can now log in.',
        'user_id' => $user_id
    ]);
    
} catch (Exception $e) {
    error_log("MINIMAL-REGISTER.PHP ERROR: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'An error occurred during registration: ' . $e->getMessage()
    ]);
}
