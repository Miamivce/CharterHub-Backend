<?php
// Script to debug login process and JWT token generation
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html');
echo "<pre>";

// 1. Check if generate_jwt function exists
echo "Step 1: Checking for generate_jwt function\n";
if (function_exists('generate_jwt')) {
    echo "✅ generate_jwt function exists globally\n";
} else {
    echo "❌ generate_jwt function does NOT exist globally\n";
    
    // Try to include jwt-core.php and check again
    $jwtCorePath = '/var/www/auth/jwt-core.php';
    if (file_exists($jwtCorePath)) {
        echo "   - JWT core file exists at $jwtCorePath\n";
        include_once $jwtCorePath;
        
        if (function_exists('generate_jwt')) {
            echo "   - ✅ generate_jwt function exists after including jwt-core.php\n";
        } else {
            echo "   - ❌ generate_jwt function still does NOT exist after including jwt-core.php\n";
            
            // Check file content
            $content = file_get_contents($jwtCorePath);
            echo "   - File contains 'function generate_jwt': " . (strpos($content, 'function generate_jwt') !== false ? "Yes" : "No") . "\n";
            echo "   - File contains 'function generate_access_token': " . (strpos($content, 'function generate_access_token') !== false ? "Yes" : "No") . "\n";
            
            // Add the function again
            $function = "\n\nfunction generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n    return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n}\n";
            file_put_contents($jwtCorePath, $function, FILE_APPEND);
            echo "   - 🔄 Added generate_jwt function again\n";
            
            // Include again and check
            include_once $jwtCorePath;
            echo "   - After adding and including again, function exists: " . (function_exists('generate_jwt') ? "Yes" : "No") . "\n";
        }
    } else {
        echo "   - ❌ JWT core file NOT found at $jwtCorePath\n";
    }
}

// 2. Check refresh tokens table
echo "\nStep 2: Checking refresh tokens table\n";
try {
    require_once '/var/www/library/db.php';
    $db = connectToDatabase();
    echo "✅ Database connection successful\n";
    
    $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
    $refreshTokensTable = $tablePrefix . 'charterhub_refresh_tokens';
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE '$refreshTokensTable'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "✅ Refresh tokens table exists\n";
        
        // Check table structure
        $stmt = $db->query("DESCRIBE $refreshTokensTable");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "   - Table columns: " . implode(", ", $columns) . "\n";
    } else {
        echo "❌ Refresh tokens table does NOT exist\n";
        
        // Create the table
        echo "   - Creating refresh tokens table...\n";
        $createTableQuery = "CREATE TABLE $refreshTokensTable (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expiry DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $db->exec($createTableQuery);
        echo "   - ✅ Refresh tokens table created successfully\n";
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
}

// 3. Debug client-login.php process
echo "\nStep 3: Debugging client-login.php\n";
echo "Trying to trace the execution of client-login.php...\n";

// Mock data for testing
$testEmail = 'test102@me.com';
$testPassword = 'password123'; // Replace with actual test password

// Try to include files from client-login.php
echo "Loading required files...\n";
try {
    // Include common files
    if (file_exists('/var/www/auth/global-cors.php')) {
        include_once '/var/www/auth/global-cors.php';
        echo "✅ Included global-cors.php\n";
    }
    
    if (file_exists('/var/www/library/db.php')) {
        // Already included above
        echo "✅ db.php is already included\n";
    }
    
    if (file_exists('/var/www/auth/jwt-core.php')) {
        // Already included above
        echo "✅ jwt-core.php is already included\n";
    }
    
    if (file_exists('/var/www/auth/auth-functions.php')) {
        include_once '/var/www/auth/auth-functions.php';
        echo "✅ Included auth-functions.php\n";
    }
    
    // Test user fetch
    echo "\nTesting user fetch:\n";
    if (function_exists('fetchUserByEmail')) {
        $user = fetchUserByEmail($testEmail);
        if ($user) {
            echo "✅ User found: ID {$user['id']}, Role: {$user['role']}\n";
        } else {
            echo "❌ User not found\n";
        }
    } else {
        echo "❌ fetchUserByEmail function does not exist\n";
    }
    
    // Test password verification
    echo "\nTesting password verification:\n";
    if (isset($user) && function_exists('verifyPassword')) {
        $passwordVerified = verifyPassword($testPassword, $user['password']);
        echo "Password verification: " . ($passwordVerified ? "✅ Success" : "❌ Failed") . "\n";
    } else {
        echo "❌ Cannot test password verification - missing function or user\n";
    }
    
    // Test token generation
    echo "\nTesting JWT token generation:\n";
    if (isset($user) && function_exists('generate_jwt')) {
        try {
            $token = generate_jwt($user['id'], $user['role'], $user['email'], $user['token_version'] ?? 1);
            echo "✅ JWT token generated successfully\n";
        } catch (Exception $e) {
            echo "❌ JWT token generation failed: " . $e->getMessage() . "\n";
        }
    } else {
        echo "❌ Cannot test JWT token generation - missing function or user\n";
    }
    
    // Test refresh token creation
    echo "\nTesting refresh token creation:\n";
    if (isset($user) && isset($db) && function_exists('createRefreshToken')) {
        try {
            $refreshToken = createRefreshToken($user['id'], $db);
            echo "✅ Refresh token created successfully\n";
        } catch (Exception $e) {
            echo "❌ Refresh token creation failed: " . $e->getMessage() . "\n";
            
            // Define the function if it doesn't exist
            if (!function_exists('createRefreshToken')) {
                echo "   - createRefreshToken function does not exist. Defining it now...\n";
                
                // Define the missing function
                function createRefreshToken($userId, $db) {
                    $token = bin2hex(random_bytes(32));
                    $hashedToken = password_hash($token, PASSWORD_DEFAULT);
                    
                    // Set expiry to 14 days from now
                    $expiry = date('Y-m-d H:i:s', strtotime('+14 days'));
                    
                    $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
                    $table = $tablePrefix . 'charterhub_refresh_tokens';
                    
                    $stmt = $db->prepare("INSERT INTO $table (user_id, token, expiry) VALUES (?, ?, ?)");
                    $stmt->execute([$userId, $hashedToken, $expiry]);
                    
                    return $token;
                }
                
                // Try again
                try {
                    $refreshToken = createRefreshToken($user['id'], $db);
                    echo "   - ✅ Refresh token created successfully after defining the function\n";
                } catch (Exception $e) {
                    echo "   - ❌ Still failed after defining: " . $e->getMessage() . "\n";
                }
            }
        }
    } else {
        echo "❌ Cannot test refresh token creation - missing function, database connection, or user\n";
    }
    
    // Test auth logging
    echo "\nTesting auth logging:\n";
    if (isset($user) && isset($db) && function_exists('logAuthEvent')) {
        try {
            logAuthEvent($user['id'], 'login', 'success', $db);
            echo "✅ Auth logging successful\n";
        } catch (Exception $e) {
            echo "❌ Auth logging failed: " . $e->getMessage() . "\n";
        }
    } else {
        echo "❌ Cannot test auth logging - missing function, database connection, or user\n";
    }
    
} catch (Exception $e) {
    echo "❌ General error during debug: " . $e->getMessage() . "\n";
}

echo "\nDebug process complete. Check the results above to identify the issue in the login process.";
echo "</pre>";
?> 