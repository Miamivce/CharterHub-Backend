<?php
// Fix for database connection issues
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Connection Fix</h1>";

// Get the .env file path
$env_file = __DIR__ . '/.env';
if (!file_exists($env_file)) {
    echo "<p>Error: .env file not found at $env_file</p>";
    exit;
}

echo "<p>Found .env file at: $env_file</p>";

// Read the current content
$env_content = file_get_contents($env_file);
echo "<p>Original SSL setting:</p>";
if (preg_match('/DB_SSL(_MODE)?=([^\n]+)/', $env_content, $matches)) {
    echo "<pre>" . htmlspecialchars($matches[0]) . "</pre>";
} else {
    echo "<p>No DB_SSL setting found in .env file.</p>";
}

// Update SSL setting in .env file
$updated_content = preg_replace(
    '/DB_SSL(_MODE)?=REQUIRED/', 
    'DB_SSL$1=DISABLED', 
    $env_content
);

// Write back to .env file
if (file_put_contents($env_file, $updated_content)) {
    echo "<p style='color:green'>✅ Successfully updated .env file to disable SSL</p>";
} else {
    echo "<p style='color:red'>❌ Failed to update .env file</p>";
}

// Test connection after update
echo "<h2>Testing database connection after update...</h2>";
try {
    // Load new .env values
    $env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
        }
    }

    // Get database configuration
    $db_config = [
        'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
        'port' => getenv('DB_PORT') ?: '19174',
        'dbname' => getenv('DB_NAME') ?: 'defaultdb',
        'username' => getenv('DB_USER') ?: 'avnadmin',
        'password' => getenv('DB_PASSWORD') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C'
    ];

    // Connect without SSL
    $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['dbname']}";
    $conn = new PDO($dsn, $db_config['username'], $db_config['password']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color:green'>✅ Connection successful after update!</p>";
    
    // Test users table
    $stmt = $conn->query("SELECT COUNT(*) as count FROM wp_charterhub_users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>User count: " . $result['count'] . "</p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Connection still failed: " . $e->getMessage() . "</p>";
}
?>
