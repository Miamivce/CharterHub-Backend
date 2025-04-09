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
    // Get the path to jwt-core.php
    $jwtCorePath = __DIR__ . '/auth/jwt-core.php';
    
    if (!file_exists($jwtCorePath)) {
        throw new Exception("JWT core file not found at: $jwtCorePath");
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
    
    // Now check if the refresh tokens table exists
    // Include DB connection
    require_once __DIR__ . '/library/db.php';
    $db = connectToDatabase();
    
    $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
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
    
    $results['message'] = "Fix completed successfully. The JWT function alias has been added and the refresh tokens table has been checked.";
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['message'] = "Error: " . $e->getMessage();
}

// Output results
echo json_encode($results, JSON_PRETTY_PRINT); 