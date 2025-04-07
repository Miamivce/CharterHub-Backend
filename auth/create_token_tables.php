<?php
/**
 * Create Token Tables Script
 * 
 * This script creates the necessary token tables for JWT authentication.
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include shared config
require_once 'config.php';

header('Content-Type: application/json');

// Connect to the database
try {
    $pdo = get_db_connection_from_config();
    echo json_encode(['success' => true, 'message' => 'Connected to database']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Create all necessary token tables
$tables = [
    // JWT tokens table
    "wp_charterhub_jwt_tokens" => "CREATE TABLE IF NOT EXISTS `wp_charterhub_jwt_tokens` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) UNSIGNED NOT NULL,
        `token_hash` varchar(255) NOT NULL,
        `refresh_token_hash` varchar(255) DEFAULT NULL,
        `expires_at` datetime NOT NULL,
        `refresh_expires_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `revoked` tinyint(1) NOT NULL DEFAULT '0',
        `last_used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `token_hash` (`token_hash`),
        KEY `refresh_token_hash` (`refresh_token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    
    // Token blacklist table
    "wp_charterhub_token_blacklist" => "CREATE TABLE IF NOT EXISTS `wp_charterhub_token_blacklist` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `token_id` VARCHAR(255) NOT NULL UNIQUE,
        `user_id` INT NOT NULL,
        `original_exp` DATETIME NOT NULL,
        `blacklisted_at` DATETIME NOT NULL,
        `reason` VARCHAR(50) NOT NULL DEFAULT 'logout',
        INDEX (`token_id`),
        INDEX (`user_id`),
        INDEX (`original_exp`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
];

$response = ['success' => true, 'created_tables' => [], 'existing_tables' => []];

foreach ($tables as $table_name => $create_sql) {
    try {
        // Check if table exists first
        $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
        $table_exists = ($stmt && $stmt->rowCount() > 0);
        
        if (!$table_exists) {
            $pdo->exec($create_sql);
            $response['created_tables'][] = $table_name;
        } else {
            $response['existing_tables'][] = $table_name;
        }
    } catch (PDOException $e) {
        $response['errors'][] = "Failed to create $table_name: " . $e->getMessage();
    }
}

// Return detailed response
echo json_encode($response, JSON_PRETTY_PRINT); 