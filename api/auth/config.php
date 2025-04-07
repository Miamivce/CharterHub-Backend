<?php
// Debug logging
error_log("config.php: File loaded");

// Define constants
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-secret-key-for-jwt-should-be-long-and-secure');
define('JWT_ISSUER', 'charterhub-api');
define('JWT_AUDIENCE', 'charterhub-app');
define('JWT_ACCESS_TOKEN_EXPIRY', 900); // 15 minutes in seconds
define('JWT_REFRESH_TOKEN_EXPIRY', 2592000); // 30 days in seconds

// Debug logging
error_log("config.php: JWT_SECRET: " . substr(JWT_SECRET, 0, 10) . "...");
error_log("config.php: JWT_ISSUER: " . JWT_ISSUER);
error_log("config.php: JWT_AUDIENCE: " . JWT_AUDIENCE);

// Define CHARTERHUB_LOADED constant for token-storage.php
define('CHARTERHUB_LOADED', true);

// Database connection
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'charterhub_local';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';

// Create database connection
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Include JWT implementation
require_once 'jwt.php';

// Include token storage helper
$token_storage_path = dirname(dirname(__DIR__)) . '/auth/token-storage.php';
if (file_exists($token_storage_path)) {
    require_once $token_storage_path;
    error_log("config.php: Token storage helper included from: " . $token_storage_path);
} else {
    error_log("config.php: Token storage helper not found at: " . $token_storage_path);
}

// Helper functions
function generate_tokens($user_id, $role) {
    $now = time();
    
    // Create access token payload
    $access_payload = [
        'iss' => JWT_ISSUER,
        'aud' => JWT_AUDIENCE,
        'iat' => $now,
        'exp' => $now + JWT_ACCESS_TOKEN_EXPIRY,
        'sub' => $user_id,
        'role' => $role
    ];
    
    // Create refresh token payload
    $refresh_payload = [
        'iss' => JWT_ISSUER,
        'aud' => JWT_AUDIENCE,
        'iat' => $now,
        'exp' => $now + JWT_REFRESH_TOKEN_EXPIRY,
        'sub' => $user_id,
        'type' => 'refresh'
    ];
    
    // Generate tokens using our JWT implementation
    $access_token = jwt_encode($access_payload, JWT_SECRET);
    $refresh_token = jwt_encode($refresh_payload, JWT_SECRET);
    
    // Store tokens in database if the function exists
    if (function_exists('store_jwt_token')) {
        error_log("generate_tokens: Storing tokens in database for user: " . $user_id);
        store_jwt_token($access_token, $user_id, JWT_ACCESS_TOKEN_EXPIRY, $refresh_token, JWT_REFRESH_TOKEN_EXPIRY);
    } else {
        error_log("generate_tokens: Token storage function not available - tokens not stored in database");
    }
    
    return [
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'expires_in' => JWT_ACCESS_TOKEN_EXPIRY
    ];
}

// Apply CORS headers
function apply_cors_headers() {
    // Allow from specified origins
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        // List of allowed origins
        $allowed_origins = array(
            'http://localhost:3000',
            'http://localhost:8000'
        );
        
        // Check if the origin is allowed
        if (in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            header('Access-Control-Allow-Credentials: true');
        }
    }
    
    // Cache preflight for 1 day
    header('Access-Control-Max-Age: 86400');
    
    // Allow these headers
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, x-requested-with, pragma, expires, cache-control, Access-Control-Allow-Credentials');
    
    // Allow these methods
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Sanitize and validate input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// JSON response helper
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Error response helper
function error_response($message, $status = 400) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// Apply CORS headers by default
apply_cors_headers(); 