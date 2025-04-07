<?php
/**
 * JWT Tables Fix Script
 * 
 * This script checks for and fixes any references to the incorrect table name
 * wp_jwt_tokens, ensuring the correct table name wp_charterhub_jwt_tokens is used.
 */

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Create a log file for this script
$log_file = __DIR__ . '/jwt_tables_fix.log';
file_put_contents($log_file, date('Y-m-d H:i:s') . " - JWT Tables Fix Script Started\n");

function log_message($message) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    echo "$message<br>";
}

// Include config file
try {
    log_message("Including config.php");
    require_once dirname(__FILE__) . '/config.php';
    log_message("Config loaded successfully");
} catch (Exception $e) {
    log_message("Error loading config: " . $e->getMessage());
    die("Failed to load configuration");
}

// Test database connection
try {
    log_message("Testing database connection");
    $conn = get_db_connection_from_config();
    log_message("Database connection successful");
} catch (Exception $e) {
    log_message("Database connection failed: " . $e->getMessage());
    die("Failed to connect to database");
}

// Check if the correct table exists
log_message("Checking if wp_charterhub_jwt_tokens table exists");
$result = $conn->query("SHOW TABLES LIKE 'wp_charterhub_jwt_tokens'");
$correct_table_exists = ($result && $result->rowCount() > 0);

if ($correct_table_exists) {
    log_message("✅ wp_charterhub_jwt_tokens table exists");
} else {
    log_message("❌ wp_charterhub_jwt_tokens table does not exist");
    
    // Create the correct table
    log_message("Creating wp_charterhub_jwt_tokens table");
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS wp_charterhub_jwt_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            refresh_token_hash VARCHAR(255) NULL,
            expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            INDEX (user_id),
            INDEX (token_hash),
            INDEX (refresh_token_hash),
            INDEX (expires_at),
            INDEX (revoked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        log_message("✅ wp_charterhub_jwt_tokens table created successfully");
    } catch (Exception $e) {
        log_message("❌ Error creating wp_charterhub_jwt_tokens table: " . $e->getMessage());
    }
}

// Check if the incorrect table exists
log_message("Checking if wp_jwt_tokens table exists");
$result = $conn->query("SHOW TABLES LIKE 'wp_jwt_tokens'");
$incorrect_table_exists = ($result && $result->rowCount() > 0);

if ($incorrect_table_exists) {
    log_message("Found incorrect table wp_jwt_tokens");
    
    // If both tables exist, migrate data from incorrect to correct table
    if ($correct_table_exists) {
        log_message("Migrating data from wp_jwt_tokens to wp_charterhub_jwt_tokens");
        try {
            // First, check if there's any data to migrate
            $count_result = $conn->query("SELECT COUNT(*) as count FROM wp_jwt_tokens");
            $count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count > 0) {
                log_message("Found $count records to migrate");
                
                // Begin transaction
                $conn->beginTransaction();
                
                // Insert data from old table to new table, avoiding duplicates
                $stmt = $conn->prepare("INSERT IGNORE INTO wp_charterhub_jwt_tokens 
                            (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at)
                            SELECT user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at
                            FROM wp_jwt_tokens");
                $stmt->execute();
                
                $migrated = $stmt->rowCount();
                log_message("Migrated $migrated records to wp_charterhub_jwt_tokens");
                
                // Commit transaction
                $conn->commit();
            } else {
                log_message("No records to migrate from wp_jwt_tokens");
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            log_message("❌ Error migrating data: " . $e->getMessage());
        }
    }
    
    // Rename the incorrect table to avoid future confusion
    log_message("Renaming wp_jwt_tokens to wp_jwt_tokens_old");
    try {
        $conn->exec("RENAME TABLE wp_jwt_tokens TO wp_jwt_tokens_old");
        log_message("✅ Table renamed successfully");
    } catch (Exception $e) {
        log_message("❌ Error renaming table: " . $e->getMessage());
    }
} else {
    log_message("✅ Incorrect table wp_jwt_tokens does not exist");
}

// Create a view for backward compatibility
log_message("Creating view for backward compatibility");
try {
    $conn->exec("CREATE OR REPLACE VIEW wp_jwt_tokens AS SELECT * FROM wp_charterhub_jwt_tokens");
    log_message("✅ Created view wp_jwt_tokens pointing to wp_charterhub_jwt_tokens");
} catch (Exception $e) {
    log_message("❌ Error creating view: " . $e->getMessage());
}

// Final check
log_message("Performing final check");
try {
    $result = $conn->query("SHOW TABLES LIKE 'wp_charterhub_jwt_tokens'");
    if ($result && $result->rowCount() > 0) {
        log_message("✅ wp_charterhub_jwt_tokens table exists");
        
        // Count rows
        $count_result = $conn->query("SELECT COUNT(*) as count FROM wp_charterhub_jwt_tokens");
        $count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
        log_message("wp_charterhub_jwt_tokens has $count rows");
    } else {
        log_message("❌ wp_charterhub_jwt_tokens table still does not exist");
    }
    
    // Check if view exists
    $result = $conn->query("SHOW TABLES LIKE 'wp_jwt_tokens'");
    if ($result && $result->rowCount() > 0) {
        log_message("✅ wp_jwt_tokens view exists");
    } else {
        log_message("❌ wp_jwt_tokens view does not exist");
    }
} catch (Exception $e) {
    log_message("❌ Error during final check: " . $e->getMessage());
}

log_message("JWT Tables Fix Script Completed"); 