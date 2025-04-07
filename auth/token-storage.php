<?php
/**
 * JWT Token Storage Helper
 * 
 * This file provides functions for storing and verifying JWT tokens
 * in the wp_charterhub_jwt_tokens table.
 */

// Don't allow direct access
if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access not allowed');
}

// Include database connection if not already included
if (!function_exists('get_db_connection')) {
    require_once dirname(__DIR__) . '/utils/db-connection.php';
}

// Include base64url_decode function if it doesn't exist
if (!function_exists('base64url_decode')) {
    /**
     * Helper function for base64url decoding (RFC 7515)
     * 
     * @param string $data The data to decode
     * @return string The decoded data
     */
    function base64url_decode($data) {
        $b64 = strtr($data, '-_', '+/');
        $padlen = 4 - strlen($b64) % 4;
        if ($padlen < 4) {
            $b64 .= str_repeat('=', $padlen);
        }
        return base64_decode($b64);
    }
}

/**
 * Store a JWT token in the database
 * 
 * @param string $token The JWT token to store
 * @param int $user_id The user ID
 * @param int $access_expiry Access token expiry in seconds
 * @param string $refresh_token The refresh token to store (optional)
 * @param int $refresh_expiry Refresh token expiry in seconds (optional)
 * @return bool True if the token was stored successfully
 */
