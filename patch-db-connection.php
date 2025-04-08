<?php
// Patch to fix the database connection by modifying the original config file
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Connection Patch</h1>";

// Target the config file directly
$config_file = __DIR__ . '/auth/config.php';
if (!file_exists($config_file)) {
    echo "<p style='color:red'>❌ Config file not found at: $config_file</p>";
    exit;
}

echo "<p>Found config file at: $config_file</p>";

// Read the current content
$config_content = file_get_contents($config_file);

// Create a backup
$backup_file = $config_file . '.patch.bak';
file_put_contents($backup_file, $config_content);
echo "<p>Created backup at: $backup_file</p>";

// Look for the get_db_connection_from_config function
if (preg_match('/function\s+get_db_connection_from_config\s*\(\)\s*\{.*?\}/s', $config_content, $matches)) {
    echo "<p>Found get_db_connection_from_config function in config file</p>";
    
    // Original function found, now modify it to disable SSL
    $original_function = $matches[0];
    
    // Check if SSL is being added in the function
    if (strpos($original_function, 'PDO::MYSQL_ATTR_SSL_CA') !== false) {
        echo "<p>Found SSL configuration in the function</p>";
        
        // Replace the SSL options part with empty options
        $patched_function = preg_replace(
            '/if\s*\(\$db_config\[\'ssl_mode\'\]\s*===\s*\'REQUIRED\'\)\s*\{.*?\}/s',
            '// SSL disabled for compatibility
        // SSL options removed',
            $original_function
        );
        
        if ($patched_function !== $original_function) {
            // Replace the function in the whole file
            $patched_content = str_replace($original_function, $patched_function, $config_content);
            
            if (file_put_contents($config_file, $patched_content)) {
                echo "<p style='color:green'>✅ Successfully patched config file to disable SSL</p>";
            } else {
                echo "<p style='color:red'>❌ Failed to write patched config file</p>";
            }
        } else {
            echo "<p style='color:orange'>⚠️ No changes made to the function</p>";
        }
    } else {
        echo "<p>No SSL configuration found in the function</p>";
    }
} else {
    echo "<p style='color:red'>❌ Could not find get_db_connection_from_config function</p>";
    
    // Alternative approach: Try to find the connection options section
    if (preg_match('/\$options\s*=\s*\[.*?\]/s', $config_content, $matches)) {
        echo "<p>Found options array in config file</p>";
        
        $original_options = $matches[0];
        
        // Remove any SSL options
        $patched_options = preg_replace(
            '/PDO::MYSQL_ATTR_SSL_CA.*?[,\n]/',
            '',
            $original_options
        );
        
        if ($patched_options !== $original_options) {
            // Replace the options in the whole file
            $patched_content = str_replace($original_options, $patched_options, $config_content);
            
            if (file_put_contents($config_file, $patched_content)) {
                echo "<p style='color:green'>✅ Successfully patched options array to disable SSL</p>";
            } else {
                echo "<p style='color:red'>❌ Failed to write patched config file</p>";
            }
        } else {
            echo "<p>No changes needed to options array</p>";
        }
    } else {
        echo "<p style='color:red'>❌ Could not find options array</p>";
    }
}

// Dummy test function that won't conflict
echo "<h2>Testing database connection...</h2>";
try {
    // Include the modified config file
    require_once $config_file;
    
    // Create a new test function to avoid conflicts
    function test_db_connection() {
        global $db_config;
        
        // Create DSN string
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db_config['host'],
            $db_config['port'],
            $db_config['dbname'],
            $db_config['charset']
        );
        
        // Connect with basic options, no SSL
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        
        // Create connection
        $pdo = new PDO(
            $dsn,
            $db_config['username'],
            $db_config['password'],
            $options
        );
        
        return $pdo;
    }
    
    // Test connection
    $conn = test_db_connection();
    echo "<p style='color:green'>✅ Test connection successful!</p>";
    
    // Try the original function too
    if (function_exists('get_db_connection_from_config')) {
        $orig_conn = get_db_connection_from_config();
        echo "<p style='color:green'>✅ Original connection function also working!</p>";
        
        // Test a simple query
        $stmt = $orig_conn->query("SELECT COUNT(*) as count FROM wp_charterhub_users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>User count: " . $result['count'] . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Connection test failed: " . $e->getMessage() . "</p>";
}
?>
