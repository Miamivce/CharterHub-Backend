<?php
// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include shared config
require_once 'config.php';

header('Content-Type: application/json');

// Connect to the database
try {
    $pdo = get_db_connection();
    echo json_encode(['success' => true, 'message' => 'Connected to database']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Check wp_charterhub_jwt_tokens table
$response = ['success' => true, 'actions' => []];

// Check if the charterhub_jwt_tokens table exists
$stmt = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}charterhub_jwt_tokens'");
$table_exists = ($stmt && $stmt->rowCount() > 0);

if (!$table_exists) {
    // Create the table if it doesn't exist
    $sql = "CREATE TABLE `{$db_config['table_prefix']}charterhub_jwt_tokens` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) UNSIGNED NOT NULL,
        `token_hash` varchar(255) NOT NULL,
        `refresh_token_hash` varchar(255) DEFAULT NULL,
        `expires_at` datetime NOT NULL,
        `refresh_expires_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `revoked` tinyint(1) NOT NULL DEFAULT '0',
        `last_used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `token_hash` (`token_hash`),
        KEY `refresh_token_hash` (`refresh_token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    try {
        $pdo->exec($sql);
        $response['actions'][] = "Created the charterhub_jwt_tokens table";
    } catch (PDOException $e) {
        $response['success'] = false;
        $response['error'] = "Failed to create charterhub_jwt_tokens table: " . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

// Copy any tokens from wp_jwt_tokens to wp_charterhub_jwt_tokens
$stmt = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}jwt_tokens'");
$old_table_exists = ($stmt && $stmt->rowCount() > 0);

if ($old_table_exists) {
    try {
        // Check if there are any tokens to copy
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$db_config['table_prefix']}jwt_tokens WHERE revoked = 0");
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            // Copy active tokens
            $sql = "INSERT IGNORE INTO {$db_config['table_prefix']}charterhub_jwt_tokens 
                    (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at)
                    SELECT user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at
                    FROM {$db_config['table_prefix']}jwt_tokens
                    WHERE revoked = 0";
            $pdo->exec($sql);
            $response['actions'][] = "Copied $count active tokens from jwt_tokens to charterhub_jwt_tokens";
        } else {
            $response['actions'][] = "No active tokens to copy from jwt_tokens";
        }
    } catch (PDOException $e) {
        $response['success'] = false;
        $response['error'] = "Failed to copy tokens: " . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

// Return success response
echo json_encode($response); 