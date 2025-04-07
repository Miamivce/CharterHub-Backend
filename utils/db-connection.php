<?php
/**
 * Database Connection Utility
 * 
 * This file provides a consistent way to connect to the database
 * across the application.
 */

/**
 * Get database connection
 * 
 * @return PDO Database connection
 * @throws PDOException If connection fails
 */
function get_db_connection() {
    // Database configuration
    $db_config = [
        'host' => 'localhost',
        'name' => 'charterhub_local',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
        'table_prefix' => 'wp_'
    ];
    
    // DSN
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['name']};charset={$db_config['charset']}";
    
    // PDO options
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    // Create connection
    return new PDO($dsn, $db_config['user'], $db_config['pass'], $options);
} 