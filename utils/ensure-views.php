<?php
/**
 * CharterHub Database View Compatibility Layer
 * 
 * This file provides functions to ensure database views are created
 * for backward compatibility with code that doesn't use table prefixes.
 */

// Define CHARTERHUB_LOADED constant if not already defined
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

/**
 * Ensure database views exist for unprefixed table access
 * 
 * This function checks if necessary views exist and creates them if not.
 * It helps maintain compatibility between code that expects tables with and without prefixes.
 * 
 * @param PDO $pdo Database connection
 * @return bool True if views were created successfully, false otherwise
 */
function ensureDatabaseViews($pdo) {
    try {
        // Make sure we have a valid PDO connection
        if (!$pdo) {
            error_log("Error: No database connection provided");
            return false;
        }
        
        // Get table prefix from global config or use wp_ as default
        global $db_config;
        $prefix = isset($db_config['table_prefix']) ? $db_config['table_prefix'] : 'wp_';
        
        error_log("Using table prefix: " . $prefix);
        
        // Core tables that need views
        $tables_to_map = [
            $prefix . 'charterhub_users' => 'charterhub_users',
            $prefix . 'charterhub_auth_logs' => 'charterhub_auth_logs',
            $prefix . 'charterhub_invitations' => 'charterhub_invitations',
            $prefix . 'charterhub_token_blacklist' => 'charterhub_token_blacklist',
            $prefix . 'charterhub_booking_guests' => 'charterhub_booking_guests',
            $prefix . 'charterhub_bookings' => 'charterhub_bookings'
        ];
        
        // Count of created views
        $created_count = 0;
        
        // Create views for each source table if it exists
        foreach ($tables_to_map as $source_table => $view_name) {
            // Skip if view name is same as source (would cause circular reference)
            if ($source_table === $view_name) {
                continue;
            }
            
            // Check if the source table exists
            $stmt = $pdo->query("SHOW TABLES LIKE '{$source_table}'");
            $table_exists = $stmt && $stmt->rowCount() > 0;
            
            if ($table_exists) {
                // Check if view already exists
                $stmt = $pdo->query("SHOW TABLES LIKE '{$view_name}'");
                $view_exists = $stmt && $stmt->rowCount() > 0;
                
                // If view exists, check if it's actually a view and not a table
                if ($view_exists) {
                    $stmt = $pdo->query("SHOW FULL TABLES WHERE `Tables_in_" . $db_config['dbname'] . "` = '{$view_name}' AND `Table_type` = 'VIEW'");
                    $is_view = $stmt && $stmt->rowCount() > 0;
                    
                    if (!$is_view) {
                        // It's a table, not a view - log warning but don't overwrite
                        error_log("Warning: {$view_name} exists as a table, not creating view");
                        continue;
                    }
                }
                
                // Create or replace the view
                try {
                    $pdo->exec("CREATE OR REPLACE VIEW `{$view_name}` AS SELECT * FROM `{$source_table}`");
                    $created_count++;
                    error_log("Created/updated view: {$view_name} -> {$source_table}");
                } catch (Exception $e) {
                    error_log("Error creating view {$view_name}: " . $e->getMessage());
                }
            }
        }
        
        error_log("Database views setup complete. Created/checked {$created_count} views.");
        return true;
        
    } catch (Exception $e) {
        error_log("Error ensuring database views: " . $e->getMessage());
        return false;
    }
}
?> 