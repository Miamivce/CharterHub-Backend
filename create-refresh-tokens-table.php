<?php
// Script to create the refresh_tokens table and add logging to client-login.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Output as plain text for better visibility
header('Content-Type: text/plain');
echo "Starting refresh_tokens table creation...\n\n";

try {
    // Find db.php using different possible paths
    $possiblePaths = [
        '/var/www/library/db.php',
        '/var/www/auth/db.php',
        '/var/www/includes/db.php',
        '/var/www/auth/client-login.php' // To extract DB connection code
    ];
    
    $dbPath = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            echo "Found potential database path: $path\n";
            $dbPath = $path;
            if ($path !== '/var/www/auth/client-login.php') {
                break; // We prefer the actual db.php if found
            }
        }
    }
    
    if (!$dbPath) {
        throw new Exception("Could not find any database connection file");
    }
    
    // Get DB credentials from environment
    echo "Attempting to get database credentials from environment...\n";
    $host = getenv('DB_HOST') ?: 'localhost';
    $dbname = getenv('DB_NAME') ?: 'charterhub';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $tablePrefix = getenv('TABLE_PREFIX') ?: 'wp_';
    
    echo "Database host: $host\n";
    echo "Database name: $dbname\n";
    echo "Table prefix: $tablePrefix\n";
    
    // Try direct PDO connection
    echo "\nAttempting direct database connection...\n";
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
    ];
    
    try {
        $db = new PDO($dsn, $username, $password, $options);
        echo "✅ Connected to database successfully\n";
    } catch (Exception $e) {
        echo "❌ Direct connection failed: " . $e->getMessage() . "\n";
        
        // Try without SSL options
        echo "Trying connection without SSL...\n";
        unset($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
        try {
            $db = new PDO($dsn, $username, $password, $options);
            echo "✅ Connected to database without SSL\n";
        } catch (Exception $e) {
            throw new Exception("All database connection attempts failed: " . $e->getMessage());
        }
    }
    
    // Create refresh_tokens table
    echo "\nChecking for refresh_tokens table...\n";
    $refreshTokensTable = $tablePrefix . 'charterhub_refresh_tokens';
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE '$refreshTokensTable'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ Refresh tokens table already exists\n";
        
        // Check table structure
        $stmt = $db->query("DESCRIBE $refreshTokensTable");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        echo "Table columns: " . implode(", ", $columns) . "\n";
    } else {
        echo "❌ Refresh tokens table does not exist, creating it...\n";
        
        // Create the table
        $createTableQuery = "CREATE TABLE $refreshTokensTable (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expiry DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $db->exec($createTableQuery);
        echo "✅ Successfully created refresh tokens table\n";
    }
    
    // Now add verbose error logging to client-login.php
    echo "\nAdding detailed error logging to client-login.php...\n";
    $loginFilePath = '/var/www/auth/client-login.php';
    
    if (file_exists($loginFilePath)) {
        $loginContent = file_get_contents($loginFilePath);
        
        // Check if we already added error logging
        if (strpos($loginContent, '// VERBOSE ERROR LOGGING') === false) {
            // Add error logging at the top of the file
            $errorLogging = "\n\n// VERBOSE ERROR LOGGING\nini_set('display_errors', 1);\nerror_reporting(E_ALL);\n\n// Start output buffering to capture errors\nob_start();\n\n// Custom error handler to log errors\nset_error_handler(function(\$errno, \$errstr, \$errfile, \$errline) {\n    error_log(\"ERROR [\$errno]: \$errstr in \$errfile on line \$errline\");\n    // Also log to a file\n    file_put_contents('/tmp/client-login-errors.log', date('Y-m-d H:i:s') . \" ERROR [\$errno]: \$errstr in \$errfile on line \$errline\\n\", FILE_APPEND);\n    return false; // Let the standard error handler continue\n});\n\n// Register shutdown function to catch fatal errors\nregister_shutdown_function(function() {\n    \$error = error_get_last();\n    if (\$error && (\$error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {\n        error_log(\"FATAL ERROR: {\$error['message']} in {\$error['file']} on line {\$error['line']}\");\n        file_put_contents('/tmp/client-login-errors.log', date('Y-m-d H:i:s') . \" FATAL ERROR: {\$error['message']} in {\$error['file']} on line {\$error['line']}\\n\", FILE_APPEND);\n    }\n    \n    // Flush output buffer\n    \$output = ob_get_clean();\n    \n    // If there was an error and not already sending JSON, convert to JSON error response\n    if (\$error && !headers_sent()) {\n        header('Content-Type: application/json');\n        echo json_encode([\n            'success' => false,\n            'message' => 'An error occurred during login',\n            'debug_info' => \"{\$error['message']} in {\$error['file']} on line {\$error['line']}\"\n        ]);\n    } else {\n        echo \$output;\n    }\n});\n\n";
            
            // Insert after <?php
            $phpPos = strpos($loginContent, "<?php");
            if ($phpPos !== false) {
                $insertPos = $phpPos + 5; // Move past <?php
                $newContent = substr($loginContent, 0, $insertPos) . $errorLogging . substr($loginContent, $insertPos);
                
                // Write back to file
                if (file_put_contents($loginFilePath, $newContent) !== false) {
                    echo "✅ Added detailed error logging to client-login.php\n";
                } else {
                    echo "❌ Failed to write error logging to client-login.php\n";
                }
            } else {
                echo "❌ Could not find <?php tag in client-login.php\n";
            }
        } else {
            echo "✅ Error logging already exists in client-login.php\n";
        }
    } else {
        echo "❌ client-login.php file not found at $loginFilePath\n";
    }
    
    echo "\nAll operations completed\n";
    echo "Check /tmp/client-login-errors.log for detailed error information after your next login attempt\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Failed to complete operations.\n";
}
?> 