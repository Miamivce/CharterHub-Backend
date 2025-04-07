<?php
// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include shared config
require_once __DIR__ . '/../auth/config.php';

// Connect to the database
try {
    $pdo = get_db_connection();
    echo "Connected to database successfully\n";
    
    // Debug database configuration
    echo "Database configuration: " . json_encode([
        'host' => $db_config['host'],
        'port' => $db_config['port'] ?? 'default',
        'name' => $db_config['name'] ?? $db_config['dbname'] ?? 'unknown',
        'table_prefix' => $db_config['table_prefix']
    ]) . "\n";
    
    // Check if the table already exists
    $stmt = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}charterhub_jwt_tokens'");
    if ($stmt && $stmt->rowCount() > 0) {
        echo "Table {$db_config['table_prefix']}charterhub_jwt_tokens already exists\n";
        
        // Drop the table to recreate it
        $pdo->exec("DROP TABLE {$db_config['table_prefix']}charterhub_jwt_tokens");
        echo "Dropped existing table to recreate it\n";
    }
    
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Create table
try {
    $sql = "CREATE TABLE {$db_config['table_prefix']}charterhub_jwt_tokens (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) UNSIGNED NOT NULL,
        `token_hash` varchar(255) NOT NULL,
        `refresh_token_hash` varchar(255) DEFAULT NULL,
        `expires_at` datetime NOT NULL,
        `refresh_expires_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `revoked` tinyint(1) NOT NULL DEFAULT 0,
        `last_used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `token_hash` (`token_hash`),
        KEY `refresh_token_hash` (`refresh_token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    echo "Successfully created {$db_config['table_prefix']}charterhub_jwt_tokens table\n";
    
    // Check if the old table exists
    $stmt = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}jwt_tokens'");
    if ($stmt && $stmt->rowCount() > 0) {
        // Copy tokens from old table
        $sql = "INSERT IGNORE INTO {$db_config['table_prefix']}charterhub_jwt_tokens 
            (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at)
            SELECT user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, 
                   COALESCE(created_at, NOW()), revoked, last_used_at
            FROM {$db_config['table_prefix']}jwt_tokens
            WHERE revoked = 0";
        
        $pdo->exec($sql);
        echo "Successfully copied tokens from old table\n";
    } else {
        echo "Old token table does not exist, no tokens to copy\n";
    }
    
    // List all tables to verify
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "All tables in database: " . implode(", ", $tables) . "\n";
    
} catch (PDOException $e) {
    echo "Error creating/updating table: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Token table setup complete\n"; 