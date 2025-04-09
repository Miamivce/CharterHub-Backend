<?php
header('Content-Type: application/json');

// Fix CORS with specific origin instead of wildcard for credentials
$allowed_origin = 'https://charter-hub.vercel.app';
if (isset($_SERVER['HTTP_ORIGIN'])) {
    if ($_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
        header("Access-Control-Allow-Origin: $allowed_origin");
    } else {
        // For development, also allow localhost origins
        if (strpos($_SERVER['HTTP_ORIGIN'], 'localhost') !== false || 
            strpos($_SERVER['HTTP_ORIGIN'], '127.0.0.1') !== false) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        }
    }
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, x-requested-with, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database configuration
define('CHARTERHUB_LOADED', true);
include_once __DIR__ . '/config.php';
require_once __DIR__ . '/../utils/database.php';

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
    
    // Since verification_token column doesn't exist, we'll set verified = 1 directly
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
