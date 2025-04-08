<?php
/**
 * CharterHub Database Schema Fix Tool
 * 
 * This script adds missing columns to the wp_charterhub_users table in production.
 * It should be run once to resolve authentication issues.
 */

// Enable error display for diagnostics
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Set content type to JSON for easier parsing
header('Content-Type: application/json');

// Results storage
$results = [
    'status' => 'running',
    'timestamp' => date('Y-m-d H:i:s'),
    'database_connection' => null,
    'changes' => [],
    'errors' => []
];

/**
 * Add a message to the output
 */
function addMessage($message) {
    global $results;
    $results['messages'][] = $message;
    echo $message . "\n";
    flush();
}

try {
    // Include only the database utilities
    require_once __DIR__ . '/utils/database.php';
    addMessage("✅ Database utilities loaded successfully");
    
    // Test database connection
    try {
        $pdo = getDbConnection();
        $results['database_connection'] = "Success";
        addMessage("✅ Database connection established successfully");
    } catch (Exception $e) {
        $results['database_connection'] = "Failed: " . $e->getMessage();
        addMessage("❌ Database connection failed: " . $e->getMessage());
        throw $e;
    }
    
    // Check if password column exists
    $passwordExists = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_users LIKE 'password'");
        $passwordExists = $stmt->rowCount() > 0;
        
        if ($passwordExists) {
            addMessage("✅ Password column already exists");
        } else {
            addMessage("⚠️ Password column missing, will be added");
        }
    } catch (Exception $e) {
        addMessage("❌ Error checking password column: " . $e->getMessage());
        $results['errors'][] = "Error checking password column: " . $e->getMessage();
    }
    
    // Check if token_version column exists
    $tokenVersionExists = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_users LIKE 'token_version'");
        $tokenVersionExists = $stmt->rowCount() > 0;
        
        if ($tokenVersionExists) {
            addMessage("✅ token_version column already exists");
        } else {
            addMessage("⚠️ token_version column missing, will be added");
        }
    } catch (Exception $e) {
        addMessage("❌ Error checking token_version column: " . $e->getMessage());
        $results['errors'][] = "Error checking token_version column: " . $e->getMessage();
    }
    
    // Add password column if it doesn't exist
    if (!$passwordExists) {
        try {
            $pdo->exec("ALTER TABLE wp_charterhub_users ADD COLUMN password VARCHAR(255) NOT NULL AFTER email");
            addMessage("✅ Successfully added password column");
            $results['changes'][] = "Added password column";
            
            // Set temporary passwords for all users
            // Password hash for 'TemporaryPassword123!'
            $tempPasswordHash = '$2y$10$YJaRMg/kRQJgzcgZu.6XHu8fBpB5FSHqZCFQfQVjg5HuL3vf9Mx4u';
            $pdo->exec("UPDATE wp_charterhub_users SET password = '$tempPasswordHash'");
            addMessage("✅ Set temporary passwords for all users");
            $results['changes'][] = "Set temporary passwords";
        } catch (Exception $e) {
            addMessage("❌ Failed to add password column: " . $e->getMessage());
            $results['errors'][] = "Failed to add password column: " . $e->getMessage();
        }
    }
    
    // Add token_version column if it doesn't exist
    if (!$tokenVersionExists) {
        try {
            $pdo->exec("ALTER TABLE wp_charterhub_users ADD COLUMN token_version INT DEFAULT 0 AFTER verified");
            addMessage("✅ Successfully added token_version column");
            $results['changes'][] = "Added token_version column";
        } catch (Exception $e) {
            addMessage("❌ Failed to add token_version column: " . $e->getMessage());
            $results['errors'][] = "Failed to add token_version column: " . $e->getMessage();
        }
    }
    
    // Verify changes
    try {
        $stmt = $pdo->query("DESCRIBE wp_charterhub_users");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = [];
        
        foreach ($columns as $col) {
            $columnNames[] = $col['Field'];
        }
        
        addMessage("📋 Current columns: " . implode(", ", $columnNames));
        
        if (in_array('password', $columnNames) && in_array('token_version', $columnNames)) {
            addMessage("✅ Schema update complete - both columns exist");
            $results['status'] = "completed";
        } else {
            $missing = [];
            if (!in_array('password', $columnNames)) $missing[] = 'password';
            if (!in_array('token_version', $columnNames)) $missing[] = 'token_version';
            
            addMessage("⚠️ Schema update incomplete - still missing: " . implode(", ", $missing));
            $results['status'] = "incomplete";
            $results['missing_columns'] = $missing;
        }
    } catch (Exception $e) {
        addMessage("❌ Failed to verify changes: " . $e->getMessage());
        $results['errors'][] = "Failed to verify changes: " . $e->getMessage();
    }
    
    addMessage("\n---- Schema Update Results ----");
    if (count($results['changes']) > 0) {
        addMessage("✅ Changes made: " . count($results['changes']));
        foreach ($results['changes'] as $change) {
            addMessage("  • " . $change);
        }
    } else {
        addMessage("ℹ️ No changes made");
    }
    
    if (count($results['errors']) > 0) {
        addMessage("❌ Errors encountered: " . count($results['errors']));
        foreach ($results['errors'] as $error) {
            addMessage("  • " . $error);
        }
    }

} catch (Exception $e) {
    $results['status'] = "failed";
    $results['error'] = $e->getMessage();
    addMessage("❌ Critical error: " . $e->getMessage());
}

// Output final JSON result alongside the messages
echo json_encode($results);
?> 