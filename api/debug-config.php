<?php
/**
 * CharterHub API Debug Endpoint
 * 
 * This file provides a simple endpoint to test API connectivity and configuration.
 * It returns basic system information without revealing sensitive details.
 */

// Set error display settings
ini_set('display_errors', 0);
error_reporting(E_ERROR);

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Include the global CORS handler
require_once dirname(__FILE__) . '/../auth/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Set content-type early to ensure it's applied even if errors occur
header('Content-Type: application/json');

// Only allow GET and OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Options requests should be handled by apply_global_cors
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Function to check database connectivity
function check_database_connection() {
    try {
        require_once dirname(__FILE__) . '/../utils/database.php';
        
        // Execute a simple query to test connection
        $result = fetchRow('SELECT 1 as db_connection_test');
        return $result && isset($result['db_connection_test']) && $result['db_connection_test'] == 1;
    } catch (Exception $e) {
        return false;
    }
}

// Check for debug mode parameter (limits information returned)
$debug_mode = isset($_GET['full']) && $_GET['full'] === 'true';

// Compile system information
$system_info = [
    'api_status' => 'online',
    'php_version' => phpversion(),
    'server_time' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get(),
    'content_type' => 'application/json',
    'cors_enabled' => true
];

// Check database connection
$system_info['database_connected'] = check_database_connection();

// Add extended information in debug mode
if ($debug_mode) {
    $system_info['extensions'] = get_loaded_extensions();
    $system_info['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $system_info['memory_limit'] = ini_get('memory_limit');
    $system_info['post_max_size'] = ini_get('post_max_size');
    $system_info['upload_max_filesize'] = ini_get('upload_max_filesize');
    $system_info['max_execution_time'] = ini_get('max_execution_time');
    
    // Check if JWT configuration is available
    $system_info['jwt_configured'] = defined('JWT_SECRET') && JWT_SECRET !== '';
    
    // Check request headers
    $system_info['request_headers'] = [];
    foreach (getallheaders() as $name => $value) {
        // Exclude authorization headers for security
        if (strtolower($name) !== 'authorization' && strtolower($name) !== 'cookie') {
            $system_info['request_headers'][$name] = $value;
        }
    }
}

// Return response
echo json_encode([
    'success' => true,
    'message' => 'API is operational',
    'system_info' => $system_info,
    'timestamp' => time()
]);
?> 