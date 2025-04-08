<?php
// Diagnostic and fix for client-login.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Login Script Fix</h1>";

// Path to the login script
$login_file = __DIR__ . '/auth/client-login.php';
if (!file_exists($login_file)) {
    echo "<p style='color:red'>❌ Login file not found at: $login_file</p>";
    exit;
}

echo "<p>Found login file at: $login_file</p>";

// Create a backup
$backup_file = $login_file . '.bak';
file_put_contents($backup_file, file_get_contents($login_file));
echo "<p>Created backup at: $backup_file</p>";

// Add error logging to the login file
$login_content = file_get_contents($login_file);

// Check if error logging is already enabled
if (strpos($login_content, "ini_set('display_errors', 0)") !== false) {
    echo "<p>Found error display setting in login file</p>";
    
    // Enable detailed error logging
    $modified_content = str_replace(
        "ini_set('display_errors', 0);",
        "ini_set('display_errors', 1);
error_reporting(E_ALL);
error_log('Client login script started');",
        $login_content
    );
    
    if ($modified_content !== $login_content) {
        file_put_contents($login_file, $modified_content);
        echo "<p style='color:green'>✅ Enabled error logging in login script</p>";
    }
}

// Create a JWT test file to check token generation
$jwt_test_file = __DIR__ . '/auth/jwt-test-minimal.php';
$jwt_test_content = <<<'EOD'
<?php
// Minimal test for JWT token generation
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Define constant to allow included files to run
define('CHARTERHUB_LOADED', true);

echo "<h1>JWT Token Generation Test</h1>";

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt-core.php';

echo "<h2>JWT Configuration</h2>";
echo "<p>JWT Secret: " . (isset($jwt_secret) ? substr($jwt_secret, 0, 3) . '...' . substr($jwt_secret, -3) : 'Not defined') . "</p>";
echo "<p>JWT Algorithm: " . (isset($jwt_algorithm) ? $jwt_algorithm : 'Not defined') . "</p>";
echo "<p>JWT Expiration: " . (isset($jwt_expiration) ? $jwt_expiration . ' seconds' : 'Not defined') . "</p>";

