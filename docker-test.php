<?php
/**
 * Docker Test Script
 * 
 * This file tests the Docker environment and database connection.
 */

// Set content type to JSON
header('Content-Type: application/json');

// Basic information
$info = [
    'service' => 'CharterHub API',
    'environment' => 'Docker',
    'php_version' => PHP_VERSION,
    'php_extensions' => get_loaded_extensions(),
    'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'PHP Built-in Server',
    'docker' => true,
    'timestamp' => date('Y-m-d H:i:s')
];

// Database configuration
$dbConfig = [
    'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
    'port' => getenv('DB_PORT') ?: '19174',
    'user' => getenv('DB_USER') ?: 'avnadmin',
    'pass' => getenv('DB_PASS') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C',
    'name' => getenv('DB_NAME') ?: 'defaultdb',
    'ssl' => getenv('DB_SSL') ?: 'REQUIRED'
];

// Test database connection
$dbConnection = false;
$dbError = null;
$tables = [];

try {
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    // Add SSL options if required
    if ($dbConfig['ssl'] === 'REQUIRED') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = true;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);
    $dbConnection = true;
    
    // Get tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Add tables to response
    $info['database'] = [
        'connected' => true,
        'tables_count' => count($tables),
        'tables' => $tables
    ];
    
} catch (PDOException $e) {
    $info['database'] = [
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

// Add environment variables (masked)
$env = [];
foreach ($_ENV as $key => $value) {
    if (in_array(strtolower($key), ['db_pass', 'db_password', 'jwt_secret', 'jwt_refresh_secret'])) {
        $env[$key] = '******';
    } else {
        $env[$key] = $value;
    }
}
$info['environment_vars'] = $env;

// Output JSON
echo json_encode($info, JSON_PRETTY_PRINT);
exit; 