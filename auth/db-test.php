<?php
/**
 * Database Connectivity Test Script
 * 
 * This script tests the database connection and the JWT tables
 */

// Set error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Create a log file for this test
$log_file = __DIR__ . '/db_test.log';
file_put_contents($log_file, date('Y-m-d H:i:s') . " - DB Test Started\n");

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

// Check tables
log_message("Checking tables in database");
$tables_to_check = [
    'wp_charterhub_users',
    'wp_charterhub_jwt_tokens',
    'wp_charterhub_token_blacklist',
    'wp_charterhub_auth_logs'
];

// PDO version of show tables
$tables_result = $conn->query("SHOW TABLES");
$existing_tables = [];
while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
    $existing_tables[] = $row[0];
}

log_message("Found " . count($existing_tables) . " tables in database");
foreach ($existing_tables as $table) {
    log_message("Table found: $table");
}

// Check required tables
foreach ($tables_to_check as $table) {
    if (in_array($table, $existing_tables)) {
        log_message("✅ Required table exists: $table");
        
        // Check table structure - PDO version
        $structure_result = $conn->query("DESCRIBE $table");
        $columns = [];
        while ($row = $structure_result->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'] . " (" . $row['Type'] . ")";
        }
        log_message("Table $table columns: " . implode(", ", $columns));
        
        // Count rows - PDO version
        $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
        $count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
        log_message("Table $table has $count rows");
    } else {
        log_message("❌ Required table missing: $table");
    }
}

// Test token table specifically
if (in_array('wp_charterhub_jwt_tokens', $existing_tables)) {
    log_message("Testing specifics of the JWT tokens table");
    try {
        // Check for any tokens - PDO version
        $token_result = $conn->query("SELECT id, user_id, expires_at, revoked FROM wp_charterhub_jwt_tokens LIMIT 5");
        $tokens = [];
        while ($row = $token_result->fetch(PDO::FETCH_ASSOC)) {
            $tokens[] = $row;
        }
        
        if (count($tokens) > 0) {
            log_message("Found " . count($tokens) . " tokens in the database");
            foreach ($tokens as $token) {
                log_message("Token ID: " . $token['id'] . 
                           ", User ID: " . $token['user_id'] . 
                           ", Expires: " . $token['expires_at'] . 
                           ", Revoked: " . ($token['revoked'] ? 'Yes' : 'No'));
            }
        } else {
            log_message("No tokens found in the database");
        }
    } catch (Exception $e) {
        log_message("Error querying tokens table: " . $e->getMessage());
    }
}

// Test users table
if (in_array('wp_charterhub_users', $existing_tables)) {
    log_message("Testing specifics of the users table");
    try {
        // Check for any users (without showing sensitive info) - PDO version
        $user_result = $conn->query("SELECT id, email, role, verified, token_version FROM wp_charterhub_users LIMIT 5");
        $users = [];
        while ($row = $user_result->fetch(PDO::FETCH_ASSOC)) {
            $users[] = $row;
        }
        
        if (count($users) > 0) {
            log_message("Found " . count($users) . " users in the database");
            foreach ($users as $user) {
                log_message("User ID: " . $user['id'] . 
                           ", Email: " . $user['email'] . 
                           ", Role: " . $user['role'] . 
                           ", Verified: " . ($user['verified'] ? 'Yes' : 'No') . 
                           ", Token Version: " . $user['token_version']);
            }
        } else {
            log_message("No users found in the database");
        }
    } catch (Exception $e) {
        log_message("Error querying users table: " . $e->getMessage());
    }
}

log_message("Database test completed successfully"); 