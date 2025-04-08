<?php
/**
 * CharterHub Database Schema Diagnostic Tool
 * 
 * This script analyzes the database structure in depth, focusing on tables used for authentication.
 * It will help identify schema mismatches between development and production.
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
    'tables' => [],
    'schema_analysis' => [],
    'potential_issues' => [],
    'recommendations' => []
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
    // Step 1: Include only the database utilities
    require_once __DIR__ . '/utils/database.php';
    addMessage("✅ Database utilities loaded successfully");
    
    // Step 2: Test database connection
    try {
        $pdo = getDbConnection();
        $results['database_connection'] = "Success";
        addMessage("✅ Database connection established successfully");
        
        // Basic connection test
        $stmt = $pdo->query("SELECT 1 as test");
        $test = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($test && isset($test['test']) && $test['test'] == 1) {
            addMessage("✅ Simple query executed successfully");
        }
    } catch (Exception $e) {
        $results['database_connection'] = "Failed: " . $e->getMessage();
        addMessage("❌ Database connection failed: " . $e->getMessage());
        throw $e;
    }
    
    // Step 3: Get list of all tables
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $results['tables']['all_tables'] = $tables;
        addMessage("📊 Found " . count($tables) . " tables in the database");
        
        // Check for expected tables
        $expected_tables = [
            'wp_charterhub_users',
            'wp_charterhub_token_blacklist',
            'wp_charterhub_jwt_tokens',
            'wp_charterhub_auth_logs',
            'wp_charterhub_invitations',
            'wp_charterhub_bookings',
            'wp_charterhub_booking_guests'
        ];
        
        $missing_tables = [];
        foreach ($expected_tables as $table) {
            if (!in_array($table, $tables)) {
                $missing_tables[] = $table;
            }
        }
        
        if (count($missing_tables) > 0) {
            $results['potential_issues'][] = "Missing tables: " . implode(", ", $missing_tables);
            addMessage("⚠️ Missing expected tables: " . implode(", ", $missing_tables));
        } else {
            addMessage("✅ All expected tables found");
        }
    } catch (Exception $e) {
        $results['tables']['error'] = $e->getMessage();
        addMessage("❌ Failed to get table list: " . $e->getMessage());
    }
    
    // Step 4: Analyze Users Table in Detail
    try {
        if (in_array('wp_charterhub_users', $tables)) {
            // Get column information
            $stmt = $pdo->query("DESCRIBE wp_charterhub_users");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['schema_analysis']['wp_charterhub_users'] = $columns;
            
            // Convert to simpler format for easy reading
            $column_names = [];
            $column_details = [];
            foreach ($columns as $col) {
                $column_names[] = $col['Field'];
                $column_details[$col['Field']] = [
                    'type' => $col['Type'],
                    'null' => $col['Null'],
                    'key' => $col['Key'],
                    'default' => $col['Default'],
                    'extra' => $col['Extra']
                ];
            }
            
            $results['schema_analysis']['wp_charterhub_users_columns'] = $column_names;
            $results['schema_analysis']['wp_charterhub_users_details'] = $column_details;
            
            addMessage("📊 wp_charterhub_users table has " . count($column_names) . " columns");
            addMessage("📋 Columns: " . implode(", ", $column_names));
            
            // Check for required columns
            $required_columns = ['id', 'email', 'password', 'first_name', 'last_name', 'role', 'verified', 'token_version'];
            $missing_columns = [];
            foreach ($required_columns as $col) {
                if (!in_array($col, $column_names)) {
                    $missing_columns[] = $col;
                }
            }
            
            if (count($missing_columns) > 0) {
                $results['potential_issues'][] = "Missing required columns in wp_charterhub_users: " . implode(", ", $missing_columns);
                addMessage("❌ Missing required columns: " . implode(", ", $missing_columns));
                
                // Check for alternative column names (WordPress-style naming)
                $wp_alternatives = [
                    'password' => ['user_pass', 'pass', 'user_password'],
                    'email' => ['user_email'],
                    'first_name' => ['user_first_name', 'firstname'],
                    'last_name' => ['user_last_name', 'lastname'],
                    'verified' => ['user_verified', 'is_verified', 'email_verified']
                ];
                
                foreach ($missing_columns as $missing_col) {
                    if (isset($wp_alternatives[$missing_col])) {
                        $alternatives = $wp_alternatives[$missing_col];
                        $found_alternatives = [];
                        
                        foreach ($alternatives as $alt) {
                            if (in_array($alt, $column_names)) {
                                $found_alternatives[] = $alt;
                            }
                        }
                        
                        if (count($found_alternatives) > 0) {
                            $results['recommendations'][] = "Column '$missing_col' might be named as: " . implode(", ", $found_alternatives);
                            addMessage("💡 Column '$missing_col' might be named as: " . implode(", ", $found_alternatives));
                        }
                    }
                }
            } else {
                addMessage("✅ All required columns found in wp_charterhub_users");
            }
            
            // Sample data (for column verification, not displaying actual values)
            $sample_query = $pdo->prepare("SELECT * FROM wp_charterhub_users LIMIT 1");
            $sample_query->execute();
            $sample = $sample_query->fetch(PDO::FETCH_ASSOC);
            
            if ($sample) {
                $results['schema_analysis']['sample_columns'] = array_keys($sample);
                addMessage("✅ Successfully retrieved sample row to verify columns");
                
                // Check sample for expected columns
                foreach ($required_columns as $col) {
                    if (!array_key_exists($col, $sample) && !in_array($col, $missing_columns)) {
                        $results['potential_issues'][] = "Column '$col' exists in schema but not in data";
                        addMessage("⚠️ Column '$col' exists in schema but not in data");
                    }
                }
            } else {
                addMessage("⚠️ No data found in wp_charterhub_users table");
            }
        } else {
            addMessage("❌ wp_charterhub_users table not found");
            $results['potential_issues'][] = "wp_charterhub_users table not found";
        }
    } catch (Exception $e) {
        $results['schema_analysis']['error'] = $e->getMessage();
        addMessage("❌ Failed to analyze wp_charterhub_users table: " . $e->getMessage());
    }
    
    // Step 5: Check token_blacklist and auth_logs tables
    // These tables have shown problems in the logs
    try {
        if (in_array('wp_charterhub_token_blacklist', $tables)) {
            $stmt = $pdo->query("DESCRIBE wp_charterhub_token_blacklist");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['schema_analysis']['wp_charterhub_token_blacklist'] = $columns;
            addMessage("✅ wp_charterhub_token_blacklist table structure retrieved");
        }
        
        if (in_array('wp_charterhub_auth_logs', $tables)) {
            $stmt = $pdo->query("DESCRIBE wp_charterhub_auth_logs");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['schema_analysis']['wp_charterhub_auth_logs'] = $columns;
            
            // Check the size of the 'action' column - logs indicated it was too small
            foreach ($columns as $col) {
                if ($col['Field'] == 'action') {
                    addMessage("📊 Auth logs 'action' column type: " . $col['Type']);
                    
                    // Check if it's a VARCHAR with length < 50
                    if (preg_match('/varchar\((\d+)\)/i', $col['Type'], $matches)) {
                        $length = (int)$matches[1];
                        if ($length < 50) {
                            $results['potential_issues'][] = "Auth logs 'action' column is too small (" . $length . " chars)";
                            addMessage("⚠️ Auth logs 'action' column is too small: " . $length . " characters");
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $results['schema_analysis']['error_auth_tables'] = $e->getMessage();
        addMessage("❌ Failed to analyze auth tables: " . $e->getMessage());
    }
    
    // Step 6: Check for WordPress-style user tables as alternatives
    try {
        if (in_array('wp_users', $tables)) {
            addMessage("ℹ️ Found WordPress wp_users table, checking if it could be used");
            
            $stmt = $pdo->query("DESCRIBE wp_users");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $wp_columns = [];
            foreach ($columns as $col) {
                $wp_columns[] = $col['Field'];
            }
            
            $results['schema_analysis']['wp_users_columns'] = $wp_columns;
            
            // Check if it has critical columns
            $critical_wp_cols = ['ID', 'user_login', 'user_pass', 'user_email'];
            $has_critical = true;
            foreach ($critical_wp_cols as $col) {
                if (!in_array($col, $wp_columns)) {
                    $has_critical = false;
                }
            }
            
            if ($has_critical) {
                $results['recommendations'][] = "WordPress wp_users table could be used as an alternative";
                addMessage("💡 WordPress wp_users table has required columns and could be used");
                
                // Sample data count
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM wp_users");
                $count = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($count && isset($count['count'])) {
                    addMessage("ℹ️ wp_users table has " . $count['count'] . " records");
                }
            }
        }
    } catch (Exception $e) {
        $results['schema_analysis']['error_wp_users'] = $e->getMessage();
        addMessage("❌ Failed to analyze WordPress tables: " . $e->getMessage());
    }
    
    // Final status
    $results['status'] = 'completed';
    
    // Generate final recommendations
    if (count($results['potential_issues']) > 0) {
        addMessage("\n---- POTENTIAL ISSUES FOUND ----");
        foreach ($results['potential_issues'] as $issue) {
            addMessage("⚠️ " . $issue);
        }
        
        // Add recommendations based on issues
        if (in_array("Missing required columns in wp_charterhub_users: password", $results['potential_issues']) ||
            in_array("wp_charterhub_users table not found", $results['potential_issues'])) {
            
            $results['recommendations'][] = "Update client-login.php to match the actual database schema";
            $results['recommendations'][] = "Create the missing columns in the database";
            $results['recommendations'][] = "Consider using wp_users table if it exists and has required data";
        }
    } else {
        addMessage("\n✅ No major issues found with database schema");
    }
    
} catch (Exception $e) {
    $results['status'] = 'error';
    $results['error'] = $e->getMessage();
    addMessage("❌ Fatal error: " . $e->getMessage());
}

// Output final JSON result
echo json_encode($results, JSON_PRETTY_PRINT); 