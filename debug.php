<?php
// Set proper content type
header('Content-Type: application/json');

// Enable all error reporting for diagnostic purposes
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create diagnostics array with basic PHP info
$diagnostics = [
    'success' => true,
    'message' => 'PHP diagnostic information',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_info' => [
        'version' => PHP_VERSION,
        'sapi' => php_sapi_name(),
        'os' => PHP_OS,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'display_errors' => ini_get('display_errors'),
        'error_reporting' => ini_get('error_reporting')
    ],
    'server_info' => [
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Not available',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Not available',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Not available',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Not available',
        'http_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Not available',
        'https' => isset($_SERVER['HTTPS']) ? 'on' : 'off',
        'hostname' => gethostname() ?: 'Unknown hostname'
    ],
    'environment' => [
        'development_mode' => getenv('DEVELOPMENT_MODE') ?: 'Not set',
        'db_host' => getenv('DB_HOST') ? 'Set' : 'Not set',
        'db_name' => getenv('DB_NAME') ? 'Set' : 'Not set',
        'db_user' => getenv('DB_USER') ? 'Set' : 'Not set',
        'jwt_secret' => getenv('JWT_SECRET') ? 'Set' : 'Not set',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Not available'
    ],
    'extensions' => [
        'mysql' => extension_loaded('mysqli') ? 'Loaded' : 'Not loaded',
        'pdo' => extension_loaded('pdo') ? 'Loaded' : 'Not loaded',
        'pdo_mysql' => extension_loaded('pdo_mysql') ? 'Loaded' : 'Not loaded',
        'json' => extension_loaded('json') ? 'Loaded' : 'Not loaded'
    ]
];

// Try to include config.php but catch any errors
try {
    $config_path = __DIR__ . '/auth/config.php';
    if (file_exists($config_path)) {
        // Define constant to prevent direct access warning
        if (!defined('CHARTERHUB_LOADED')) {
            define('CHARTERHUB_LOADED', true);
        }
        
        // Include the file but catch and suppress any warnings
        ob_start();
        include $config_path;
        $config_output = ob_get_clean();
        
        $diagnostics['config_included'] = true;
        $diagnostics['config_output'] = !empty($config_output) ? substr($config_output, 0, 200) . '...' : 'No output';
        $diagnostics['config_file_exists'] = true;
        
        // Check if database config is available
        $diagnostics['db_config_available'] = isset($db_config) && is_array($db_config);
        
        // Add database configuration keys (without sensitive values)
        if (isset($db_config) && is_array($db_config)) {
            $diagnostics['db_config_keys'] = array_keys($db_config);
            $diagnostics['db_config_params'] = [
                'host' => isset($db_config['host']) ? (strpos($db_config['host'], 'localhost') !== false ? 'localhost' : 'remote host') : 'not set',
                'port' => isset($db_config['port']) ? 'set' : 'not set',
                'dbname' => isset($db_config['dbname']) ? 'set' : 'not set'
            ];
        }
    } else {
        $diagnostics['config_file_exists'] = false;
    }
} catch (Exception $e) {
    $diagnostics['config_error'] = $e->getMessage();
}

// Return the data
echo json_encode($diagnostics, JSON_PRETTY_PRINT); 