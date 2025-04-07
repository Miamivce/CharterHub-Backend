<?php
/**
 * CharterHub Users Table Creator
 * Creates the wp_charterhub_users table for client authentication
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include required files
require_once __DIR__ . '/config.php';

// Set JSON content type header
header('Content-Type: application/json; charset=UTF-8');

// Initialize response
$response = [
    'success' => true,
    'message' => 'CharterHub users table check',
    'timestamp' => date('Y-m-d H:i:s'),
    'actions' => [],
    'results' => []
];

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // Check if the table already exists
    $stmt = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}charterhub_clients'");
    if ($stmt && $stmt->rowCount() > 0) {
        $response['actions'][] = "The {$db_config['table_prefix']}charterhub_clients table already exists";
        $response['results']['table_exists'] = true;
        
        // Check existing columns
        $stmt = $pdo->query("SHOW COLUMNS FROM {$db_config['table_prefix']}charterhub_clients");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $response['results']['existing_columns'] = $columns;
    } else {
        // Create the table with all required columns
        $stmt = $pdo->prepare("CREATE TABLE `{$db_config['table_prefix']}charterhub_clients` (
            `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` varchar(100) NOT NULL,
            `email` varchar(255) NOT NULL,
            `password` varchar(255) NOT NULL,
            `display_name` varchar(255) NOT NULL,
            `first_name` varchar(100) NOT NULL,
            `last_name` varchar(100) NOT NULL,
            `role` varchar(50) NOT NULL DEFAULT 'charter_client',
            `verified` tinyint(1) NOT NULL DEFAULT '0',
            `refresh_token` varchar(255) DEFAULT NULL,
            `last_login` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`),
            UNIQUE KEY `email` (`email`),
            KEY `role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Execute table creation
        $stmt->execute();
        
        $response['actions'][] = "Created the {$db_config['table_prefix']}charterhub_clients table";
        $response['results']['table_created'] = true;
        $response['results']['sql_used'] = $stmt->queryString;
    }
    
    // Verify foreign key relationship with wp_users
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = '{$db_config['table_prefix']}charterhub_clients'
        AND REFERENCED_TABLE_NAME = '{$db_config['table_prefix']}users'
    ");
    $fk_exists = ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0);
    $response['results']['foreign_key_exists'] = $fk_exists;
    
    // Get table status
    $stmt = $pdo->query("
        SELECT COUNT(*) as total,
        SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified_count,
        COUNT(DISTINCT role) as role_count
        FROM {$db_config['table_prefix']}charterhub_clients
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['results']['table_stats'] = $stats;
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    $response['error'] = $e->getMessage();
    http_response_code(500);
    echo json_encode($response, JSON_PRETTY_PRINT);
} 