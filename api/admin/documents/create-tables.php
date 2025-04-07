<?php
/**
 * Create Document Tables Script
 * 
 * Ensures that the document tables exist with the correct structure
 */

// Include database configuration
require_once __DIR__ . '/../../../db-config.php';

try {
    // Get database connection using the correct function name
    $pdo = get_db_connection_from_config();
    
    // Check if the documents table exists
    $checkTableStmt = $pdo->prepare("
        SELECT 1 FROM information_schema.tables 
        WHERE table_schema = DATABASE() AND table_name = 'wp_charterhub_documents'
    ");
    $checkTableStmt->execute();
    $tableExists = $checkTableStmt->fetchColumn();
    
    if (!$tableExists) {
        // Create documents table
        $pdo->exec("
            CREATE TABLE wp_charterhub_documents (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_type VARCHAR(50) NOT NULL,
                file_size BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                visibility VARCHAR(20) NOT NULL DEFAULT 'private',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX (user_id),
                INDEX (visibility)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        echo "Documents table created successfully.\n";
    } else {
        echo "Documents table already exists.\n";
    }
    
    // Output success message
    echo "All document tables are set up correctly!\n";
    
} catch (PDOException $e) {
    // Output error message
    echo "Error setting up document tables: " . $e->getMessage() . "\n";
} 