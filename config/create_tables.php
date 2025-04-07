<?php
/**
 * Create Missing Database Tables
 * 
 * This script creates any missing database tables required for the application.
 */

// Include database configuration
require_once __DIR__ . '/database.php';

echo "Starting table creation process...\n";

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // Read the SQL file
    $sql = file_get_contents(__DIR__ . '/create_invitations_table.sql');
    
    // Execute the SQL
    $result = $pdo->exec($sql);
    
    echo "Tables created successfully.\n";
    echo "Executed SQL statements.\n";
    
    // Check if the invitations table exists
    $tables = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_invitations'")->fetchAll();
    if (count($tables) > 0) {
        echo "wp_charterhub_invitations table exists.\n";
    } else {
        echo "ERROR: wp_charterhub_invitations table was not created.\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Table creation process completed.\n"; 