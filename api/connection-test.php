<?php
/**
 * CharterHub Database Connection Test
 * 
 * This endpoint tests database connectivity and reports status.
 */

// Define a constant to prevent direct access
define('CHARTERHUB_LOADED', true);

// Error handling
ini_set('display_errors', 0);
error_reporting(E_ERROR);

// Include the global CORS handler
require_once dirname(__FILE__) . '/../auth/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Set content-type early
header('Content-Type: application/json');

// Include config with database functions
require_once dirname(__FILE__) . '/../auth/config.php';

// Test connection
try {
    $pdo = get_db_connection_from_config();
    $result = $pdo->query("SELECT 1 as test")->fetch();
    
    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful',
        'test_result' => $result,
        'environment' => [
            'db_host' => !empty(getenv('DB_HOST')) ? 'Set' : 'Using default',
            'db_port' => !empty(getenv('DB_PORT')) ? 'Set' : 'Using default',
            'db_name' => !empty(getenv('DB_NAME')) ? 'Set' : 'Using default',
            'db_user' => !empty(getenv('DB_USER')) ? 'Set' : 'Using default', 
            'db_password' => !empty(getenv('DB_PASSWORD')) ? 'Set (masked)' : 'Using default (masked)',
            'jwt_secret' => !empty(getenv('JWT_SECRET')) ? 'Set (masked)' : 'Using default (masked)',
            'php_version' => phpversion()
        ],
        'server_info' => [
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'request_time' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'http_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ],
        'files_exist' => [
            'config.php' => file_exists(dirname(__FILE__) . '/../auth/config.php'),
            'database.php' => file_exists(dirname(__FILE__) . '/../utils/database.php'),
            'global-cors.php' => file_exists(dirname(__FILE__) . '/../auth/global-cors.php')
        ]
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Connection test failed: ' . $e->getMessage(),
        'environment' => [
            'db_host' => !empty(getenv('DB_HOST')) ? 'Set' : 'Using default',
            'db_port' => !empty(getenv('DB_PORT')) ? 'Set' : 'Using default',
            'db_name' => !empty(getenv('DB_NAME')) ? 'Set' : 'Using default',
            'db_user' => !empty(getenv('DB_USER')) ? 'Set' : 'Using default',
            'db_password' => !empty(getenv('DB_PASSWORD')) ? 'Set (masked)' : 'Using default (masked)',
            'jwt_secret' => !empty(getenv('JWT_SECRET')) ? 'Set (masked)' : 'Using default (masked)',
            'php_version' => phpversion()
        ],
        'server_info' => [
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'request_time' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ],
        'files_exist' => [
            'config.php' => file_exists(dirname(__FILE__) . '/../auth/config.php'),
            'database.php' => file_exists(dirname(__FILE__) . '/../utils/database.php'),
            'global-cors.php' => file_exists(dirname(__FILE__) . '/../auth/global-cors.php')
        ]
    ], JSON_PRETTY_PRINT);
}
?> 