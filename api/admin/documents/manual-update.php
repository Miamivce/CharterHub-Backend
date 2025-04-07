<?php
/**
 * Manual Document Management Schema Update Script
 */

// Include database configuration
require_once __DIR__ . '/../../../db-config.php';
require_once __DIR__ . '/../../../helpers/Response.php';

// Set headers for JSON response
header('Content-Type: application/json');

try {
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_documents'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Create table if it doesn't exist
        $pdo->exec("
            CREATE TABLE wp_charterhub_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                document_type VARCHAR(50) NOT NULL DEFAULT 'other',
                file_path VARCHAR(255) NOT NULL,
                file_type VARCHAR(50) NOT NULL,
                file_size BIGINT NOT NULL,
                notes TEXT,
                booking_id INT NULL,
                uploaded_by INT NOT NULL,
                uploader_name VARCHAR(255),
                user_id INT NULL,
                visibility VARCHAR(20) NOT NULL DEFAULT 'private',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        echo json_encode([
            'success' => true,
            'message' => 'Documents table created successfully',
            'created_new_table' => true
        ]);
        exit;
    }
    
    // Check if columns exist and add them if they don't
    $columns = [
        ['name' => 'document_type', 'sql' => "ALTER TABLE wp_charterhub_documents ADD COLUMN document_type VARCHAR(50) NOT NULL DEFAULT 'other'"],
        ['name' => 'notes', 'sql' => "ALTER TABLE wp_charterhub_documents ADD COLUMN notes TEXT"],
        ['name' => 'booking_id', 'sql' => "ALTER TABLE wp_charterhub_documents ADD COLUMN booking_id INT NULL"],
        ['name' => 'uploaded_by', 'sql' => "ALTER TABLE wp_charterhub_documents MODIFY COLUMN uploaded_by INT NOT NULL"],
        ['name' => 'uploader_name', 'sql' => "ALTER TABLE wp_charterhub_documents ADD COLUMN uploader_name VARCHAR(255)"],
        ['name' => 'user_id', 'sql' => "ALTER TABLE wp_charterhub_documents MODIFY COLUMN user_id INT NULL"]
    ];
    
    $columnsAdded = [];
    $columnsModified = [];
    
    foreach ($columns as $column) {
        $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_documents LIKE '{$column['name']}'");
        if ($stmt->rowCount() === 0) {
            // Column doesn't exist, add it
            try {
                $pdo->exec($column['sql']);
                $columnsAdded[] = $column['name'];
            } catch (Exception $e) {
                // Skip errors if the column already exists
                error_log("Error adding column {$column['name']}: " . $e->getMessage());
            }
        } else {
            // Column exists, check if it needs modification
            if (strpos($column['sql'], 'MODIFY COLUMN') !== false) {
                try {
                    $pdo->exec($column['sql']);
                    $columnsModified[] = $column['name'];
                } catch (Exception $e) {
                    // Skip errors on modification
                    error_log("Error modifying column {$column['name']}: " . $e->getMessage());
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Documents table updated successfully',
        'columns_added' => $columnsAdded,
        'columns_modified' => $columnsModified
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error updating database schema: ' . $e->getMessage()
    ]);
} 