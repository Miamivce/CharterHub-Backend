<?php
// db-test.php - Database connection test script
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Connection Test</h1>";

// Load configuration (adjust path if needed)
if (file_exists(__DIR__ . '/.env')) {
    // Load from .env file
    echo "<p>Loading from .env file</p>";
    $env_lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
        }
    }
}

// Get database configuration
$db_config = [
    'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
    'port' => getenv('DB_PORT') ?: '19174',
    'dbname' => getenv('DB_NAME') ?: 'defaultdb',
    'username' => getenv('DB_USER') ?: 'avnadmin',
    'password' => getenv('DB_PASSWORD') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4'
];

// Show connection details (without password)
echo "<p>Database connection details:</p>";
echo "<ul>";
echo "<li>Host: " . $db_config['host'] . "</li>";
echo "<li>Port: " . $db_config['port'] . "</li>";
echo "<li>Database: " . $db_config['dbname'] . "</li>";
echo "<li>Username: " . $db_config['username'] . "</li>";
echo "<li>Password: [HIDDEN]</li>";
echo "</ul>";

// Test connection with SSL enabled (as required by Aiven)
echo "<h2>Testing database connection with SSL...</h2>";
try {
    $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_CA => true  // Enable SSL
    ];
    
    echo "<p>Attempting connection to: $dsn</p>";
    $conn = new PDO($dsn, $db_config['username'], $db_config['password'], $options);
    echo "<p style='color:green'>✅ Connection successful!</p>";
    
    // Test basic query
    $stmt = $conn->query("SELECT NOW() as time");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Server time: " . $result['time'] . "</p>";
    
    // Check if users table exists
    echo "<h3>Testing users table:</h3>";
    $stmt = $conn->query("SHOW TABLES LIKE 'wp_charterhub_users'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✅ Users table exists</p>";
        
        // Count users
        $stmt = $conn->query("SELECT COUNT(*) as count FROM wp_charterhub_users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Number of users: " . $result['count'] . "</p>";
        
        // Check sample user data (without revealing sensitive info)
        $stmt = $conn->query("SELECT id, email, LEFT(first_name, 1) as first_initial, LEFT(last_name, 1) as last_initial FROM wp_charterhub_users LIMIT 1");
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<p>Sample user exists: ID " . $user['id'] . ", Email: " . htmlspecialchars(substr($user['email'], 0, 3)) . "***</p>";
        } else {
            echo "<p style='color:orange'>⚠️ No users found in table</p>";
        }
    } else {
        echo "<p style='color:red'>❌ Users table doesn't exist!</p>";
        
        // Check all tables
        echo "<h3>Available tables:</h3>";
        echo "<ul>";
        $stmt = $conn->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            foreach ($tables as $table) {
                echo "<li>" . htmlspecialchars($table) . "</li>";
            }
        } else {
            echo "<li>No tables found in database</li>";
        }
        echo "</ul>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Connection failed: " . $e->getMessage() . "</p>";
    
    // Try alternative connection without SSL
    echo "<h2>Trying alternative connection without SSL...</h2>";
    try {
        $options_no_ssl = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $conn_no_ssl = new PDO($dsn, $db_config['username'], $db_config['password'], $options_no_ssl);
        echo "<p style='color:green'>✅ Connection without SSL successful!</p>";
    } catch (PDOException $e2) {
        echo "<p style='color:red'>❌ Alternative connection also failed: " . $e2->getMessage() . "</p>";
    }
}

// Show PHP and environment info
echo "<h2>Environment Information</h2>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>PDO MySQL extension: " . (extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled') . "</li>";
echo "<li>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</li>";
echo "</ul>";
?>
