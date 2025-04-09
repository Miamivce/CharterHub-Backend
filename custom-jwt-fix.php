<?php
// Custom script to directly modify client-login.php with required functions and tables

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Results
$results = [
    'success' => true,
    'steps' => []
];

try {
    // 1. Check for client-login.php file
    $loginFilePath = '/var/www/auth/client-login.php';
    
    if (!file_exists($loginFilePath)) {
        throw new Exception("client-login.php not found at $loginFilePath");
    }
    
    $results['steps'][] = "Found client-login.php at $loginFilePath";
    
    // 2. Add generate_jwt function directly in client-login.php
    $loginContent = file_get_contents($loginFilePath);
    
    // Only add if not already there
    if (strpos($loginContent, 'function generate_jwt') === false) {
        // Find a good position to add the function - after require/include statements but before main logic
        $functionCode = "\n\n// Added by fix script\nif (!function_exists('generate_jwt')) {\n    function generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n        return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n    }\n}\n\n";
        
        // Try to insert after includes or at the beginning
        $includePos = strpos($loginContent, "<?php");
        if ($includePos !== false) {
            $includePos += 5; // Move past <?php
            $loginContent = substr($loginContent, 0, $includePos) . $functionCode . substr($loginContent, $includePos);
            $results['steps'][] = "Added generate_jwt function to client-login.php";
        } else {
            // Prepend to the file
            $loginContent = "<?php\n" . $functionCode . substr($loginContent, 5);
            $results['steps'][] = "Prepended generate_jwt function to client-login.php";
        }
        
        file_put_contents($loginFilePath, $loginContent);
    } else {
        $results['steps'][] = "generate_jwt function already exists in client-login.php";
    }
    
    // 3. Add createRefreshToken function if it doesn't exist
    if (strpos($loginContent, 'function createRefreshToken') === false) {
        $refreshTokenFunction = "\n\n// Added by fix script\nif (!function_exists('createRefreshToken')) {\n    function createRefreshToken(\$userId, \$db) {\n        \$token = bin2hex(random_bytes(32));\n        \$hashedToken = password_hash(\$token, PASSWORD_DEFAULT);\n        \n        // Set expiry to 14 days from now\n        \$expiry = date('Y-m-d H:i:s', strtotime('+14 days'));\n        \n        \$tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';\n        \$table = \$tablePrefix . 'charterhub_refresh_tokens';\n        \n        \$stmt = \$db->prepare(\"INSERT INTO \$table (user_id, token, expiry) VALUES (?, ?, ?)\");\n        \$stmt->execute([\$userId, \$hashedToken, \$expiry]);\n        \n        return \$token;\n    }\n}\n\n";
        
        // Add after the generate_jwt function
        $pos = strpos($loginContent, "function generate_jwt");
        if ($pos !== false) {
            $pos = strpos($loginContent, "}", $pos);
            if ($pos !== false) {
                $pos += 1; // Move past }
                $loginContent = substr($loginContent, 0, $pos) . $refreshTokenFunction . substr($loginContent, $pos);
                $results['steps'][] = "Added createRefreshToken function to client-login.php";
            }
        } else {
            // Add at the beginning after <?php
            $includePos = strpos($loginContent, "<?php");
            if ($includePos !== false) {
                $includePos += 5; // Move past <?php
                $loginContent = substr($loginContent, 0, $includePos) . $refreshTokenFunction . substr($loginContent, $includePos);
                $results['steps'][] = "Added createRefreshToken function to client-login.php at the beginning";
            }
        }
        
        file_put_contents($loginFilePath, $loginContent);
    } else {
        $results['steps'][] = "createRefreshToken function already exists in client-login.php";
    }
    
    // 4. Create refresh tokens table
    require_once '/var/www/library/db.php';
    $db = connectToDatabase();
    $results['steps'][] = "Connected to database";
    
    $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
    $refreshTokensTable = $tablePrefix . 'charterhub_refresh_tokens';
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE '$refreshTokensTable'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Create the table
        $createTableQuery = "CREATE TABLE $refreshTokensTable (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expiry DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $db->exec($createTableQuery);
        $results['steps'][] = "Created refresh tokens table $refreshTokensTable";
    } else {
        $results['steps'][] = "Refresh tokens table already exists";
    }
    
    // 5. Fix the JWT core file as well for completeness
    $jwtCorePath = '/var/www/auth/jwt-core.php';
    if (file_exists($jwtCorePath)) {
        $jwtContent = file_get_contents($jwtCorePath);
        
        if (strpos($jwtContent, 'function generate_jwt') === false) {
            $jwtFunction = "\n\n/**\n * Alias for generate_access_token for backward compatibility\n * @param int \$user_id The user ID\n * @param string \$role The user role\n * @param string \$email The user email\n * @param int \$token_version The token version\n * @return string The generated JWT token\n */\nfunction generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n    return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n}\n";
            
            // Append function
            file_put_contents($jwtCorePath, $jwtFunction, FILE_APPEND);
            $results['steps'][] = "Added generate_jwt function to jwt-core.php";
        } else {
            $results['steps'][] = "generate_jwt function already exists in jwt-core.php";
        }
    } else {
        $results['steps'][] = "jwt-core.php not found at $jwtCorePath";
    }
    
    $results['message'] = "All fixes applied successfully";
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['message'] = "Error: " . $e->getMessage();
}

// Output results
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT); 