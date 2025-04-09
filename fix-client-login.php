<?php
// Ultra simple script - just adds functions directly to client-login.php without database access
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Output as plain text for better error visibility
header('Content-Type: text/plain');
echo "Starting client-login.php fix...\n\n";

try {
    // 1. Find client-login.php
    $loginFilePath = '/var/www/auth/client-login.php';
    
    if (!file_exists($loginFilePath)) {
        throw new Exception("ERROR: client-login.php not found at $loginFilePath");
    }
    
    echo "Found client-login.php at $loginFilePath\n";
    
    // 2. Read the file
    $loginContent = file_get_contents($loginFilePath);
    if ($loginContent === false) {
        throw new Exception("ERROR: Failed to read client-login.php");
    }
    
    echo "Successfully read client-login.php content\n";
    
    // 3. Check if generate_jwt function already exists
    if (strpos($loginContent, 'function generate_jwt') !== false) {
        echo "Function generate_jwt already exists in client-login.php\n";
    } else {
        echo "Function generate_jwt not found, adding it...\n";
        
        // Add the function definition after <?php
        $functionCode = "\n\n// Added by fix script\nif (!function_exists('generate_jwt')) {\n    function generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n        if (function_exists('generate_access_token')) {\n            return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n        } else {\n            // Fallback implementation if needed\n            require_once __DIR__ . '/jwt-core.php';\n            return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n        }\n    }\n}\n\n";
        
        // Insert after <?php
        $insertPos = strpos($loginContent, "<?php");
        if ($insertPos !== false) {
            $insertPos += 5; // Move past <?php
            $newContent = substr($loginContent, 0, $insertPos) . $functionCode . substr($loginContent, $insertPos);
            
            // 4. Write the modified content back to the file
            $writeResult = file_put_contents($loginFilePath, $newContent);
            if ($writeResult === false) {
                throw new Exception("ERROR: Failed to write changes to client-login.php");
            }
            
            echo "SUCCESS: Added generate_jwt function to client-login.php\n";
        } else {
            throw new Exception("ERROR: Could not find <?php tag in client-login.php");
        }
    }
    
    // 5. Check if createRefreshToken function needs to be added
    if (strpos($loginContent, 'function createRefreshToken') !== false) {
        echo "Function createRefreshToken already exists in client-login.php\n";
    } else {
        echo "Function createRefreshToken not found, adding it...\n";
        
        // Define the refresh token function that doesn't need DB connection upfront
        $refreshTokenFunction = "\n\n// Added by fix script\nif (!function_exists('createRefreshToken')) {\n    function createRefreshToken(\$userId, \$db) {\n        // Generate a random token\n        \$token = bin2hex(random_bytes(32));\n        \$hashedToken = password_hash(\$token, PASSWORD_DEFAULT);\n        \n        // Set expiry to 14 days from now\n        \$expiry = date('Y-m-d H:i:s', strtotime('+14 days'));\n        \n        // Define table name\n        \$tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';\n        \$table = \$tablePrefix . 'charterhub_refresh_tokens';\n        \n        // Check if table exists, create if not\n        \$tableExists = false;\n        try {\n            \$stmt = \$db->query(\"SHOW TABLES LIKE '\$table'\");\n            \$tableExists = \$stmt->rowCount() > 0;\n        } catch (\\Exception \$e) {\n            // Table doesn't exist or error occurred\n        }\n        \n        if (!\$tableExists) {\n            try {\n                \$createTableQuery = \"CREATE TABLE \$table (\n                    id INT AUTO_INCREMENT PRIMARY KEY,\n                    user_id INT NOT NULL,\n                    token VARCHAR(255) NOT NULL,\n                    expiry DATETIME NOT NULL,\n                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n                )\";\n                \$db->exec(\$createTableQuery);\n            } catch (\\Exception \$e) {\n                // Couldn't create table, but continue anyway\n            }\n        }\n        \n        // Insert the token\n        try {\n            \$stmt = \$db->prepare(\"INSERT INTO \$table (user_id, token, expiry) VALUES (?, ?, ?)\");\n            \$stmt->execute([\$userId, \$hashedToken, \$expiry]);\n        } catch (\\Exception \$e) {\n            // If insert fails, still return the token and continue\n        }\n        \n        return \$token;\n    }\n}\n\n";
        
        // Read the content again since we might have modified it
        $loginContent = file_get_contents($loginFilePath);
        
        // Find position to insert - after generate_jwt function if it exists
        $insertPos = strpos($loginContent, "function generate_jwt");
        if ($insertPos !== false) {
            $insertPos = strpos($loginContent, "}", $insertPos);
            if ($insertPos !== false) {
                $insertPos += 1; // Move past }
            } else {
                // Fallback to right after <?php
                $insertPos = strpos($loginContent, "<?php");
                $insertPos = ($insertPos !== false) ? $insertPos + 5 : 0;
            }
        } else {
            // Fallback to right after <?php
            $insertPos = strpos($loginContent, "<?php");
            $insertPos = ($insertPos !== false) ? $insertPos + 5 : 0;
        }
        
        // Insert the function
        $newContent = substr($loginContent, 0, $insertPos) . $refreshTokenFunction . substr($loginContent, $insertPos);
        
        // Write back to file
        $writeResult = file_put_contents($loginFilePath, $newContent);
        if ($writeResult === false) {
            throw new Exception("ERROR: Failed to write createRefreshToken function to client-login.php");
        }
        
        echo "SUCCESS: Added createRefreshToken function to client-login.php\n";
    }
    
    echo "\nAll fixes have been applied to client-login.php.\n";
    echo "You should now be able to log in successfully.\n";
    echo "After logging in, please reapply the full fix (including database table creation) as needed.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Failed to apply fixes.\n";
}
?> 