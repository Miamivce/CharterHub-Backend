<?php
/**
 * CharterHub Refresh Token Helper
 * 
 * This file manages the generation, storage, and validation of refresh tokens.
 */

// Define CHARTERHUB_LOADED constant if not already defined
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include required dependencies
require_once __DIR__ . '/../utils/database.php';
require_once __DIR__ . '/config.php';

/**
 * Generate a secure refresh token
 * 
 * @param int $length Length of the token
 * @return string The generated token
 */
function generate_secure_refresh_token($length = 64) {
    try {
        return bin2hex(random_bytes($length / 2));
    } catch (Exception $e) {
        error_log("TOKEN-HELPER: Error generating secure token: " . $e->getMessage());
        // Fallback to less secure but still random token
        return md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
    }
}

/**
 * Store a refresh token in the database
 * 
 * @param int $user_id The user ID
 * @param string $token The refresh token
 * @param int $expires_in Expiration time in seconds
 * @return bool Whether the token was successfully stored
 */
function store_refresh_token_safe($user_id, $token, $expires_in = 1209600) {
    try {
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        
        // Check if refresh_tokens table exists, create if not
        if (!tableExists('wp_charterhub_refresh_tokens')) {
            create_refresh_tokens_table();
        }
        
        // Hash token for storage
        $token_hash = hash('sha256', $token);
        
        // Insert using a safer approach that doesn't require an ID field
        $insert_query = "INSERT INTO wp_charterhub_refresh_tokens 
                         (user_id, token_hash, expires_at, created_at, revoked) 
                         VALUES (?, ?, ?, NOW(), 0)";
        
        $success = executeUpdate($insert_query, [
            $user_id,
            $token_hash,
            $expires_at
        ]);
        
        if ($success) {
            error_log("TOKEN-HELPER: Token stored successfully for user ID: $user_id");
            return true;
        } else {
            error_log("TOKEN-HELPER: Failed to store token for user ID: $user_id");
            return false;
        }
    } catch (Exception $e) {
        error_log("TOKEN-HELPER: Error storing token: " . $e->getMessage());
        
        // Try an alternative approach if the error is about field 'id'
        if (strpos($e->getMessage(), "Field 'id' doesn't have a default value") !== false) {
            try {
                // Get the next auto-increment value
                $row = fetchRow("SHOW TABLE STATUS LIKE 'wp_charterhub_refresh_tokens'");
                $next_id = isset($row['Auto_increment']) ? (int)$row['Auto_increment'] : 1;
                
                // Insert with explicit ID
                $alt_query = "INSERT INTO wp_charterhub_refresh_tokens 
                              (id, user_id, token_hash, expires_at, created_at, revoked) 
                              VALUES (?, ?, ?, ?, NOW(), 0)";
                
                $success = executeUpdate($alt_query, [
                    $next_id,
                    $user_id,
                    $token_hash,
                    $expires_at
                ]);
                
                if ($success) {
                    error_log("TOKEN-HELPER: Token stored with alternative method for user ID: $user_id");
                    return true;
                }
            } catch (Exception $alt_e) {
                error_log("TOKEN-HELPER: Alternative storage method failed: " . $alt_e->getMessage());
            }
        }
        
        return false;
    }
}

/**
 * Create the refresh tokens table
 * 
 * @return bool Whether the table was successfully created
 */
function create_refresh_tokens_table() {
    try {
        $query = "CREATE TABLE IF NOT EXISTS wp_charterhub_refresh_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (user_id),
            INDEX (token_hash),
            INDEX (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        executeUpdate($query);
        
        error_log("TOKEN-HELPER: Refresh tokens table created or verified");
        return true;
    } catch (Exception $e) {
        error_log("TOKEN-HELPER: Error creating refresh tokens table: " . $e->getMessage());
        return false;
    }
}

/**
 * Validate a refresh token
 * 
 * @param string $token The refresh token to validate
 * @return array|false User data if token is valid, false otherwise
 */
function validate_stored_refresh_token($token) {
    try {
        // Hash token for lookup
        $token_hash = hash('sha256', $token);
        
        // Look up token in database
        $row = fetchRow(
            "SELECT rt.*, u.email, u.role 
             FROM wp_charterhub_refresh_tokens rt
             JOIN wp_charterhub_users u ON rt.user_id = u.id
             WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND rt.revoked = 0",
            [$token_hash]
        );
        
        if (!$row) {
            error_log("TOKEN-HELPER: Invalid or expired token");
            return false;
        }
        
        // Update last used timestamp
        executeUpdate(
            "UPDATE wp_charterhub_refresh_tokens SET last_used_at = NOW() WHERE id = ?",
            [$row['id']]
        );
        
        // Return user data
        return [
            'user_id' => $row['user_id'],
            'email' => $row['email'],
            'role' => $row['role']
        ];
    } catch (Exception $e) {
        error_log("TOKEN-HELPER: Error validating token: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke a refresh token
 * 
 * @param string $token The refresh token to revoke
 * @return bool Whether the token was successfully revoked
 */
function revoke_stored_refresh_token($token) {
    try {
        // Hash token for lookup
        $token_hash = hash('sha256', $token);
        
        // Look up and revoke token
        $success = executeUpdate(
            "UPDATE wp_charterhub_refresh_tokens SET revoked = 1 WHERE token_hash = ?",
            [$token_hash]
        );
        
        return $success > 0;
    } catch (Exception $e) {
        error_log("TOKEN-HELPER: Error revoking token: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke all refresh tokens for a user
 * 
 * @param int $user_id The user ID
 * @return int Number of tokens revoked
 */
function revoke_all_refresh_tokens_for_user($user_id) {
    try {
        $count = executeUpdate(
            "UPDATE wp_charterhub_refresh_tokens SET revoked = 1 WHERE user_id = ?",
            [$user_id]
        );
        
        error_log("TOKEN-HELPER: Revoked $count refresh token(s) for user ID: $user_id");
        return $count;
    } catch (Exception $e) {
        error_log("TOKEN-HELPER: Error revoking all tokens: " . $e->getMessage());
        return 0;
    }
}

// Ensure table exists
if (!tableExists('wp_charterhub_refresh_tokens')) {
    create_refresh_tokens_table();
} 