<?php
// Simple fix to disable SSL in database connection
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database SSL Fix</h1>";

// Create a new file with a fixed database connection function
$fixed_db_file = __DIR__ . '/utils/db-fixed.php';

// The content of our fixed database connection file
$content = <<<'EOD'
<?php
/**
 * Fixed database connection without SSL
 * This file is automatically included by the login script
 */

// Define the constant to prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

/**
 * Get a database connection with SSL disabled
 */
function get_db_connection_from_config() {
    global $db_config;
    
    try {
        // Create DSN string without SSL requirements
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db_config['host'],
            $db_config['port'],
            $db_config['dbname'],
            $db_config['charset']
        );
        
        // PDO options - explicitly disable SSL
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        // Create and return PDO connection
        $pdo = new PDO(
            $dsn,
            $db_config['username'],
            $db_config['password'],
            $options
        );
        
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection error: ' . $e->getMessage());
        throw new PDOException('Database connection failed: ' . $e->getMessage());
    }
}
EOD;

// Write the file
if (file_put_contents($fixed_db_file, $content)) {
    echo "<p style='color:green'>✅ Created fixed database connection file at: $fixed_db_file</p>";
} else {
    echo "<p style='color:red'>❌ Failed to create fixed database file</p>";
    exit;
}

// Now modify the auth/config.php file to include our fixed file
$config_file = __DIR__ . '/auth/config.php';
if (!file_exists($config_file)) {
    echo "<p style='color:red'>❌ Config file not found at: $config_file</p>";
    exit;
}

echo "<p>Found config file at: $config_file</p>";

// Read the content
$config_content = file_get_contents($config_file);

// Create a backup
file_put_contents($config_file . '.bak', $config_content);
echo "<p>Created backup at: " . $config_file . '.bak' . "</p>";

// Check if our file is already included
if (strpos($config_content, 'db-fixed.php') === false) {
    // Add our fixed file inclusion at the end of the file
    $config_content .= "\n\n// Include fixed database connection without SSL\nrequire_once __DIR__ . '/../utils/db-fixed.php';\n";
    
    // Write the modified content
    if (file_put_contents($config_file, $config_content)) {
        echo "<p style='color:green'>✅ Updated config file to use fixed database connection</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to update config file</p>";
    }
} else {
    echo "<p>Fixed database file is already included in config</p>";
}

// Test the connection
echo "<h2>Testing database connection...</h2>";
try {
    // Include our files to test
    require_once $config_file;
    
    if (function_exists('get_db_connection_from_config')) {
        $conn = get_db_connection_from_config();
        echo "<p style='color:green'>✅ Database connection successful!</p>";
        
        // Test a simple query
        $stmt = $conn->query("SELECT COUNT(*) as count FROM wp_charterhub_users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>User count: " . $result['count'] . "</p>";
    } else {
        echo "<p style='color:red'>❌ get_db_connection_from_config function not available!</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Connection failed: " . $e->getMessage() . "</p>";
}
?>
