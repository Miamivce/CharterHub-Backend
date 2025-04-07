<?php
/**
 * Database Configuration
 * 
 * This file contains the database connection settings for the application.
 * It uses environment variables for sensitive information.
 */

// Include environment variable loader if not already loaded
if (!function_exists('getenv')) {
    require_once __DIR__ . '/env.php';
}

// Development mode flag (from environment or default to false)
define('DEVELOPMENT_MODE', getenv('DEVELOPMENT_MODE') === 'true' ? true : false);

// Database configuration from environment variables
$db_config = [
    'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
    'name' => getenv('DB_NAME') ?: 'defaultdb',
    'user' => getenv('DB_USER') ?: 'avnadmin',
    'pass' => getenv('DB_PASS') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C',
    'port' => getenv('DB_PORT') ?: '19174',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'table_prefix' => getenv('DB_TABLE_PREFIX') ?: 'wp_',
    'ssl' => getenv('DB_SSL') ?: 'REQUIRED'
];

// Frontend URL for building links (e.g., invitation URLs)
$frontend_url = getenv('FRONTEND_URL') ?: 'http://localhost:3000';

// Default connection function
function get_db_connection() {
    global $db_config;
    
    try {
        // Create connection string
        $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['name']};charset={$db_config['charset']}";
        
        // Set PDO options for better error handling and SSL
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        // Add SSL options if required
        if ($db_config['ssl'] === 'REQUIRED') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = true;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        
        // Create PDO instance
        $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
        
        return $pdo;
    } catch (PDOException $e) {
        // Log the error but don't expose details
        error_log("Database connection error: " . $e->getMessage());
        return null;
    }
}

// Database connection settings (for mysqli compatibility)
$db_host = getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com';
$db_user = getenv('DB_USER') ?: 'avnadmin';
$db_password = getenv('DB_PASS') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C';
$db_name = getenv('DB_NAME') ?: 'defaultdb';
$db_port = getenv('DB_PORT') ?: '19174';
$db_ssl = getenv('DB_SSL') ?: 'REQUIRED';

// Create connection with SSL options
$mysqli = mysqli_init();
if ($db_ssl === 'REQUIRED') {
    mysqli_ssl_set($mysqli, NULL, NULL, NULL, NULL, NULL);
    mysqli_options($mysqli, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
}
@$mysqli->real_connect($db_host, $db_user, $db_password, $db_name, $db_port);

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Set charset to ensure proper encoding
$mysqli->set_charset("utf8mb4");

// Function to sanitize input
function sanitize_input($input) {
    global $mysqli;
    if (is_array($input)) {
        foreach ($input as $key => $value) {
            $input[$key] = sanitize_input($value);
        }
    } else {
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input);
        $input = $mysqli->real_escape_string($input);
    }
    return $input;
}

// Function to generate a response
function generate_response($success, $message, $data = null) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    return json_encode($response);
}

// Database configuration
$db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';
$table_prefix = getenv('DB_TABLE_PREFIX') ?: 'wp_';

// Export to global scope
$GLOBALS['db_config'] = $db_config; 