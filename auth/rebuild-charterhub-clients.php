<?php
/**
 * Rebuild wp_charterhub_clients Table
 *
 * This script drops the existing wp_charterhub_clients table (if it exists) and recreates it
 * with the correct unified schema.
 * 
 * Fields:
 * - id: bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT (primary key)
 * - username: varchar(100) NOT NULL (unique)
 * - email: varchar(255) NOT NULL (unique)
 * - password: varchar(255) NOT NULL
 * - display_name: varchar(255) NOT NULL
 * - first_name: varchar(100) NOT NULL
 * - last_name: varchar(100) NOT NULL
 * - role: varchar(50) NOT NULL DEFAULT 'charter_client'
 * - verified: tinyint(1) NOT NULL DEFAULT '0'
 * - refresh_token: varchar(255) DEFAULT NULL
 * - last_login: datetime DEFAULT NULL
 * - created_at: datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
 * - updated_at: datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 */

require_once __DIR__ . '/../db-config.php';

$conn = get_db_connection_from_config();
if (!$conn) {
    die("Database connection failed");
}

try {
    // Drop the table if it exists
    $conn->exec("DROP TABLE IF EXISTS wp_charterhub_clients");

    // Create the new table with the unified schema
    $create_sql = "CREATE TABLE `wp_charterhub_clients` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $conn->exec($create_sql);
    echo "Table wp_charterhub_clients has been rebuilt successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?> 