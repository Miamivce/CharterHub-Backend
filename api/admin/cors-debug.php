<?php
/**
 * CORS Debug Endpoint
 * 
 * This file is for diagnosing CORS issues with the admin API.
 * It logs detailed information about request headers, CORS configuration,
 * and database connection.
 */

// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define loaded constant to prevent direct access in included files
define('CHARTERHUB_LOADED', true);

// Log basic request info
error_log("CORS-DEBUG: Request received from " . ($_SERVER['HTTP_ORIGIN'] ?? 'unknown origin'));
error_log("CORS-DEBUG: Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("CORS-DEBUG: All headers: " . json_encode(getallheaders()));

// Define allowed origins directly in this file for testing
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:5173', 
    'http://localhost:8000',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:8000',
    'https://charterhub.yachtstory.com',
    'https://staging-charterhub.yachtstory.com',
    'https://admin.yachtstory.be',
    'https://www.admin.yachtstory.be',
    'http://admin.yachtstory.be',
    'https://app.yachtstory.be'
];

// Get the origin from the request
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
error_log("CORS-DEBUG: Origin header value: $origin");

// Check if origin is allowed
$isAllowed = in_array($origin, $allowed_origins);
error_log("CORS-DEBUG: Is origin allowed: " . ($isAllowed ? 'Yes' : 'No'));
error_log("CORS-DEBUG: Allowed origins: " . implode(', ', $allowed_origins));

// Set CORS headers explicitly
if ($isAllowed && !empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    error_log("CORS-DEBUG: Set Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: http://localhost:3000");
    error_log("CORS-DEBUG: Set default Access-Control-Allow-Origin: http://localhost:3000");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-HTTP-Method-Override");
header("Access-Control-Max-Age: 86400"); // 24 hours cache

// Handle OPTIONS request immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    error_log("CORS-DEBUG: Handled OPTIONS request with 200 response");
    exit;
}

// Try to test database connection
error_log("CORS-DEBUG: Testing database connection");
try {
    require_once dirname(__DIR__, 2) . '/includes/config.php';
    
    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
        throw new Exception("Database configuration constants not defined");
    }
    
    error_log("CORS-DEBUG: DB constants exist: DB_HOST=" . DB_HOST . ", DB_NAME=" . DB_NAME);
    
    // Connect to the database
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    error_log("CORS-DEBUG: Database connection successful");
    
    // Test table existence
    $tables = [
        'wp_charterhub_users',
        'wp_charterhub_customers'
    ];
    
    foreach ($tables as $table) {
        try {
            $stmt = $db->prepare("SELECT 1 FROM $table LIMIT 1");
            $stmt->execute();
            error_log("CORS-DEBUG: Table $table exists and is accessible");
            
            // For users table, count total users
            if ($table === 'wp_charterhub_users') {
                $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE role = 'admin'");
                $stmt->execute();
                $count = $stmt->fetchColumn();
                error_log("CORS-DEBUG: Found $count admin users in $table");
            }
        } catch (PDOException $e) {
            error_log("CORS-DEBUG: Table $table error: " . $e->getMessage());
        }
    }
    
} catch (Exception $e) {
    error_log("CORS-DEBUG: Database connection error: " . $e->getMessage());
}

// Prepare the response
$response = [
    'success' => true,
    'message' => 'CORS Debug completed successfully',
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'origin' => $origin,
        'origin_allowed' => $isAllowed,
        'headers' => getallheaders()
    ],
    'server' => [
        'php_version' => PHP_VERSION,
        'time' => date('c'),
        'environment' => getenv('APP_ENV') ?: 'unknown'
    ]
];

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
exit; 