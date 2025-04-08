<?php
/**
 * CharterHub Database Connection Test
 * 
 * This script tests database connectivity with different SSL settings
 * to help diagnose connection issues on Render.com or other hosting platforms.
 */

// Display errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>CharterHub Database Connection Test</h1>";
echo "<p>Testing connection to MySQL database on Aiven.io...</p>";

// Load environment variables from .env file if exists
if (file_exists(__DIR__ . '/.env')) {
    echo "<p>Loading configuration from .env file</p>";
    $env_lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse variable
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Set as environment variable
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Get database configuration from environment
$db_config = [
    'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
    'port' => getenv('DB_PORT') ?: '19174',
    'dbname' => getenv('DB_NAME') ?: 'defaultdb',
    'username' => getenv('DB_USER') ?: 'avnadmin',
    'password' => getenv('DB_PASSWORD') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4'
];

// Show connection details (without password)
echo "<p>Connection details:</p>";
echo "<ul>";
echo "<li>Host: " . $db_config['host'] . "</li>";
echo "<li>Port: " . $db_config['port'] . "</li>";
echo "<li>Database: " . $db_config['dbname'] . "</li>";
echo "<li>Username: " . $db_config['username'] . "</li>";
echo "<li>Password: [HIDDEN]</li>";
echo "</ul>";

// Test different SSL settings
$ssl_settings = [
    'REQUIRED' => [
        'description' => 'SSL Required',
        'options' => [
            PDO::MYSQL_ATTR_SSL_CA => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    ],
    'VERIFY_CA' => [
        'description' => 'SSL Verify CA',
        'options' => [
            PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/ca.pem',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    ],
    'DISABLED' => [
        'description' => 'SSL Disabled',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    ]
];

// Try each SSL setting
foreach ($ssl_settings as $ssl_mode => $ssl_config) {
    echo "<h2>Testing with " . $ssl_config['description'] . "</h2>";
    
    try {
        // Create DSN
        $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
        
        echo "<p>DSN: $dsn</p>";
        echo "<p>Attempting connection...</p>";
        
        // Attempt connection
        $start_time = microtime(true);
        $pdo = new PDO(
            $dsn,
            $db_config['username'],
            $db_config['password'],
            $ssl_config['options']
        );
        $elapsed = round(microtime(true) - $start_time, 2);
        
        echo "<p style='color:green'>✅ Connection successful with $ssl_mode! ($elapsed seconds)</p>";
        
        // Test a simple query
        $stmt = $pdo->query("SELECT NOW() as server_time, DATABASE() as current_db");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<p>Server time: " . $result['server_time'] . "</p>";
        echo "<p>Connected to database: " . $result['current_db'] . "</p>";
        
        // Test user tables
        $stmt = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_users'");
        if ($stmt->rowCount() > 0) {
            echo "<p>✅ Found users table!</p>";
            
            // Count users
            $stmt = $pdo->query("SELECT COUNT(*) as user_count FROM wp_charterhub_users");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Number of users: " . $result['user_count'] . "</p>";
        } else {
            echo "<p style='color:red'>❌ Users table not found!</p>";
        }
        
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ Connection failed with $ssl_mode: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr>";
}

// Connection summary
echo "<h2>Connection Test Summary</h2>";
echo "<p>If any of the connection methods above were successful, you can use that SSL mode in your configuration.</p>";
echo "<p>Make sure your .env file has the correct DB_SSL setting.</p>";
?> 