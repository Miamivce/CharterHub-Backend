<?php
/**
 * CharterHub JWT Token Cleanup Script
 * 
 * This script automatically cleans up expired and revoked JWT tokens
 * from the database to improve performance and reduce database size.
 * 
 * It can be run manually or set up as a cron job to run periodically.
 */

// Define the CHARTERHUB_LOADED constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Include required files
require_once __DIR__ . '/config.php';

// Basic authentication for non-CLI access
$is_cli = (php_sapi_name() === 'cli');
$allowed_ips = [
    '127.0.0.1',
    '::1', // localhost IPv6
];

if (!$is_cli && !in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied. Run this script from the command line or locally.';
    exit;
}

// Get database connection
function get_pdo_connection() {
    global $db_config;
    
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['database']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    try {
        return new PDO($dsn, $db_config['username'], $db_config['password'], $options);
    } catch (PDOException $e) {
        exit("Database connection failed: " . $e->getMessage());
    }
}

// Get token table prefix
$table_prefix = $GLOBALS['db_config']['table_prefix'] ?? 'wp_';

// Connect to database
$pdo = get_pdo_connection();

// Start cleanup process
$output = [
    'timestamp' => date('Y-m-d H:i:s'),
    'stats' => [],
    'messages' => []
];

// Get count before cleanup
$before_count = $pdo->query("SELECT COUNT(*) FROM {$table_prefix}charterhub_jwt_tokens")->fetchColumn();
$output['stats']['total_tokens_before'] = $before_count;

// Delete expired tokens older than 7 days
$expired_stmt = $pdo->prepare("
    DELETE FROM {$table_prefix}charterhub_jwt_tokens 
    WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND revoked = 0
");
$expired_stmt->execute();
$expired_deleted = $expired_stmt->rowCount();
$output['stats']['expired_tokens_deleted'] = $expired_deleted;
$output['messages'][] = "Deleted {$expired_deleted} expired tokens older than 7 days";

// Delete revoked tokens older than 30 days
$revoked_stmt = $pdo->prepare("
    DELETE FROM {$table_prefix}charterhub_jwt_tokens 
    WHERE revoked = 1 
    AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$revoked_stmt->execute();
$revoked_deleted = $revoked_stmt->rowCount();
$output['stats']['revoked_tokens_deleted'] = $revoked_deleted;
$output['messages'][] = "Deleted {$revoked_deleted} revoked tokens older than 30 days";

// Delete duplicate tokens for same user, keeping the most recent
$duplicates_query = "
    DELETE t1 FROM {$table_prefix}charterhub_jwt_tokens t1
    INNER JOIN {$table_prefix}charterhub_jwt_tokens t2
    WHERE t1.user_id = t2.user_id
    AND t1.id < t2.id
    AND t1.revoked = 0
    AND t2.revoked = 0
    AND t1.expires_at > NOW()
    AND t2.expires_at > NOW()
    AND t1.created_at < t2.created_at
";
$duplicate_stmt = $pdo->prepare($duplicates_query);
$duplicate_stmt->execute();
$duplicate_deleted = $duplicate_stmt->rowCount();
$output['stats']['duplicate_tokens_deleted'] = $duplicate_deleted;
$output['messages'][] = "Deleted {$duplicate_deleted} duplicate active tokens";

// Mark recently expired tokens as revoked
$mark_revoked_stmt = $pdo->prepare("
    UPDATE {$table_prefix}charterhub_jwt_tokens 
    SET revoked = 1
    WHERE expires_at < NOW()
    AND expires_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    AND revoked = 0
");
$mark_revoked_stmt->execute();
$marked_revoked = $mark_revoked_stmt->rowCount();
$output['stats']['tokens_marked_revoked'] = $marked_revoked;
$output['messages'][] = "Marked {$marked_revoked} recently expired tokens as revoked";

// Optimize table if supported
try {
    $pdo->exec("OPTIMIZE TABLE {$table_prefix}charterhub_jwt_tokens");
    $output['messages'][] = "Optimized token table";
} catch (Exception $e) {
    $output['messages'][] = "Note: Table optimization skipped - " . $e->getMessage();
}

// Get count after cleanup
$after_count = $pdo->query("SELECT COUNT(*) FROM {$table_prefix}charterhub_jwt_tokens")->fetchColumn();
$output['stats']['total_tokens_after'] = $after_count;
$output['stats']['total_removed'] = $before_count - $after_count;
$output['stats']['percent_reduction'] = $before_count > 0 ? round((($before_count - $after_count) / $before_count) * 100, 2) . '%' : '0%';

// Print results
if ($is_cli) {
    echo "=== JWT Token Cleanup ===\n";
    echo "Timestamp: {$output['timestamp']}\n\n";
    
    echo "Statistics:\n";
    foreach ($output['stats'] as $key => $value) {
        echo "- " . ucwords(str_replace('_', ' ', $key)) . ": {$value}\n";
    }
    
    echo "\nMessages:\n";
    foreach ($output['messages'] as $message) {
        echo "- {$message}\n";
    }
} else {
    // Output as JSON
    header('Content-Type: application/json');
    echo json_encode($output, JSON_PRETTY_PRINT);
} 