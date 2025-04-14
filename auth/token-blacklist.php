<?php
/**
 * CharterHub Token Blacklist Management
 * 
 * This file manages the token blacklist for JWT authentication.
 * It provides functions for adding tokens to the blacklist, checking if tokens are blacklisted,
 * and performing maintenance cleanup of expired blacklisted tokens.
 * 
 * Part of the JWT Authentication System Refactoring (Phase 1, Step 1)
 */

// Define CHARTERHUB_LOADED constant if not already defined
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include required dependencies
// require_once __DIR__ . '/../db-config.php';
// require_once __DIR__ . '/jwt-core.php'; // Removed to prevent circular dependency
require_once __DIR__ . '/../utils/database.php';

// Check if the parse_jwt_token function is defined (should be in jwt-core.php)
if (!function_exists('parse_jwt_token')) {
    /**
     * Simplified JWT token parser for when jwt-core.php is not yet loaded
     * Only extracts the payload for blacklisting purposes
     * 
     * @param string $token The JWT token to parse
     * @return array|false The parsed token with 'header' and 'payload' or false on failure
     */
    function parse_jwt_token($token) {
        try {
            $parts = explode('.', $token);
            if (count($parts) != 3) {
                return false;
            }
            
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            
            if (!$payload) {
                return false;
            }
            
            return [
                'header' => [],
                'payload' => $payload
            ];
        } catch (Exception $e) {
            error_log("Failed to parse JWT token: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Add a token to the blacklist
 * 
 * @param string $token The token to blacklist
 * @param string $reason The reason for blacklisting (logout, security, etc.)
 * @return bool Whether the token was successfully blacklisted
 */
function blacklist_token($token, $reason = 'logout') {
    try {
        // Parse token to extract information
        $parsed_token = parse_jwt_token($token);
        if (!$parsed_token) {
            error_log("BLACKLIST: Failed to parse token for blacklisting");
            return false;
        }
        
        $payload = $parsed_token['payload'];
        
        // Required fields
        if (!isset($payload['jti']) || !isset($payload['exp']) || !isset($payload['sub'])) {
            error_log("BLACKLIST: Token missing required fields for blacklisting");
            return false;
        }
        
        $token_id = $payload['jti'];
        $user_id = $payload['sub'];
        $expiration = $payload['exp'];
        
        // First, check if token is already blacklisted
        $existingToken = fetchRow(
            "SELECT id FROM wp_charterhub_token_blacklist WHERE token_id = ?",
            [$token_id]
        );
        
        if ($existingToken) {
            // Token already blacklisted
            error_log("BLACKLIST: Token already blacklisted (ID: $token_id)");
            return true;
        }
        
        // Add token to blacklist
        $success = executeUpdate(
            "INSERT INTO wp_charterhub_token_blacklist 
             (token_id, user_id, original_exp, blacklisted_at, reason) 
             VALUES (?, ?, FROM_UNIXTIME(?), NOW(), ?)",
            [$token_id, $user_id, $expiration, $reason]
        );
        
        if ($success) {
            error_log("BLACKLIST: Added token to blacklist (ID: $token_id)");
            
            // Attempt to revoke the token in the tokens table as well
            try {
                $affected = executeUpdate(
                    "UPDATE wp_charterhub_jwt_tokens
                     SET revoked = 1
                     WHERE token_hash = ? OR refresh_token_hash = ?",
                    [$token_id, $token_id]
                );
                
                if ($affected > 0) {
                    error_log("BLACKLIST: Revoked $affected token(s) in jwt_tokens table (ID: $token_id)");
                }
            } catch (Exception $e) {
                error_log("BLACKLIST: Error revoking token in jwt_tokens table: " . $e->getMessage());
            }
            
            return true;
        } else {
            error_log("BLACKLIST: Failed to add token to blacklist");
            return false;
        }
    } catch (Exception $e) {
        error_log("BLACKLIST: Error blacklisting token: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a token is blacklisted
 * 
 * @param string $token_id The token ID (JTI claim) to check
 * @return bool Whether the token is blacklisted
 */
function is_token_blacklisted($token_id) {
    try {
        $result = fetchRow(
            "SELECT id FROM wp_charterhub_token_blacklist WHERE token_id = ?",
            [$token_id]
        );
        
        return $result !== false;
    } catch (Exception $e) {
        error_log("BLACKLIST: Error checking blacklist: " . $e->getMessage());
        return false; // Default to not blacklisted if check fails
    }
}

/**
 * Blacklist all tokens for a specific user
 * 
 * @param int $user_id The user ID
 * @param string $reason The reason for blacklisting
 * @return bool Whether the operation was successful
 */
function blacklist_all_user_tokens($user_id, $reason = 'security') {
    try {
        // First, revoke all tokens for this user in the tokens table
        revoke_all_user_tokens($user_id);
        
        // Now, get active tokens for this user
        $tokens = fetchRows(
            "SELECT token_hash, refresh_token_hash FROM wp_charterhub_jwt_tokens 
             WHERE user_id = ? AND revoked = 0",
            [$user_id]
        );
        
        $count = 0;
        
        // Blacklist each token
        foreach ($tokens as $row) {
            if (!empty($row['token_hash'])) {
                blacklist_token($row['token_hash'], $reason);
                $count++;
            }
            
            if (!empty($row['refresh_token_hash']) && $row['refresh_token_hash'] !== $row['token_hash']) {
                blacklist_token($row['refresh_token_hash'], $reason);
                $count++;
            }
        }
        
        return $count > 0;
    } catch (Exception $e) {
        error_log("BLACKLIST: Error blacklisting user tokens: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up expired tokens from the blacklist
 * Removes tokens that have been blacklisted and are now past their original expiration time
 * 
 * @param int $days_to_keep Number of days to keep expired tokens in the blacklist for audit purposes
 * @return int Number of tokens removed from the blacklist
 */
function cleanup_token_blacklist($days_to_keep = 30) {
    try {
        $count = executeUpdate(
            "DELETE FROM wp_charterhub_token_blacklist 
             WHERE original_exp < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days_to_keep]
        );
        
        error_log("BLACKLIST: Cleaned up $count expired tokens from blacklist");
        
        return $count;
    } catch (Exception $e) {
        error_log("BLACKLIST: Error cleaning up token blacklist: " . $e->getMessage());
        return 0;
    }
}

/**
 * Create or update the token blacklist table
 * 
 * @return bool Whether the table was successfully created/updated
 */
function create_token_blacklist_table() {
    try {
        $query = "CREATE TABLE IF NOT EXISTS wp_charterhub_token_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_id VARCHAR(255) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            original_exp DATETIME NOT NULL,
            blacklisted_at DATETIME NOT NULL,
            reason VARCHAR(50) NOT NULL DEFAULT 'logout',
            INDEX (token_id),
            INDEX (user_id),
            INDEX (original_exp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        executeUpdate($query);
        
        error_log("BLACKLIST: Token blacklist table created or verified");
        return true;
    } catch (Exception $e) {
        error_log("BLACKLIST: Error creating token blacklist table: " . $e->getMessage());
        return false;
    }
}

// Create the token blacklist table if it doesn't exist
if (!function_exists('table_exists')) {
    /**
     * Check if a table exists in the database
     * 
     * @param string $table_name The name of the table to check
     * @return bool Whether the table exists
     */
    function table_exists($table_name) {
        return tableExists($table_name);
    }
}

/**
 * Revoke all tokens for a specific user
 * 
 * @param int $user_id The user ID
 * @return int Number of tokens revoked
 */
function revoke_all_user_tokens($user_id) {
    try {
        $count = executeUpdate(
            "UPDATE wp_charterhub_jwt_tokens SET revoked = 1 WHERE user_id = ?",
            [$user_id]
        );
        
        error_log("BLACKLIST: Revoked $count token(s) for user ID: $user_id");
        return $count;
    } catch (Exception $e) {
        error_log("BLACKLIST: Error revoking user tokens: " . $e->getMessage());
        return 0;
    }
}

// Automatically create the token blacklist table if it doesn't exist
if (!table_exists('wp_charterhub_token_blacklist')) {
    create_token_blacklist_table();
} 