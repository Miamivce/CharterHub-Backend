<?php
/**
 * Token Debug Script
 * 
 * This script checks JWT secret configuration and attempts basic token generation
 * to diagnose issues with the token system.
 */

// Set error display settings for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Basic CORS to allow testing from anywhere
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Check environment variables
$env_check = [
    'JWT_SECRET' => getenv('JWT_SECRET') ? 'Set (length: ' . strlen(getenv('JWT_SECRET')) . ')' : 'NOT SET',
    'JWT_REFRESH_SECRET' => getenv('JWT_REFRESH_SECRET') ? 'Set (length: ' . strlen(getenv('JWT_REFRESH_SECRET')) . ')' : 'NOT SET',
    'JWT_ALGORITHM' => getenv('JWT_ALGORITHM') ?: 'NOT SET (will use default)',
    'JWT_EXPIRY_MINUTES' => getenv('JWT_EXPIRY_MINUTES') ?: 'NOT SET (will use default)'
];

// Check if vendor autoloader exists
$vendor_autoloader = file_exists(__DIR__ . '/../vendor/autoload.php');

// Try to include the basic required files (but catch errors)
try {
    require_once __DIR__ . '/config.php';
    $config_included = true;
} catch (Exception $e) {
    $config_included = false;
    $config_error = $e->getMessage();
}

// Check JWT configuration from config file
$jwt_config = [
    'jwt_secret' => isset($jwt_secret) ? 'Set (length: ' . strlen($jwt_secret ?? '') . ')' : 'NOT SET',
    'jwt_algorithm' => $jwt_algorithm ?? 'NOT SET',
    'jwt_expiration' => $jwt_expiration ?? 'NOT SET',
    'refresh_expiration' => $refresh_expiration ?? 'NOT SET'
];

// Check token related tables
try {
    require_once __DIR__ . '/../utils/database.php';
    $db_included = true;
    
    $db = get_db_connection_from_config();
    $db_connected = true;
    
    // Check tables
    $tables = [
        'wp_charterhub_jwt_tokens',
        'wp_charterhub_token_blacklist'
    ];
    
    $table_status = [];
    
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $table_status[$table] = [
            'exists' => $stmt && $stmt->rowCount() > 0
        ];
        
        if ($table_status[$table]['exists']) {
            $cols = $db->query("DESCRIBE $table");
            $table_status[$table]['columns'] = [];
            while ($col = $cols->fetch(PDO::FETCH_ASSOC)) {
                $table_status[$table]['columns'][] = $col['Field'];
            }
        }
    }
} catch (Exception $e) {
    $db_included = false;
    $db_connected = false;
    $db_error = $e->getMessage();
    $table_status = ['error' => $e->getMessage()];
}

// Try to load Firebase JWT
try {
    if ($vendor_autoloader) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
    
    // Check if Firebase JWT is available
    $firebase_jwt_available = class_exists('Firebase\JWT\JWT');
} catch (Exception $e) {
    $firebase_jwt_available = false;
    $firebase_jwt_error = $e->getMessage();
}

// Test simple token generation if everything seems ok
$token_test = null;
if ($firebase_jwt_available && isset($jwt_secret) && !empty($jwt_secret)) {
    try {
        $payload = [
            'iss' => 'debug-script',
            'aud' => 'debug-client',
            'iat' => time(),
            'exp' => time() + 60,
            'sub' => 0,
            'test' => true
        ];
        
        $token = \Firebase\JWT\JWT::encode($payload, $jwt_secret, $jwt_algorithm ?? 'HS256');
        $token_test = [
            'success' => true,
            'token' => substr($token, 0, 20) . '...' // Only show part of token
        ];
    } catch (Exception $e) {
        $token_test = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Return all information
$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'environment_variables' => $env_check,
    'vendor_autoloader' => $vendor_autoloader ? 'Found' : 'NOT FOUND',
    'config_included' => $config_included ? 'Success' : 'Failed: ' . ($config_error ?? 'Unknown error'),
    'jwt_config' => $jwt_config,
    'database' => [
        'included' => $db_included ? 'Success' : 'Failed: ' . ($db_error ?? 'Unknown error'),
        'connected' => $db_connected ?? false,
        'tables' => $table_status
    ],
    'firebase_jwt' => [
        'available' => $firebase_jwt_available ? 'Yes' : 'No: ' . ($firebase_jwt_error ?? 'Class not found'),
        'token_test' => $token_test
    ],
    'php_version' => phpversion(),
    'loaded_extensions' => get_loaded_extensions()
];

echo json_encode($result, JSON_PRETTY_PRINT); 