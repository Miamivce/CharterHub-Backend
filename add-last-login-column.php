<?php
/**
 * Add last_login column to wp_charterhub_users table
 * 
 * This script adds the last_login column to the wp_charterhub_users table
 * to fix the login issue where the login process fails with a 500 error
 * because it tries to update this column which doesn't exist.
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include configuration and database connection
require_once __DIR__ . '/utils/database.php';

// Set headers for output
header('Content-Type: text/plain');

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // Check if the column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_users LIKE 'last_login'");
    $column_exists = $stmt->rowCount() > 0;
    
    if ($column_exists) {
        echo "The 'last_login' column already exists in the wp_charterhub_users table.\n";
    } else {
        // Add the last_login column
        echo "Adding 'last_login' column to wp_charterhub_users table...\n";
        $pdo->exec("ALTER TABLE wp_charterhub_users ADD COLUMN last_login DATETIME DEFAULT NULL AFTER notes");
        
        echo "Column added successfully!\n";
    }
    
    // Verify the column was added
    $stmt = $pdo->query("DESCRIBE wp_charterhub_users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "\nCurrent columns in wp_charterhub_users:\n";
    print_r($columns);
    
    echo "\nThe issue with login failures due to missing 'last_login' column should be resolved now.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 