<?php
/**
 * CharterHub JWT Token Diagnostics Script
 * 
 * This script provides diagnostic information about JWT tokens in the database
 * and helps identify issues with token storage and management.
 * 
 * IMPORTANT: This script should be removed or secured in production environments.
 */

// Define the CHARTERHUB_LOADED constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt-helper.php';
require_once __DIR__ . '/token-storage.php';

// CSRF protection
$allowed_ips = [
    '127.0.0.1',
    '::1', // localhost IPv6
];

if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied. This tool is only available locally.';
    exit;
}

// Get database connection
$pdo = get_db_connection();

// Get token table prefix
$table_prefix = $GLOBALS['db_config']['table_prefix'] ?? 'wp_';

// Initialize output
$output = [
    'title' => 'JWT Token Diagnostics',
    'timestamp' => date('Y-m-d H:i:s'),
    'summary' => [],
    'details' => [],
    'recommendations' => []
];

// Get token statistics
$total_tokens = $pdo->query("SELECT COUNT(*) FROM {$table_prefix}charterhub_jwt_tokens")->fetchColumn();
$active_tokens = $pdo->query("
    SELECT COUNT(*) FROM {$table_prefix}charterhub_jwt_tokens 
    WHERE revoked = 0 AND expires_at > NOW()
")->fetchColumn();
$expired_tokens = $pdo->query("
    SELECT COUNT(*) FROM {$table_prefix}charterhub_jwt_tokens 
    WHERE revoked = 0 AND expires_at <= NOW()
")->fetchColumn();
$revoked_tokens = $pdo->query("
    SELECT COUNT(*) FROM {$table_prefix}charterhub_jwt_tokens 
    WHERE revoked = 1
")->fetchColumn();

// Get user statistics
$total_users = $pdo->query("SELECT COUNT(*) FROM {$table_prefix}charterhub_users")->fetchColumn();
$users_with_tokens = $pdo->query("
    SELECT COUNT(DISTINCT user_id) FROM {$table_prefix}charterhub_jwt_tokens 
    WHERE revoked = 0 AND expires_at > NOW()
")->fetchColumn();
$users_without_tokens = $total_users - $users_with_tokens;

// Add summary information
$output['summary'] = [
    'total_tokens' => $total_tokens,
    'active_tokens' => $active_tokens,
    'expired_tokens' => $expired_tokens,
    'revoked_tokens' => $revoked_tokens,
    'total_users' => $total_users,
    'users_with_tokens' => $users_with_tokens,
    'users_without_tokens' => $users_without_tokens,
    'token_coverage' => $total_users > 0 ? round(($users_with_tokens / $total_users) * 100, 2) . '%' : 'N/A'
];

// Check for users without tokens
if ($users_without_tokens > 0) {
    $users_without_tokens_data = $pdo->query("
        SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.last_login
        FROM {$table_prefix}charterhub_users u
        LEFT JOIN (
            SELECT DISTINCT user_id 
            FROM {$table_prefix}charterhub_jwt_tokens 
            WHERE revoked = 0 AND expires_at > NOW()
        ) t ON u.id = t.user_id
        WHERE t.user_id IS NULL
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $output['details']['users_without_tokens'] = $users_without_tokens_data;
}

// Get recent token activity
$recent_tokens = $pdo->query("
    SELECT 
        t.user_id, 
        u.email,
        t.created_at, 
        t.expires_at, 
        t.last_used_at,
        t.revoked
    FROM {$table_prefix}charterhub_jwt_tokens t
    JOIN {$table_prefix}charterhub_users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$output['details']['recent_tokens'] = $recent_tokens;

// Find users with multiple active tokens (potential issue)
$multiple_tokens_users = $pdo->query("
    SELECT 
        t.user_id, 
        u.email,
        COUNT(*) as token_count
    FROM {$table_prefix}charterhub_jwt_tokens t
    JOIN {$table_prefix}charterhub_users u ON t.user_id = u.id
    WHERE t.revoked = 0 AND t.expires_at > NOW()
    GROUP BY t.user_id
    HAVING COUNT(*) > 1
    ORDER BY COUNT(*) DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if (count($multiple_tokens_users) > 0) {
    $output['details']['users_with_multiple_tokens'] = $multiple_tokens_users;
}

// Get table info
$table_columns = $pdo->query("SHOW COLUMNS FROM {$table_prefix}charterhub_jwt_tokens")->fetchAll(PDO::FETCH_ASSOC);
$column_names = array_column($table_columns, 'Field');
$output['details']['token_table_columns'] = $column_names;

// Verify token table has indexes
$table_indexes = $pdo->query("SHOW INDEX FROM {$table_prefix}charterhub_jwt_tokens")->fetchAll(PDO::FETCH_ASSOC);
$indexed_columns = array_column($table_indexes, 'Column_name');
$output['details']['token_table_indexes'] = $indexed_columns;

// Check for recommended indexes
$recommended_indexes = ['user_id', 'token_hash', 'refresh_token_hash', 'expires_at', 'revoked'];
$missing_indexes = array_diff($recommended_indexes, $indexed_columns);

if (count($missing_indexes) > 0) {
    $output['recommendations'][] = [
        'issue' => 'Missing database indexes',
        'description' => 'The following columns should be indexed for better performance: ' . implode(', ', $missing_indexes),
        'sql' => "-- Create missing indexes on {$table_prefix}charterhub_jwt_tokens\n" . 
                implode(";\n", array_map(function($col) use ($table_prefix) {
                    return "CREATE INDEX idx_{$col} ON {$table_prefix}charterhub_jwt_tokens ({$col})";
                }, $missing_indexes))
    ];
}

// Check for expired tokens that need cleanup
if ($expired_tokens > 100) {
    $output['recommendations'][] = [
        'issue' => 'Large number of expired tokens',
        'description' => "Found {$expired_tokens} expired tokens in the database. Consider cleaning them up to improve performance.",
        'sql' => "-- Delete expired tokens\nDELETE FROM {$table_prefix}charterhub_jwt_tokens WHERE expires_at < NOW() AND revoked = 0;"
    ];
}

// Check for revoked tokens that need cleanup
if ($revoked_tokens > 100) {
    $output['recommendations'][] = [
        'issue' => 'Large number of revoked tokens',
        'description' => "Found {$revoked_tokens} revoked tokens in the database. Consider cleaning them up to improve performance.",
        'sql' => "-- Delete revoked tokens older than 30 days\nDELETE FROM {$table_prefix}charterhub_jwt_tokens WHERE revoked = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);"
    ];
}

// Output as JSON or HTML based on request
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($output, JSON_PRETTY_PRINT);
    exit;
}

// HTML output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JWT Token Diagnostics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; max-width: 1200px; margin: 0 auto; padding: 20px; }
        h1, h2, h3 { color: #2c3e50; }
        .summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #f8f9fa; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-value { font-size: 24px; font-weight: bold; color: #3498db; }
        .stat-label { font-size: 14px; color: #7f8c8d; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        tr:hover { background-color: #f5f5f5; }
        .recommendation { background: #fffbea; border-left: 4px solid #f1c40f; padding: 15px; margin-bottom: 15px; border-radius: 4px; }
        .sql-code { background: #f8f8f8; padding: 10px; font-family: monospace; border-radius: 4px; overflow-x: auto; }
        .button { display: inline-block; padding: 8px 16px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <h1><?php echo $output['title']; ?></h1>
    <p>Generated on <?php echo $output['timestamp']; ?></p>
    
    <h2>Summary</h2>
    <div class="summary">
        <?php foreach ($output['summary'] as $label => $value): ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $value; ?></div>
                <div class="stat-label"><?php echo ucwords(str_replace('_', ' ', $label)); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <h2>Recent Token Activity</h2>
    <table>
        <thead>
            <tr>
                <th>User ID</th>
                <th>Email</th>
                <th>Created</th>
                <th>Expires</th>
                <th>Last Used</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($output['details']['recent_tokens'] as $token): ?>
                <tr>
                    <td><?php echo $token['user_id']; ?></td>
                    <td><?php echo $token['email']; ?></td>
                    <td><?php echo $token['created_at']; ?></td>
                    <td><?php echo $token['expires_at']; ?></td>
                    <td><?php echo $token['last_used_at']; ?></td>
                    <td><?php echo $token['revoked'] ? 'Revoked' : (strtotime($token['expires_at']) < time() ? 'Expired' : 'Active'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if (isset($output['details']['users_with_multiple_tokens'])): ?>
    <h2>Users with Multiple Active Tokens</h2>
    <table>
        <thead>
            <tr>
                <th>User ID</th>
                <th>Email</th>
                <th>Token Count</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($output['details']['users_with_multiple_tokens'] as $user): ?>
                <tr>
                    <td><?php echo $user['user_id']; ?></td>
                    <td><?php echo $user['email']; ?></td>
                    <td><?php echo $user['token_count']; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    
    <?php if (isset($output['details']['users_without_tokens'])): ?>
    <h2>Sample Users Without Active Tokens</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Name</th>
                <th>Role</th>
                <th>Last Login</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($output['details']['users_without_tokens'] as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo $user['email']; ?></td>
                    <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                    <td><?php echo $user['role']; ?></td>
                    <td><?php echo $user['last_login'] ?? 'Never'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    
    <h2>Database Structure</h2>
    <p><strong>Token Table Columns:</strong> <?php echo implode(', ', $output['details']['token_table_columns']); ?></p>
    <p><strong>Indexed Columns:</strong> <?php echo implode(', ', $output['details']['token_table_indexes']); ?></p>
    
    <?php if (!empty($output['recommendations'])): ?>
    <h2>Recommendations</h2>
    <?php foreach ($output['recommendations'] as $rec): ?>
        <div class="recommendation">
            <h3><?php echo $rec['issue']; ?></h3>
            <p><?php echo $rec['description']; ?></p>
            <?php if (isset($rec['sql'])): ?>
                <div class="sql-code"><?php echo nl2br(htmlspecialchars($rec['sql'])); ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
    
    <p>
        <a href="?format=json" class="button">View as JSON</a>
    </p>
</body>
</html> 