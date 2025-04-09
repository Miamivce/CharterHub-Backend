<?php
/**
 * CharterHub Database View Fix
 * 
 * This script creates database views without the wp_ prefix
 * to make the code work with both table naming conventions.
 */

// Enable all error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set content type to plain text for easier reading
header('Content-Type: text/plain');
echo "=== CHARTERHUB TABLE PREFIX FIX ===\n\n";

try {
    // Connect directly to database using environment variables
    $host = getenv('DB_HOST') ?: 'localhost';
    $dbname = getenv('DB_NAME') ?: 'defaultdb';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    
    echo "Connecting to database: $host/$dbname as $user\n";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false // Disable SSL verification for easier connection
    ];
    
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, $options);
    echo "✅ Connected to database successfully\n\n";
    
    // Check for wp_charterhub_users table
    echo "Checking for wp_charterhub_users table...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_users'");
    $table_exists = $stmt->rowCount() > 0;
    
    if ($table_exists) {
        echo "✅ Found table: wp_charterhub_users\n";
        
        // First drop any existing view
        $pdo->exec("DROP VIEW IF EXISTS charterhub_users");
        
        // Create the view
        $pdo->exec("CREATE VIEW charterhub_users AS SELECT * FROM wp_charterhub_users");
        echo "✅ Created view: charterhub_users -> wp_charterhub_users\n";
        
        // Verify the view
        $stmt = $pdo->query("SELECT COUNT(*) FROM charterhub_users");
        $count = $stmt->fetchColumn();
        echo "✅ View verified: charterhub_users contains $count records\n";
    } else {
        echo "❌ Table wp_charterhub_users not found\n";
    }
    
    // Check for other common tables and create views as needed
    $tables_to_map = [
        'wp_charterhub_auth_logs' => 'charterhub_auth_logs',
        'wp_charterhub_invitations' => 'charterhub_invitations',
        'wp_charterhub_token_blacklist' => 'charterhub_token_blacklist',
        'wp_charterhub_booking_guests' => 'charterhub_booking_guests',
        'wp_charterhub_bookings' => 'charterhub_bookings'
    ];
    
    echo "\nChecking for additional tables...\n";
    
    foreach ($tables_to_map as $source_table => $view_name) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$source_table'");
        $exists = $stmt->rowCount() > 0;
        
        if ($exists) {
            echo "Found table: $source_table\n";
            $pdo->exec("DROP VIEW IF EXISTS $view_name");
            $pdo->exec("CREATE VIEW $view_name AS SELECT * FROM $source_table");
            echo "✅ Created view: $view_name -> $source_table\n";
        }
    }
    
    echo "\n=== FIX COMPLETED SUCCESSFULLY ===\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?> 