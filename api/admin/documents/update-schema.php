<?php
/**
 * Document Management Schema Update Script
 * 
 * Updates the wp_charterhub_documents table:
 * 1. Removes wp_users foreign key constraint
 * 2. Changes user_id to reference wp_charterhub_users instead
 * 3. Ensures all required columns exist
 */

// Include database configuration
require_once __DIR__ . '/../../../db-config.php';

// Include Response helper
require_once __DIR__ . '/../../../helpers/Response.php';

// Include auth helpers
require_once __DIR__ . '/../../../auth/global-cors.php';
require_once __DIR__ . '/../../../auth/jwt-core.php';
require_once __DIR__ . '/../../../auth/token-blacklist.php';

// Define CHARTERHUB_LOADED for global CORS if not already defined
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Apply CORS headers
apply_global_cors(['GET', 'POST', 'OPTIONS']);

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// In development mode, skip auth checks
$isDevelopmentMode = true;

if (!$isDevelopmentMode) {
    // Verify JWT token and get user info
    $userPayload = get_authenticated_user(true, ['admin']);
    if (!$userPayload) {
        Response::authError('Unauthorized access');
    }
} else {
    // Mock admin user payload for development
    $userPayload = [
        'sub' => 14,
        'email' => 'admin@charterhub.com',
        'role' => 'admin'
    ];
}

try {
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Step 1: Check if table exists
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
                user_id INT NOT NULL,
                visibility VARCHAR(20) NOT NULL DEFAULT 'private',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Add foreign key constraint to wp_charterhub_users
        $pdo->exec("
            ALTER TABLE wp_charterhub_documents
            ADD CONSTRAINT fk_documents_charterhub_user
            FOREIGN KEY (user_id) REFERENCES wp_charterhub_users(id)
            ON DELETE CASCADE
        ");
        
        $result = [
            'message' => 'Documents table created successfully',
            'created_new_table' => true
        ];
    } else {
        // Step 2: Check for existing foreign key constraints
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_NAME = 'wp_charterhub_documents'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            AND CONSTRAINT_SCHEMA = DATABASE()
        ");
        
        $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Drop existing foreign key constraints
        foreach ($constraints as $constraint) {
            $pdo->exec("ALTER TABLE wp_charterhub_documents DROP FOREIGN KEY `{$constraint}`");
        }
        
        // Step 3: Check if required columns exist
        $columns = [
            ['name' => 'document_type', 'sql' => "ADD COLUMN document_type VARCHAR(50) NOT NULL DEFAULT 'other'"],
            ['name' => 'notes', 'sql' => "ADD COLUMN notes TEXT"],
            ['name' => 'booking_id', 'sql' => "ADD COLUMN booking_id INT NULL"],
            ['name' => 'uploaded_by', 'sql' => "ADD COLUMN uploaded_by INT NOT NULL"],
            ['name' => 'uploader_name', 'sql' => "ADD COLUMN uploader_name VARCHAR(255)"]
        ];
        
        $columnsAdded = [];
        foreach ($columns as $column) {
            $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_documents LIKE '{$column['name']}'");
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE wp_charterhub_documents {$column['sql']}");
                $columnsAdded[] = $column['name'];
            }
        }
        
        // Step 4: Add foreign key constraint to wp_charterhub_users
        $pdo->exec("
            ALTER TABLE wp_charterhub_documents
            ADD CONSTRAINT fk_documents_charterhub_user
            FOREIGN KEY (user_id) REFERENCES wp_charterhub_users(id)
            ON DELETE CASCADE
        ");
        
        $result = [
            'message' => 'Documents table updated successfully',
            'constraints_dropped' => $constraints,
            'columns_added' => $columnsAdded
        ];
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    Response::success($result, 200, 'Database schema updated successfully');
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    Response::serverError('Error updating database schema', $e);
} 