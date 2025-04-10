<?php
/**
 * Direct Client Login Fix
 * 
 * This script will directly fix the client-login.php file
 * by replacing it with a more robust version.
 */

// Set headers for output
header('Content-Type: text/plain');

// Define the path to the client login file
$login_file_path = __DIR__ . '/auth/client-login.php';

// Expand to include variations based on common deployments
$possible_paths = [
    $login_file_path,
    '/var/www/auth/client-login.php',
    '/var/www/html/auth/client-login.php',
    '/app/auth/client-login.php',
    './auth/client-login.php',
    '../auth/client-login.php'
];

// Find the correct path
$found_path = null;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $found_path = $path;
        break;
    }
}

if (!$found_path) {
    echo "ERROR: Could not find the client-login.php file in any of the expected locations.\n";
    echo "Checked paths:\n";
    foreach ($possible_paths as $path) {
        echo " - $path\n";
    }
    exit(1);
}

echo "FOUND: client-login.php at $found_path\n";

// Create a backup
$backup_path = $found_path . '.bak.' . time();
if (copy($found_path, $backup_path)) {
    echo "BACKUP: Created backup at $backup_path\n";
} else {
    echo "WARNING: Failed to create backup. Proceeding with caution...\n";
}

// The fixed client login code
$fixed_client_login = <<<'EOD'
<?php
/**
 * CharterHub Client Login API - Fixed Version
 * 
 * Enhanced error handling, proper connection management, and
 * simplified token generation.
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include configuration and dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/global-cors.php';
require_once __DIR__ . '/../utils/database.php';
require_once __DIR__ . '/jwt-core.php';

// Apply CORS headers
apply_global_cors(['POST', 'OPTIONS']);

// Content-Type header
header('Content-Type: application/json; charset=UTF-8');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to users, but log them

// Function to send JSON response
function send_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("CLIENT-LOGIN: Method not allowed: " . $_SERVER['REQUEST_METHOD']);
    send_response([
        'success' => false,
        'error' => 'method_not_allowed',
        'message' => 'Method not allowed'
    ], 405);
}

// Read and validate input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    error_log("CLIENT-LOGIN: Invalid input format");
    send_response([
        'success' => false,
        'error' => 'invalid_input',
        'message' => 'Invalid input format'
    ], 400);
}

// Validate required fields
if (!isset($data['email']) || !isset($data['password'])) {
    error_log("CLIENT-LOGIN: Missing required fields (email or password)");
    send_response([
        'success' => false,
        'error' => 'missing_fields',
        'message' => 'Email and password are required'
    ], 400);
}

try {
    // Get database connection
    $pdo = get_db_connection();
    error_log("CLIENT-LOGIN: Database connection successful");
    
    // Authentication logic
    $email = strtolower(trim($data['email']));
    $password = $data['password'];
    $remember_me = isset($data['rememberMe']) ? (bool)$data['rememberMe'] : false;
    
    // Get user data from database
    try {
        $query = "SELECT id, email, password, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE email = ?";
        $user = fetchRow($query, [$email]);
        
        if (!$user) {
            error_log("CLIENT-LOGIN: User not found: $email");
            send_response([
                'success' => false,
                'error' => 'authentication_failed',
                'message' => 'Invalid credentials'
            ], 401);
        }
        
        error_log("CLIENT-LOGIN: User found: " . $user['id']);
        
        // Check if user is verified
        if (!$user['verified']) {
            error_log("CLIENT-LOGIN: User not verified: " . $user['id']);
            send_response([
                'success' => false,
                'error' => 'account_not_verified',
                'message' => 'Please verify your email before logging in'
            ], 401);
        }
        
        // Verify the password
        if (!password_verify($password, $user['password'])) {
            error_log("CLIENT-LOGIN: Invalid password for user: " . $user['id']);
            send_response([
                'success' => false,
                'error' => 'authentication_failed',
                'message' => 'Invalid credentials'
            ], 401);
        }
        
        // Make sure user is a client
        if ($user['role'] !== 'client') {
            error_log("CLIENT-LOGIN: Role mismatch - Expected client, got: " . $user['role']);
            send_response([
                'success' => false,
                'error' => 'role_mismatch',
                'message' => 'Please use the admin login page'
            ], 403);
        }
        
        // Update last login time (in a try/catch to prevent errors from breaking login)
        try {
            executeQuery("UPDATE wp_charterhub_users SET last_login = NOW() WHERE id = ?", [$user['id']]);
            error_log("CLIENT-LOGIN: Updated last_login for user: " . $user['id']);
        } catch (Exception $e) {
            // Log but don't fail on this error
            error_log("CLIENT-LOGIN: Warning - Could not update last_login: " . $e->getMessage());
        }
        
        // Generate JWT function
        if (!function_exists('generate_jwt')) {
            function generate_jwt($payload, $expiry = 1800) {
                global $jwt_secret;
                
                $issuedAt = time();
                $expiryTime = $issuedAt + $expiry;
                
                $header = json_encode([
                    'alg' => 'HS256',
                    'typ' => 'JWT'
                ]);
                
                $payload['iat'] = $issuedAt;
                $payload['exp'] = $expiryTime;
                $payload['jti'] = bin2hex(random_bytes(16));
                
                $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
                $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
                
                $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $jwt_secret, true);
                $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
                
                return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
            }
        }
        
        // Create refresh token function
        function createRefreshToken($user_id, $remember_me = false) {
            global $pdo;
            
            try {
                // Generate a secure random token
                $refresh_token = bin2hex(random_bytes(32));
                $hashed_token = password_hash($refresh_token, PASSWORD_DEFAULT);
                
                // Set expiration time (14 days for remember me, 1 day otherwise)
                $expires_in = $remember_me ? 14 * 24 * 60 * 60 : 24 * 60 * 60;
                $expiry = date('Y-m-d H:i:s', time() + $expires_in);
                
                // Delete any existing refresh tokens for this user
                $delete_stmt = $pdo->prepare("DELETE FROM wp_charterhub_refresh_tokens WHERE user_id = ?");
                $delete_stmt->execute([$user_id]);
                
                // Insert the new refresh token
                $insert_stmt = $pdo->prepare("INSERT INTO wp_charterhub_refresh_tokens (user_id, token, expiry, created_at) VALUES (?, ?, ?, NOW())");
                $insert_stmt->execute([$user_id, $hashed_token, $expiry]);
                
                // Return the plain text token (this will be stored in an HTTP-only cookie)
                return $refresh_token;
            } catch (Exception $e) {
                error_log("Failed to create refresh token: " . $e->getMessage());
                return null;
            }
        }
        
        // Generate token with better error handling
        try {
            // Create the JWT
            $jwt_payload = [
                'sub' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'ver' => $user['token_version'] ?? 0
            ];
            
            error_log("CLIENT-LOGIN: Generating JWT for user: " . $user['id']);
            
            // Generate the access token (30 minutes)
            $access_token = generate_jwt($jwt_payload, 30 * 60);
            
            if (!$access_token) {
                throw new Exception("Failed to generate access token");
            }
            
            // Generate the refresh token
            $refresh_token = createRefreshToken($user['id'], $remember_me);
            
            if (!$refresh_token) {
                error_log("CLIENT-LOGIN: Warning - Failed to create refresh token for user " . $user['id']);
                // Continue anyway, just without refresh capability
            }
            
            // Set the refresh token as an HTTP-only cookie if created successfully
            if ($refresh_token) {
                $expires = $remember_me ? time() + (14 * 24 * 60 * 60) : 0;
                setcookie('refresh_token', $refresh_token, [
                    'expires' => $expires,
                    'path' => '/',
                    'httponly' => true,
                    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                    'samesite' => 'Strict'
                ]);
            }
            
            // Format user data
            $formatted_user = [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'],
                'fullName' => trim($user['first_name'] . ' ' . $user['last_name']),
                'phoneNumber' => $user['phone_number'] ?? '',
                'company' => $user['company'] ?? '',
                'role' => $user['role'],
                'verified' => (bool)$user['verified']
            ];
            
            // Send success response
            error_log("CLIENT-LOGIN: Login successful for user: " . $user['id']);
            send_response([
                'success' => true,
                'token' => $access_token,
                'user' => $formatted_user
            ]);
            
        } catch (Exception $e) {
            error_log("CLIENT-LOGIN: Error generating tokens: " . $e->getMessage());
            send_response([
                'success' => false,
                'error' => 'token_generation_failed',
                'message' => 'Failed to generate authentication tokens'
            ], 500);
        }
        
    } catch (Exception $e) {
        error_log("CLIENT-LOGIN: Database error: " . $e->getMessage());
        send_response([
            'success' => false,
            'error' => 'server_error',
            'message' => 'Authentication error. Please try again later.'
        ], 500);
    }
    
} catch (Exception $e) {
    error_log("CLIENT-LOGIN: Fatal error: " . $e->getMessage());
    send_response([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Authentication error. Please try again later.'
    ], 500);
}
EOD;

// Write the fixed content to the file
if (file_put_contents($found_path, $fixed_client_login)) {
    echo "SUCCESS: Updated client-login.php with fixed code\n";
    echo "The client login should now work properly\n";
} else {
    echo "ERROR: Failed to write the updated code to $found_path\n";
    echo "Do you have write permissions for this file?\n";
    exit(1);
}

echo "\nFix implementation complete!\n";
echo "Please try logging in again.\n";
?> 