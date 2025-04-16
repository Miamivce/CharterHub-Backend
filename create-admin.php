<?php
/**
 * Admin User Creation/Reset Script
 * 
 * This script creates or updates an admin user in the wp_charterhub_users table
 * with the specified credentials.
 */

// Define CHARTERHUB_LOADED constant to allow including config files
define('CHARTERHUB_LOADED', true);

// Include the configuration
require_once __DIR__ . '/auth/config.php';

// Admin credentials to set
$admin_email = 'admin@charterhub.com';
$admin_password = 'Test123!';
$admin_first_name = 'Admin';
$admin_last_name = 'User';

try {
    // Get database connection
    $pdo = get_db_connection();
    
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM wp_charterhub_users WHERE email = ?");
    $stmt->execute([$admin_email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Hash the password
    $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
    
    if ($user) {
        // Update existing user
        $stmt = $pdo->prepare("
            UPDATE wp_charterhub_users 
            SET password = ?, role = 'admin', verified = 1,
                first_name = ?, last_name = ?,
                updated_at = NOW()
            WHERE email = ?
        ");
        $stmt->execute([$hashed_password, $admin_first_name, $admin_last_name, $admin_email]);
        
        echo "Admin user updated successfully!\n";
        echo "ID: " . $user['id'] . "\n";
        echo "Email: $admin_email\n";
        echo "Role: admin\n";
        echo "Password: $admin_password (hashed in database)\n";
    } else {
        // Create new admin user
        $stmt = $pdo->prepare("
            INSERT INTO wp_charterhub_users 
            (email, password, first_name, last_name, role, verified, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'admin', 1, NOW(), NOW())
        ");
        $stmt->execute([$admin_email, $hashed_password, $admin_first_name, $admin_last_name]);
        
        $newId = $pdo->lastInsertId();
        
        echo "Admin user created successfully!\n";
        echo "ID: $newId\n";
        echo "Email: $admin_email\n";
        echo "Role: admin\n";
        echo "Password: $admin_password (hashed in database)\n";
    }
    
    echo "\nYou can now log in with these credentials at admin.yachtstory.be\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    
    // Provide more detailed error information for debugging
    echo "\nDebugging information:\n";
    echo "PHP Version: " . phpversion() . "\n";
    echo "PDO Drivers: " . implode(", ", PDO::getAvailableDrivers()) . "\n";
    
    // Connection information (with password masked)
    global $db_config;
    echo "Connection attempt to: " . $db_config['host'] . ":" . ($db_config['port'] ?? '3306') . "\n";
    echo "Database: " . ($db_config['dbname'] ?? $db_config['name']) . "\n";
    echo "User: " . ($db_config['username'] ?? $db_config['user']) . "\n";
}
?>