echo "<h2>Test Token Generation</h2>";
if (function_exists('generate_access_token')) {
    try {
        // Generate a test token
        $test_token = generate_access_token(12345, 'test@example.com', 'client', 1);
        
        if ($test_token) {
            echo "<p style='color:green'>✅ Successfully generated test token</p>";
            echo "<p>Token preview: " . substr($test_token, 0, 20) . "...</p>";
            
            // Try to validate the token
            if (function_exists('validate_token')) {
                $validation = validate_token($test_token);
                if (isset($validation['error']) && $validation['error'] === true) {
                    echo "<p style='color:red'>❌ Token validation failed: " . $validation['message'] . "</p>";
                } else {
                    echo "<p style='color:green'>✅ Token validation successful!</p>";
                }
            }
        } else {
            echo "<p style='color:red'>❌ Failed to generate token</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Exception during token generation: " . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
} else {
    echo "<p style='color:red'>❌ generate_access_token function not found</p>";
}

// Test refresh token generation
echo "<h2>Test Refresh Token Generation</h2>";
if (function_exists('generate_refresh_token')) {
    try {
        $refresh_token = generate_refresh_token(12345, 'test@example.com', 'client', 1);
        
        if ($refresh_token) {
            echo "<p style='color:green'>✅ Successfully generated refresh token</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to generate refresh token</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Exception during refresh token generation: " . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
} else {
    echo "<p style='color:red'>❌ generate_refresh_token function not found</p>";
}
EOD;

file_put_contents($jwt_test_file, $jwt_test_content);
echo "<p style='color:green'>✅ Created JWT test file at: $jwt_test_file</p>";

// Create a direct client login test
$login_test_file = __DIR__ . '/auth/direct-login-test.php';
$login_test_content = <<<'EOD'
<?php
// Direct test of client login functionality
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Define constant to allow included files to run
define('CHARTERHUB_LOADED', true);

echo "<h1>Direct Client Login Test</h1>";

// Simulate the login request
$_SERVER['REQUEST_METHOD'] = 'POST';
$test_email = 'test@example.com';
$test_password = 'password123';

// Create fake request body
$json = json_encode([
    'email' => $test_email,
    'password' => $test_password
]);

// Capture output
ob_start();

try {
    // Manual setup before including the login file
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/jwt-core.php';
    require_once __DIR__ . '/token-blacklist.php';
    require_once __DIR__ . '/../utils/database.php';
    
    // Define the helper functions that login script uses
    if (!function_exists('json_response')) {
        function json_response($data, $status = 200) {
            echo json_encode($data);
            return $data;
        }
    }
    
    if (!function_exists('error_response')) {
        function error_response($message, $status = 400, $code = null) {
            $response = ['error' => true, 'message' => $message];
            if ($code) {
                $response['code'] = $code;
            }
            echo json_encode($response);
            return $response;
        }
    }
    
    // Mock file_get_contents to return our test data
    function mock_file_get_contents($path) {
        global $json;
        if ($path === 'php://input') {
            return $json;
        }
        return file_get_contents($path);
    }
    
    // Replace real file_get_contents with our mock
    function file_get_contents($path) {
        return mock_file_get_contents($path);
    }
    
    echo "<h2>Testing Database Connection</h2>";
    try {
        $conn = getDbConnection();
        echo "<p style='color:green'>✅ Database connection successful</p>";
        
        // Check users table
        $stmt = $conn->query("SELECT COUNT(*) as count FROM wp_charterhub_users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Found " . $result['count'] . " users in database</p>";
        
        // Look for test user
        $stmt = $conn->prepare("SELECT id, email FROM wp_charterhub_users WHERE email = ? OR email LIKE ?");
        $stmt->execute([$test_email, 'test%']);
        $test_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($test_users) > 0) {
            echo "<p>Found test users:</p><ul>";
            foreach ($test_users as $user) {
                echo "<li>ID: " . $user['id'] . ", Email: " . $user['email'] . "</li>";
            }
            echo "</ul>";
            
            // Use this test user for login
            $test_email = $test_users[0]['email'];
            $json = json_encode([
                'email' => $test_email,
                'password' => $test_password
            ]);
        } else {
            echo "<p style='color:orange'>⚠️ No test users found. Using default test user.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Database connection failed: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>Simulating Login Request</h2>";
    echo "<p>Test email: " . $test_email . "</p>";
    echo "<p>Test password: [HIDDEN]</p>";
    
    echo "<h2>Login Logic Steps (Testing)</h2>";
    
    // Instead of including the full login script, test individual components
    // Parse JSON input
    $data = json_decode($json, true);
    echo "<p>JSON data parsed: " . (is_array($data) ? "Yes" : "No") . "</p>";
    
    // Normalize email
    $email = strtolower(trim($data['email']));
    echo "<p>Normalized email: " . $email . "</p>";
    
    // Try to find user
    try {
        echo "<p>Looking up user by email...</p>";
        $user = fetchRow(
            'SELECT id, email, password, first_name, last_name, role, verified, token_version FROM wp_charterhub_users WHERE LOWER(email) = LOWER(?)',
            [$email]
        );
        
        if ($user) {
            echo "<p style='color:green'>✅ User found in database!</p>";
            echo "<ul>";
            echo "<li>ID: " . $user['id'] . "</li>";
            echo "<li>Email: " . $user['email'] . "</li>";
            echo "<li>Role: " . $user['role'] . "</li>";
            echo "<li>Verified: " . ($user['verified'] ? "Yes" : "No") . "</li>";
            echo "</ul>";
            
            // Test token generation
            if (function_exists('generate_access_token')) {
                try {
                    echo "<p>Testing token generation for this user...</p>";
                    $token = generate_access_token(
                        $user['id'],
                        $user['email'],
                        $user['role'],
                        $user['token_version']
                    );
                    if ($token) {
                        echo "<p style='color:green'>✅ Token generation successful</p>";
                    } else {
                        echo "<p style='color:red'>❌ Token generation failed</p>";
                    }
                } catch (Exception $e) {
                    echo "<p style='color:red'>❌ Exception during token generation: " . $e->getMessage() . "</p>";
                }
            }
        } else {
            echo "<p style='color:red'>❌ User not found in database</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Error querying user: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Test failed: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Get and display output
$output = ob_get_clean();
echo $output;
?>
EOD;

file_put_contents($login_test_file, $login_test_content);
echo "<p style='color:green'>✅ Created direct login test at: $login_test_file</p>";

echo "<h2>Next Steps</h2>";
echo "<ol>";
echo "<li>Visit <a href='/auth/jwt-test-minimal.php'>/auth/jwt-test-minimal.php</a> to check if JWT token generation works</li>";
echo "<li>Visit <a href='/auth/direct-login-test.php'>/auth/direct-login-test.php</a> to test the login functionality directly</li>";
echo "<li>These tests will help identify where exactly in the login process the error is occurring</li>";
echo "</ol>";
?>