function store_jwt_token($token, $user_id, $access_expiry = 900, $refresh_token = null, $refresh_expiry = 2592000) {
    global $db_config;
    
    // Check if db_config is available, and set default table prefix if not
    $table_prefix = isset($db_config) && isset($db_config['table_prefix']) ? $db_config['table_prefix'] : 'wp_';
    
    try {
        $pdo = get_db_connection();
        
        // Start a transaction to ensure data consistency
        $pdo->beginTransaction();
        $transaction_active = true;
        
        try {
            // Calculate expiry dates
            $access_expires_at = date('Y-m-d H:i:s', time() + $access_expiry);
            $refresh_expires_at = $refresh_token ? date('Y-m-d H:i:s', time() + $refresh_expiry) : null;
            
            // Create token hash for database storage
            $token_hash = hash('sha256', $token);
            $refresh_token_hash = $refresh_token ? hash('sha256', $refresh_token) : null;
            
            // First try to store in wp_charterhub_jwt_tokens table
            // Check if token already exists
            $stmt = $pdo->prepare("
                SELECT id FROM {$table_prefix}charterhub_jwt_tokens
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing token
                $stmt = $pdo->prepare("
                    UPDATE {$table_prefix}charterhub_jwt_tokens
                    SET token_hash = ?,
                        refresh_token_hash = ?,
                        expires_at = ?,
                        refresh_expires_at = ?,
                        revoked = 0,
                        last_used_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $token_hash,
                    $refresh_token_hash,
                    $access_expires_at,
                    $refresh_expires_at,
                    $user_id
                ]);
            } else {
                // Insert new token
                $stmt = $pdo->prepare("
                    INSERT INTO {$table_prefix}charterhub_jwt_tokens
                    (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), 0, NOW())
                ");
                $stmt->execute([
                    $user_id,
                    $token_hash,
                    $refresh_token_hash,
                    $access_expires_at,
                    $refresh_expires_at
                ]);
            }
            
            // Also store refresh token in the users table for compatibility
            if ($refresh_token) {
                try {
                    // First verify the column can handle the data by checking its type
                    $column_check = $pdo->prepare("
                        SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH 
                        FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_NAME = '{$table_prefix}charterhub_users' 
                        AND COLUMN_NAME = 'refresh_token'
                    ");
                    $column_check->execute();
                    $column_info = $column_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($column_info) {
                        $data_type = $column_info['DATA_TYPE'];
                        $max_length = $column_info['CHARACTER_MAXIMUM_LENGTH'];
                        
                        error_log("Token storage: refresh_token column type is {$data_type}" . 
                                 ($max_length ? " with max length {$max_length}" : " with no length limit"));
                        
                        // Check if token might be too long for VARCHAR column
                        if ($data_type === 'varchar' && $max_length && strlen($refresh_token) > $max_length) {
                            error_log("WARNING: refresh_token length (" . strlen($refresh_token) . 
                                     ") exceeds column max length ({$max_length}). Token may be truncated.");
                        }
                    }
                    
                    // Update the users table with the refresh token
                    $stmt = $pdo->prepare("
                        UPDATE {$table_prefix}charterhub_users
                        SET refresh_token = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$refresh_token, $user_id]);
                    
                    $rowsUpdated = $stmt->rowCount();
                    error_log("Refresh token stored in users table. Rows affected: " . $rowsUpdated);
                    
                    if ($rowsUpdated === 0) {
                        error_log("WARNING: Refresh token update query completed but no rows were updated. User ID: " . $user_id);
                    }
                } catch (Exception $e) {
                    // Log error but don't fail the transaction
                    error_log("Error storing refresh token in users table: " . $e->getMessage());
                    // Continue with the transaction since the main token storage in jwt_tokens succeeded
                }
            }
            
            // Commit the transaction
            if ($transaction_active) {
                try {
                    $pdo->commit();
                    $transaction_active = false;
                } catch (Exception $commitEx) {
                    error_log("Error committing transaction in store_jwt_token: " . $commitEx->getMessage());
                    // Don't throw the exception, we'll return false below
                    return false;
                }
            }
            return true;
            
        } catch (Exception $e) {
            // Roll back transaction if there's an error
            if ($transaction_active) {
                try {
                    $pdo->rollBack();
                    $transaction_active = false;
                } catch (Exception $rollbackEx) {
                    error_log("Error rolling back transaction in store_jwt_token: " . $rollbackEx->getMessage());
                    // Can't do much if rollback fails
                }
            }
            
            error_log("Error storing JWT token in database: " . $e->getMessage());
            return false;
        }
    } catch (Exception $e) {
        error_log("Error connecting to database: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify a JWT token exists in the database and is not revoked
 * 
 * @param string $token The JWT token to verify
 * @param bool $allow_expired Whether to allow expired tokens
 * @return array An array with 'valid' status and additional info
 */
function verify_token_in_database($token, $allow_expired = false) {
    global $db_config;
    
    // Check if db_config is available, and set default table prefix if not
    $table_prefix = isset($db_config) && isset($db_config['table_prefix']) ? $db_config['table_prefix'] : 'wp_';
    
    try {
        $pdo = get_db_connection();
        
        // Create token hash
        $token_hash = hash('sha256', $token);
        
        // Debug log
        error_log("Verifying token in database. Hash: " . $token_hash);
        error_log("Using table prefix: " . $table_prefix);
        
        // FIRST, check if token was explicitly revoked in the past
        $check_revoked_stmt = $pdo->prepare("
            SELECT COUNT(*) as revoked_count 
            FROM {$table_prefix}charterhub_jwt_tokens 
            WHERE token_hash = ? AND revoked = 1
        ");
        $check_revoked_stmt->execute([$token_hash]);
        $revoked_result = $check_revoked_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If this token was previously revoked, reject it regardless
        if ($revoked_result && $revoked_result['revoked_count'] > 0) {
            error_log("Token was previously revoked, rejecting it without reinsertion");
            return [
                'valid' => false,
                'reason' => 'revoked',
                'token_hash' => $token_hash
            ];
        }
        
        try {
            // Try to find token in the jwt_tokens table first
            $sql = "
                SELECT t.*, u.role, u.verified
                FROM {$table_prefix}charterhub_jwt_tokens t
                JOIN {$table_prefix}charterhub_users u ON t.user_id = u.id
                WHERE t.token_hash = ? 
                AND t.revoked = 0
                AND (t.expires_at > NOW() OR ?)
            ";
            error_log("SQL Query: " . $sql);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$token_hash, $allow_expired ? 1 : 0]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("Token found in jwt_tokens table for user " . $result['user_id']);
                
                // Update last used timestamp
                $stmt = $pdo->prepare("
                    UPDATE {$table_prefix}charterhub_jwt_tokens
                    SET last_used_at = NOW()
                    WHERE token_hash = ?
                ");
                $stmt->execute([$token_hash]);
                
                return [
                    'valid' => true,
                    'found_in_db' => true,
                    'user_id' => $result['user_id'],
                    'token_hash' => $token_hash
                ];
            } else {
                error_log("Token hash being checked: " . $token_hash);
                error_log("Query result: Token NOT found in database");
                error_log("Token not found in database or revoked");
            }
        } catch (Exception $e) {
            error_log("Error checking token in jwt_tokens table: " . $e->getMessage());
        }
        
        // If we get here, token wasn't found in jwt_tokens table
        // We need to extract the user ID from the token
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            try {
                $payload = json_decode(base64url_decode($parts[1]), true);
                if ($payload && isset($payload['sub'])) {
                    $user_id = $payload['sub'];
                    error_log("Extracted user ID from token: " . $user_id);
                    
                    // Check if this user has any active tokens first before creating a new one
                    $check_active_stmt = $pdo->prepare("
                        SELECT COUNT(*) as active_count 
                        FROM {$table_prefix}charterhub_jwt_tokens 
                        WHERE user_id = ? AND revoked = 0
                    ");
                    $check_active_stmt->execute([$user_id]);
                    $active_result = $check_active_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // If there are active tokens for this user but this token isn't one of them,
                    // it suggests this token was either revoked or is old/invalid
                    if ($active_result && $active_result['active_count'] > 0) {
                        error_log("User has active tokens but this specific token was not found. Likely stale or revoked.");
                        return [
                            'valid' => false,
                            'reason' => 'not_found_in_db',
                            'user_id' => $user_id,
                            'has_active_tokens' => true,
                            'token_hash' => $token_hash,
                            'decoded_payload' => $payload
                        ];
                    }
                    
                    // First check if token is already expired
                    $expires_at = isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : null;
                    if (!$allow_expired && $expires_at && strtotime($expires_at) < time()) {
                        error_log("Token is expired. Expiry: " . $expires_at . ", Current: " . date('Y-m-d H:i:s'));
                        return [
                            'valid' => false,
                            'reason' => 'expired',
                            'user_id' => $user_id,
                            'token_hash' => $token_hash
                        ];
                    }
                    
                    // ONLY insert the token if it's entirely new and there are no active tokens
                    // for this user (which would indicate a valid session elsewhere)
                    $stmt = $pdo->prepare("
                        INSERT INTO {$table_prefix}charterhub_jwt_tokens
                        (user_id, token_hash, expires_at, created_at, revoked, last_used_at)
                        VALUES (?, ?, ?, NOW(), 0, NOW())
                    ");
                    $stmt->execute([$user_id, $token_hash, $expires_at]);
                    
                    error_log("Token successfully inserted in jwt_tokens table as a new entry");
                    return [
                        'valid' => true,
                        'found_in_db' => false,
                        'registered' => true,
                        'user_id' => $user_id,
                        'token_hash' => $token_hash
                    ];
                }
            } catch (Exception $e) {
                error_log("Error extracting payload or registering token: " . $e->getMessage());
            }
        }
        
        // If we get here, the token could not be registered
        return [
            'valid' => false,
            'reason' => 'invalid_format',
            'token_hash' => $token_hash
        ];
        
    } catch (Exception $e) {
        error_log("Error verifying token in database: " . $e->getMessage());
        return [
            'valid' => false,
            'reason' => 'db_error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Revoke a JWT token
 * 
 * @param string $token The JWT token to revoke
 * @param string $reason Optional reason for revocation
 * @return bool True if the token was revoked successfully
 */
function revoke_jwt_token($token, $reason = 'manual_revocation') {
    global $db_config;
    
    // Check if db_config is available, and set default table prefix if not
    $table_prefix = isset($db_config) && isset($db_config['table_prefix']) ? $db_config['table_prefix'] : 'wp_';
    
    try {
        $pdo = get_db_connection();
        $transaction_started = false;
        
        // Fresh connection - start explicit transaction for consistency
        try {
            $pdo->beginTransaction();
            $transaction_started = true;
            error_log("revoke_jwt_token: Transaction started");
        } catch (Exception $txEx) {
            error_log("revoke_jwt_token: Could not start transaction: " . $txEx->getMessage());
            // Continue without transaction if it fails
        }
        
        // Check if required columns exist
        try {
            $column_check = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens LIKE 'revoked_at'");
            $revoked_at_exists = $column_check->rowCount() > 0;
            
            if (!$revoked_at_exists) {
                error_log("revoke_jwt_token: Adding missing revoked_at column");
                $pdo->exec("ALTER TABLE {$table_prefix}charterhub_jwt_tokens ADD COLUMN revoked_at DATETIME NULL");
            }
            
            $column_check = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens LIKE 'revoked_reason'");
            $revoked_reason_exists = $column_check->rowCount() > 0;
            
            if (!$revoked_reason_exists) {
                error_log("revoke_jwt_token: Adding missing revoked_reason column");
                $pdo->exec("ALTER TABLE {$table_prefix}charterhub_jwt_tokens ADD COLUMN revoked_reason VARCHAR(255) NULL");
            }
            
            // Check for a notes column for additional information
            $column_check = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens LIKE 'notes'");
            $notes_exists = $column_check->rowCount() > 0;
            
            if (!$notes_exists) {
                error_log("revoke_jwt_token: Adding missing notes column");
                $pdo->exec("ALTER TABLE {$table_prefix}charterhub_jwt_tokens ADD COLUMN notes TEXT NULL");
            }
        } catch (Exception $columnEx) {
            // Log but continue - even if columns don't exist, basic revocation can work
            error_log("revoke_jwt_token: Could not check/add required columns: " . $columnEx->getMessage());
        }
        
        // Token hash
        $token_hash = hash('sha256', $token);
        
        // Get token info for logging
        $token_info = null;
        $token_user_id = null;
        $token_payload = null;
        
        try {
            $info_stmt = $pdo->prepare("
                SELECT user_id, created_at 
                FROM {$table_prefix}charterhub_jwt_tokens
                WHERE token_hash = ?
            ");
            $info_stmt->execute([$token_hash]);
            $token_info = $info_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($token_info) {
                $token_user_id = $token_info['user_id'];
                error_log("revoke_jwt_token: Revoking token for user ID {$token_info['user_id']}, created at {$token_info['created_at']}");
            } else {
                error_log("revoke_jwt_token: Token not found in database. Hash: " . substr($token_hash, 0, 10) . "...");
                
                // Try to extract user ID from token for additional logging
                try {
                    $parts = explode('.', $token);
                    if (count($parts) === 3) {
                        $payload_base64 = $parts[1];
                        $payload_decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload_base64));
                        $token_payload = json_decode($payload_decoded, true);
                        
                        if (isset($token_payload['sub'])) {
                            $token_user_id = $token_payload['sub'];
                            error_log("revoke_jwt_token: Token belongs to user ID {$token_payload['sub']} but not found in database");
                            
                            // Try to register the token in the database first so we can properly revoke it
                            if ($token_payload['exp'] > time()) {
                                try {
                                    $register_stmt = $pdo->prepare("
                                        INSERT INTO {$table_prefix}charterhub_jwt_tokens
                                        (user_id, token_hash, expires_at, created_at, notes)
                                        VALUES (?, ?, ?, NOW(), ?)
                                        ON DUPLICATE KEY UPDATE notes = CONCAT(IFNULL(notes, ''), '\nToken registered during revocation')
                                    ");
                                    $register_stmt->execute([
                                        $token_user_id, 
                                        $token_hash, 
                                        date('Y-m-d H:i:s', $token_payload['exp']),
                                        "Token registered during revocation attempt. " . 
                                        (isset($token_payload['email']) ? "Email in token: " . $token_payload['email'] : "No email in token")
                                    ]);
                                    
                                    error_log("revoke_jwt_token: Token registered in database for revocation tracking");
                                } catch (Exception $regEx) {
                                    error_log("revoke_jwt_token: Could not register token: " . $regEx->getMessage());
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Ignore errors in token parsing
                    error_log("revoke_jwt_token: Could not parse token: " . $e->getMessage());
                }
            }
        } catch (Exception $infoEx) {
            // Non-critical, just for logging
            error_log("revoke_jwt_token: Could not get token info: " . $infoEx->getMessage());
        }
        
        // Revoke token with enhanced information
        try {
            $stmt = $pdo->prepare("
                UPDATE {$table_prefix}charterhub_jwt_tokens
                SET revoked = 1,
                    revoked_at = NOW(),
                    revoked_reason = ?,
                    last_used_at = NOW(),
                    notes = CONCAT(IFNULL(notes, ''), '\nRevoked at ', NOW(), ' for reason: ', ?)
                WHERE token_hash = ?
            ");
            
            // Use the reason both as the formal reason and in the notes
            $stmt->execute([$reason, $reason, $token_hash]);
            
            $rows_affected = $stmt->rowCount();
            error_log("revoke_jwt_token: Revoked token with hash " . substr($token_hash, 0, 10) . "... Rows affected: " . $rows_affected);
            
            // If we have user ID but token wasn't found, or if update didn't affect any rows
            if (($token_user_id && !$token_info) || $rows_affected === 0) {
                // Try again with user_id if token_hash doesn't match but we know the user
                try {
                    if ($token_user_id) {
                        // Look for any tokens with this hash (exact match may have failed)
                        $fuzzy_stmt = $pdo->prepare("
                            SELECT token_hash FROM {$table_prefix}charterhub_jwt_tokens
                            WHERE token_hash LIKE ? AND user_id = ? AND revoked = 0
                            LIMIT 5
                        ");
                        
                        // Try with first few characters of token hash
                        $fuzzy_stmt->execute([substr($token_hash, 0, 10) . '%', $token_user_id]);
                        $fuzzy_results = $fuzzy_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($fuzzy_results)) {
                            error_log("revoke_jwt_token: Found " . count($fuzzy_results) . " similar tokens for user ID {$token_user_id}");
                            
                            // Revoke all similar tokens
                            foreach ($fuzzy_results as $fuzzy_token) {
                                $fuzzy_hash = $fuzzy_token['token_hash'];
                                try {
                                    $fuzzy_revoke = $pdo->prepare("
                                        UPDATE {$table_prefix}charterhub_jwt_tokens
                                        SET revoked = 1,
                                            revoked_at = NOW(),
                                            revoked_reason = ?,
                                            notes = CONCAT(IFNULL(notes, ''), '\nRevoked as similar token during fuzzy match for hash: {$token_hash}')
                                        WHERE token_hash = ?
                                    ");
                                    $fuzzy_revoke->execute([$reason . ' (fuzzy match)', $fuzzy_hash]);
                                    error_log("revoke_jwt_token: Revoked similar token with hash: " . substr($fuzzy_hash, 0, 10) . "...");
                                } catch (Exception $fuzzyEx) {
                                    error_log("revoke_jwt_token: Error revoking similar token: " . $fuzzyEx->getMessage());
                                }
                            }
                        }
                    }
                } catch (Exception $fuzzyEx) {
                    error_log("revoke_jwt_token: Error during fuzzy token search: " . $fuzzyEx->getMessage());
                }
            }

            if ($transaction_started) {
                $pdo->commit();
                error_log("revoke_jwt_token: Transaction committed");
            }
            
            return $rows_affected > 0;
        } catch (Exception $updateEx) {
            // If the enhanced update fails, try a simpler one
            error_log("revoke_jwt_token: Enhanced revocation failed, trying basic revocation: " . $updateEx->getMessage());
            
            if ($transaction_started) {
                try {
                    $pdo->rollBack();
                    error_log("revoke_jwt_token: Transaction rolled back");
                } catch (Exception $rollbackEx) {
                    error_log("revoke_jwt_token: Could not rollback transaction: " . $rollbackEx->getMessage());
                }
            }
            
            try {
                $basic_stmt = $pdo->prepare("
                    UPDATE {$table_prefix}charterhub_jwt_tokens
                    SET revoked = 1
                    WHERE token_hash = ?
                ");
                $basic_stmt->execute([$token_hash]);
                
                $basic_rows = $basic_stmt->rowCount();
                error_log("revoke_jwt_token: Basic revocation completed. Rows affected: " . $basic_rows);
                
                return $basic_rows > 0;
            } catch (Exception $basicEx) {
                error_log("revoke_jwt_token: Basic revocation also failed: " . $basicEx->getMessage());
                return false;
            }
        }
    } catch (Exception $e) {
        error_log("revoke_jwt_token: Error revoking JWT token: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke all JWT tokens for a user
 * 
 * @param int $user_id The user ID
 * @param string $reason Optional reason for revocation
 * @return int Number of tokens revoked
 */
function revoke_all_user_tokens($user_id, $reason = 'user_logout') {
    global $db_config;
    
    // Check if db_config is available, and set default table prefix if not
    $table_prefix = isset($db_config) && isset($db_config['table_prefix']) ? $db_config['table_prefix'] : 'wp_';
    
    try {
        $pdo = get_db_connection();
        $transaction_started = false;
        
        // Start transaction to ensure database consistency
        try {
            $pdo->beginTransaction();
            $transaction_started = true;
            error_log("revoke_all_user_tokens: Transaction started for user ID {$user_id}");
        } catch (Exception $txEx) {
            error_log("revoke_all_user_tokens: Could not start transaction: " . $txEx->getMessage());
            // Continue without transaction if it fails
        }
        
        // Check if required columns exist
        try {
            $column_check = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens LIKE 'revoked_at'");
            $revoked_at_exists = $column_check->rowCount() > 0;
            
            if (!$revoked_at_exists) {
                error_log("revoke_all_user_tokens: Adding missing revoked_at column");
                $pdo->exec("ALTER TABLE {$table_prefix}charterhub_jwt_tokens ADD COLUMN revoked_at DATETIME NULL");
            }
            
            $column_check = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens LIKE 'revoked_reason'");
            $revoked_reason_exists = $column_check->rowCount() > 0;
            
            if (!$revoked_reason_exists) {
                error_log("revoke_all_user_tokens: Adding missing revoked_reason column");
                $pdo->exec("ALTER TABLE {$table_prefix}charterhub_jwt_tokens ADD COLUMN revoked_reason VARCHAR(255) NULL");
            }
            
            // Check for a notes column for additional information
            $column_check = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens LIKE 'notes'");
            $notes_exists = $column_check->rowCount() > 0;
            
            if (!$notes_exists) {
                error_log("revoke_all_user_tokens: Adding missing notes column");
                $pdo->exec("ALTER TABLE {$table_prefix}charterhub_jwt_tokens ADD COLUMN notes TEXT NULL");
            }
        } catch (Exception $columnEx) {
            // Log but continue - even if columns don't exist, basic revocation can work
            error_log("revoke_all_user_tokens: Could not check/add required columns: " . $columnEx->getMessage());
        }
        
        // Get count of active tokens for logging
        $active_token_count = 0;
        try {
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) as token_count
                FROM {$table_prefix}charterhub_jwt_tokens
                WHERE user_id = ? AND revoked = 0
            ");
            $count_stmt->execute([$user_id]);
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($count_result) {
                $active_token_count = $count_result['token_count'];
                error_log("revoke_all_user_tokens: Found {$active_token_count} active tokens for user ID {$user_id}");
            }
        } catch (Exception $countEx) {
            // Non-critical, just for logging
            error_log("revoke_all_user_tokens: Could not get token count: " . $countEx->getMessage());
        }
        
        // If no active tokens, skip the update
        if ($active_token_count == 0) {
            error_log("revoke_all_user_tokens: No active tokens to revoke for user ID {$user_id}");
            
            if ($transaction_started) {
                try {
                    $pdo->commit();
                    error_log("revoke_all_user_tokens: Empty transaction committed");
                } catch (Exception $commitEx) {
                    error_log("revoke_all_user_tokens: Could not commit empty transaction: " . $commitEx->getMessage());
                }
            }
            
            return 0;
        }
        
        // Revoke all tokens with enhanced information
        try {
            // Get email for the user to include in notes
            $user_email = null;
            try {
                $email_stmt = $pdo->prepare("
                    SELECT email FROM {$table_prefix}charterhub_users
                    WHERE id = ?
                ");
                $email_stmt->execute([$user_id]);
                $user_email = $email_stmt->fetchColumn();
            } catch (Exception $emailEx) {
                error_log("revoke_all_user_tokens: Could not get user email: " . $emailEx->getMessage());
            }
            
            $stmt = $pdo->prepare("
                UPDATE {$table_prefix}charterhub_jwt_tokens
                SET revoked = 1,
                    revoked_at = NOW(),
                    revoked_reason = ?,
                    notes = CONCAT(IFNULL(notes, ''), '\nRevoked at ', NOW(), ' for reason: ', ?, ' User email: ', ?)
                WHERE user_id = ? AND revoked = 0
            ");
            $stmt->execute([$reason, $reason, $user_email ?: 'unknown', $user_id]);
            
            $rows_affected = $stmt->rowCount();
            error_log("revoke_all_user_tokens: Revoked {$rows_affected} tokens for user ID {$user_id}");
            
            if ($transaction_started) {
                $pdo->commit();
                error_log("revoke_all_user_tokens: Transaction committed successfully");
            }
            
            return $rows_affected;
        } catch (Exception $updateEx) {
            // If the enhanced update fails, try a simpler one
            error_log("revoke_all_user_tokens: Enhanced revocation failed, trying basic revocation: " . $updateEx->getMessage());
            
            if ($transaction_started) {
                try {
                    $pdo->rollBack();
                    error_log("revoke_all_user_tokens: Transaction rolled back");
                } catch (Exception $rollbackEx) {
                    error_log("revoke_all_user_tokens: Could not rollback transaction: " . $rollbackEx->getMessage());
                }
            }
            
            try {
                $basic_stmt = $pdo->prepare("
                    UPDATE {$table_prefix}charterhub_jwt_tokens
                    SET revoked = 1
                    WHERE user_id = ? AND revoked = 0
                ");
                $basic_stmt->execute([$user_id]);
                
                $basic_rows = $basic_stmt->rowCount();
                error_log("revoke_all_user_tokens: Basic revocation completed. Rows affected: " . $basic_rows);
                
                return $basic_rows;
            } catch (Exception $basicEx) {
                error_log("revoke_all_user_tokens: Basic revocation also failed: " . $basicEx->getMessage());
                return 0;
            }
        }
    } catch (Exception $e) {
        error_log("revoke_all_user_tokens: Error revoking user tokens: " . $e->getMessage());
        return 0;
    }
} 