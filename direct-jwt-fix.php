<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Results array
$results = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'message' => '',
    'details' => []
];

try {
    // Server paths - check for both possible locations
    $possiblePaths = [
        '/var/www/auth/jwt-core.php',  // Production path
        __DIR__ . '/auth/jwt-core.php', // Local path
    ];
    
    $jwtCorePath = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $jwtCorePath = $path;
            $results['details'][] = "Found JWT core file at: $jwtCorePath";
            break;
        }
    }
    
    if (!$jwtCorePath) {
        throw new Exception("JWT core file not found in any of the expected locations");
    }
    
    // Read the file
    $jwtCoreContent = file_get_contents($jwtCorePath);
    
    // Check if the function already exists
    if (strpos($jwtCoreContent, 'function generate_jwt') !== false) {
        $results['details'][] = "generate_jwt function already exists, no changes needed";
    } else {
        // Check if generate_access_token exists
        if (strpos($jwtCoreContent, 'function generate_access_token') === false) {
            throw new Exception("generate_access_token function not found in jwt-core.php. Cannot create alias.");
        }
        
        // Add the alias function
        $aliasFunction = "\n\n/**\n * Alias for generate_access_token for backward compatibility\n * @param int \$user_id The user ID\n * @param string \$role The user role\n * @param string \$email The user email\n * @param int \$token_version The token version\n * @return string The generated JWT token\n */\nfunction generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n    return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n}\n";
        
        // Append the function to the end of the file
        $updatedContent = $jwtCoreContent . $aliasFunction;
        
        // Write the updated content back to the file
        if (file_put_contents($jwtCorePath, $updatedContent) === false) {
            throw new Exception("Failed to write updates to jwt-core.php");
        }
        
        $results['details'][] = "Successfully added generate_jwt function alias to jwt-core.php";
    }
    
    // Instead of relying on included files for DB connection, create direct PDO connection
    try {
        // Database credentials - these should match your production environment
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: 'charterhub';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        $tablePrefix = getenv('TABLE_PREFIX') ?: 'wp_';
        
        $results['details'][] = "Attempting to connect to database at $host";
        
        // Create PDO connection with SSL options for cloud databases
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
        ];
        
        // Try both with and without SSL
        try {
            $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
            $db = new PDO($dsn, $username, $password, $options);
            $results['details'][] = "Connected to database successfully";
        } catch (Exception $e) {
            // Try without SSL options
            unset($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
            $db = new PDO($dsn, $username, $password, $options);
            $results['details'][] = "Connected to database successfully (without SSL)";
        }
        
        // Check if the refresh tokens table exists
        $refreshTokensTable = $tablePrefix . 'charterhub_refresh_tokens';
        
        // Check if the table exists
        $stmt = $db->query("SHOW TABLES LIKE '$refreshTokensTable'");
        $tableExists = $stmt->rowCount() > 0;
        
        if ($tableExists) {
            $results['details'][] = "Refresh tokens table already exists";
        } else {
            // Create the table
            $createTableQuery = "CREATE TABLE $refreshTokensTable (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                expiry DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            
            $db->exec($createTableQuery);
            $results['details'][] = "Successfully created refresh tokens table";
        }
    } catch (Exception $e) {
        $results['details'][] = "Database operation error: " . $e->getMessage();
        $results['details'][] = "JWT function alias was added but database operations failed";
    }
    
    $results['message'] = "Fix completed successfully. The JWT function alias has been added.";
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['message'] = "Error: " . $e->getMessage();
}

// Output results
echo json_encode($results, JSON_PRETTY_PRINT); 