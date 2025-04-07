<?php
/**
 * Create JWT Token Table
 * 
 * This script creates the wp_charterhub_jwt_tokens table if it doesn't already exist.
 * This table is used to store JWT tokens for user authentication.
 */

// Include database connection
require_once __DIR__ . '/db-connection.php';

try {
    $db = get_db_connection();
    
    // Check if the table already exists
    $stmt = $db->query("SHOW TABLES LIKE 'wp_charterhub_jwt_tokens'");
    if ($stmt->rowCount() > 0) {
        echo "Table wp_charterhub_jwt_tokens already exists.\n";
        exit(0);
    }
    
    // Create the table
    $sql = "CREATE TABLE wp_charterhub_jwt_tokens (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) UNSIGNED NOT NULL,
        `token_hash` varchar(255) NOT NULL,
        `refresh_token_hash` varchar(512) DEFAULT NULL,
        `expires_at` datetime NOT NULL,
        `refresh_expires_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `revoked` tinyint(1) NOT NULL DEFAULT 0,
        `last_used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `token_hash` (`token_hash`),
        KEY `refresh_token_hash` (`refresh_token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "Table wp_charterhub_jwt_tokens created successfully.\n";
    
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
    exit(1);
} 