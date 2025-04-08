<?php
// Fix for database.php to respect SSL settings from .env
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Utils Fix</h1>";

// File to modify
$db_file = __DIR__ . '/utils/database.php';
if (!file_exists($db_file)) {
    echo "<p style='color:red'>❌ Database utils file not found at: $db_file</p>";
    exit;
}

echo "<p>Found database utils file at: $db_file</p>";

// Read the current content
$original_content = file_get_contents($db_file);
$backup_content = $original_content;

// Make a backup
$backup_file = $db_file . '.bak';
file_put_contents($backup_file, $backup_content);
echo "<p>Created backup at: $backup_file</p>";

// Find and modify the getDbConnection function
echo "<h2>Modifying database connection code</h2>";

// Pattern to find (this is what needs updating)
$patterns = [
    // Pattern 1: Look for hardcoded SSL options
    '/(PDO::MYSQL_ATTR_SSL_CA)\s*=>\s*true/',
    
    // Pattern 2: Look for SSL mode check
    '/if\s*\(\$db_config\[\'ssl_mode\'\]\s*===\s*\'REQUIRED\'\)\s*\{/',
];

$replacements = [
    // Replacement 1: Make SSL conditional on environment variable
    '(getenv(\'DB_SSL\') !== \'DISABLED\' && getenv(\'DB_SSL_MODE\') !== \'DISABLED\') ? PDO::MYSQL_ATTR_SSL_CA : null',
    
    // Replacement 2: Make SSL check respect environment
    'if (getenv(\'DB_SSL\') !== \'DISABLED\' && getenv(\'DB_SSL_MODE\') !== \'DISABLED\' && $db_config[\'ssl_mode\'] === \'REQUIRED\') {',
];

// Apply modifications
$modified_content = $original_content;
$changes_made = 0;

foreach ($patterns as $index => $pattern) {
    $count = 0;
    $modified_content = preg_replace($pattern, $replacements[$index], $modified_content, -1, $count);
    $changes_made += $count;
}

if ($changes_made > 0) {
    // Write the modified content back to the file
    if (file_put_contents($db_file, $modified_content)) {
        echo "<p style='color:green'>✅ Successfully updated database utils file with $changes_made changes</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to update file</p>";
    }
} else {
    echo "<p style='color:orange'>⚠️ No changes needed in the file (pattern not found)</p>";
    
    // In this case, we'll try a direct addition to the connection code
    $connection_pattern = '/function\s+getDbConnection\(\)\s*\{/';
    $connection_replacement = "function getDbConnection() {\n    // Read SSL mode from environment
    \$use_ssl = (getenv('DB_SSL') !== 'DISABLED' && getenv('DB_SSL_MODE') !== 'DISABLED');
    error_log('Database SSL mode: ' . (\$use_ssl ? 'ENABLED' : 'DISABLED'));";
    
    $modified_content = preg_replace($connection_pattern, $connection_replacement, $original_content, -1, $count);
    
    if ($count > 0) {
        if (file_put_contents($db_file, $modified_content)) {
            echo "<p style='color:green'>✅ Added SSL environment check to database connection function</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to update file</p>";
        }
    } else {
        echo "<p style='color:red'>❌ Could not find database connection function</p>";
        
        // Last resort: Create alternative database function
        $alt_file = __DIR__ . '/utils/fixed-database.php';
        $alt_content = <<<'EOD'
<?php
/**
 * Fixed Database Utility Functions
 * SSL disabled by default
 */

// Include original database file
require_once __DIR__ . '/database.php';

/**
 * Alternative database connection function with SSL disabled
 */
function getFixedDbConnection() {
    static $connection = null;
    
    if ($connection === null) {
        try {
            error_log("Creating new database connection with SSL DISABLED");
            
            // Get database config
            global $db_config;
            if (!isset($db_config)) {
                // Fallback to hardcoded values
                $db_config = [
                    'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
                    'port' => getenv('DB_PORT') ?: '19174',
                    'dbname' => getenv('DB_NAME') ?: 'defaultdb',
                    'username' => getenv('DB_USER') ?: 'avnadmin',
                    'password' => getenv('DB_PASSWORD') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C',
                    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4'
                ];
            }
            
            // Create DSN string WITHOUT SSL requirements
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $db_config['host'],
