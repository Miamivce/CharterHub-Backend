<?php
// Debug script for client login issues
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Login Script Debug</h1>";

// Define CHARTERHUB_LOADED constant to allow included files to run
define('CHARTERHUB_LOADED', true);

// Add error handling
function my_error_handler($errno, $errstr, $errfile, $errline) {
    echo "<div style='color:red;border:1px solid red;padding:5px;margin:5px;'>";
    echo "<strong>Error:</strong> [$errno] $errstr<br>";
    echo "Error on line $errline in file $errfile";
    echo "</div>";
    return true;
}
set_error_handler("my_error_handler");

echo "<h2>Testing JWT Library</h2>";
// Check if Firebase JWT library is available
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
    echo "<p>Composer autoload file exists</p>";
    
    if (class_exists('Firebase\JWT\JWT')) {
        echo "<p style='color:green'>✅ Firebase JWT library is available</p>";
    } else {
        echo "<p style='color:red'>❌ Firebase JWT library is NOT available</p>";
        echo "<p>This is likely causing your login to fail. Install with: <code>composer require firebase/php-jwt</code></p>";
    }
} else {
    echo "<p style='color:red'>❌ Composer autoload file is missing at: $composer_autoload</p>";
}

echo "<h2>JWT Configuration</h2>";
// Check JWT configuration
$auth_config_file = __DIR__ . '/auth/config.php';
if (file_exists($auth_config_file)) {
    echo "<p>Auth config file exists at: $auth_config_file</p>";
    
    try {
        // Include the file with error suppression
        ob_start();
        require_once $auth_config_file;
        $output = ob_get_clean();
        
        if (!empty($output)) {
            echo "<p style='color:orange'>⚠️ Warning: Auth config file produced output:</p>";
            echo "<pre>" . htmlspecialchars($output) . "</pre>";
        }
        
        // Check JWT variables
        echo "<p>JWT Secret defined: " . (isset($jwt_secret) ? "Yes" : "No") . "</p>";
        if (isset($jwt_secret)) {
            echo "<p>JWT Secret length: " . strlen($jwt_secret) . " characters</p>";
            echo "<p>JWT Secret starts with: " . substr($jwt_secret, 0, 3) . "...</p>";
        }
        
        echo "<p>Auth config array exists: " . (isset($auth_config) ? "Yes" : "No") . "</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Error loading auth config: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ Auth config file is missing at: $auth_config_file</p>";
}

echo "<h2>Testing Login Script Dependencies</h2>";

// Check required files
$required_files = [
    '/auth/global-cors.php',
    '/auth/config.php',
    '/auth/jwt-core.php',
    '/auth/token-blacklist.php',
    '/utils/database.php'
];

foreach ($required_files as $file) {
    $path = __DIR__ . $file;
    if (file_exists($path)) {
        echo "<p style='color:green'>✅ Found: $file</p>";
    } else {
        echo "<p style='color:red'>❌ Missing: $file</p>";
    }
}

echo "<h2>Simulating Login Process</h2>";
$login_file = __DIR__ . '/auth/client-login.php';

if (file_exists($login_file)) {
    echo "<p>Login file exists at: $login_file</p>";
    
    try {
        // Get login file contents 
        $login_contents = file_get_contents($login_file);
        $login_size = strlen($login_contents);
        echo "<p>Login file size: $login_size bytes</p>";
        
        // Output first 10 lines to verify it's correct
        $login_lines = explode("\n", $login_contents);
        $first_lines = array_slice($login_lines, 0, 10);
        echo "<p>First few lines of login script:</p>";
        echo "<pre>" . htmlspecialchars(implode("\n", $first_lines)) . "</pre>";
        
        echo "<p>Testing connection to database in login context...</p>";
        
        // Include required files (that the login script would use)
        try {
            require_once __DIR__ . '/auth/config.php';
            require_once __DIR__ . '/utils/database.php';
            
            // Test getDbConnection function if it exists
            if (function_exists('getDbConnection')) {
                try {
                    $conn = getDbConnection();
                    echo "<p style='color:green'>✅ Database connection from login context successful!</p>";
                    
                    // Test basic query
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM wp_charterhub_users");
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo "<p>Number of users: " . $result['count'] . "</p>";
                } catch (Exception $e) {
                    echo "<p style='color:red'>❌ Database connection from login context failed: " . $e->getMessage() . "</p>";
                }
            } else {
                echo "<p style='color:red'>❌ getDbConnection function not found!</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color:red'>❌ Error including required files: " . $e->getMessage() . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Error accessing login file: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ Login file is missing at: $login_file</p>";
}

echo "<h2>Environment Settings</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>PDO MySQL Extension: " . (extension_loaded('pdo_mysql') ? "Enabled" : "Disabled") . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
?>
