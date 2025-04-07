<?php
/**
 * Alter JWT Token Table
 * 
 * This script alters the wp_charterhub_jwt_tokens table to increase the size of the refresh_token_hash column
 * from varchar(255) to varchar(512).
 */

// Include database connection
require_once __DIR__ . '/db-connection.php';

try {
    $db = get_db_connection();
    
    // Check if the table exists
    $stmt = $db->query("SHOW TABLES LIKE 'wp_charterhub_jwt_tokens'");
    if ($stmt->rowCount() == 0) {
        echo "Table wp_charterhub_jwt_tokens does not exist. Please run create-jwt-token-table.php first.\n";
        exit(1);
    }
    
    // Check the current column size
    $stmt = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_NAME = 'wp_charterhub_jwt_tokens' 
                        AND COLUMN_NAME = 'refresh_token_hash'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($column && $column['COLUMN_TYPE'] === 'varchar(512)') {
        echo "Column refresh_token_hash is already varchar(512). No changes needed.\n";
        exit(0);
    }
    
    // Alter the table to increase the column size
    $sql = "ALTER TABLE wp_charterhub_jwt_tokens 
            MODIFY COLUMN `refresh_token_hash` varchar(512) DEFAULT NULL";
    
    $db->exec($sql);
    echo "Column refresh_token_hash in wp_charterhub_jwt_tokens increased to varchar(512) successfully.\n";
    
} catch (PDOException $e) {
    echo "Error altering table: " . $e->getMessage() . "\n";
    exit(1);
} 