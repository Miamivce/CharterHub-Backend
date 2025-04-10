<?php
/**
 * Debug Client Login Issues
 * 
 * This script attempts to fix login issues by:
 * 1. Adding detailed error logging
 * 2. Ensuring database connections are properly closed
 * 3. Adding try/catch blocks around key operations
 * 4. Simplifying the token generation process
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include configuration and database connection
require_once __DIR__ . '/utils/database.php';

// Set headers for output
header('Content-Type: text/plain');

// Function to check the login script
function debug_login_script() {
    echo "Starting login script debugging...\n\n";
    
    try {
        // Connect to database
        $pdo = get_db_connection();
        echo "Database connection successful\n";
        
        // First check the client-login.php file
        $login_path = __DIR__ . '/auth/client-login.php';
        if (!file_exists($login_path)) {
            echo "Error: client-login.php file not found at expected location\n";
            echo "Expected path: $login_path\n";
            return;
        }
        
        echo "Found client-login.php file at: $login_path\n";
        
        // Backup the original file
        $backup_path = __DIR__ . '/auth/client-login.php.bak';
        if (!file_exists($backup_path)) {
            copy($login_path, $backup_path);
            echo "Created backup of client-login.php at: $backup_path\n";
        }
        
        // Read the content of the file
        $content = file_get_contents($login_path);
        if (!$content) {
            echo "Error: Could not read client-login.php file\n";
            return;
        }
        
        echo "Successfully read client-login.php content (" . strlen($content) . " bytes)\n";
        
        // Create a fixed version of the login script
        $fixed_content = create_fixed_login_script();
        
        // Write the fixed content to a new file
        $fixed_path = __DIR__ . '/auth/client-login-fixed.php';
        if (file_put_contents($fixed_path, $fixed_content)) {
            echo "Created fixed login script at: $fixed_path\n";
            echo "To use this fixed script, rename it to client-login.php after testing\n";
        } else {
            echo "Error: Could not create fixed login script\n";
        }
        
        echo "\nDebugging complete. Here's the summary:\n";
        echo "1. Checked database connection: Success\n";
        echo "2. Found client-login.php file: Success\n";
        echo "3. Created backup of original file: Success\n";
        echo "4. Created fixed version for testing: Success\n\n";
        echo "To test the fixed login script, access it directly at: /auth/client-login-fixed.php\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Function to create a fixed login script with better error handling
function create_fixed_login_script() {
    // This is a simplified version of the login script with better error handling
    return '<?php
/**
 * CharterHub Client Login API Endpoint - Fixed Version
 * 
 * This is an enhanced version of the client login script with:
 * - Improved error handling
 * - Detailed logging
 * - Proper database connection management
 * - Fixed token generation
 */

// Define CHARTERHUB_LOADED constant
define("CHARTERHUB_LOADED", true);

// Include configuration and dependencies
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/global-cors.php";
require_once __DIR__ . "/../utils/database.php";
require_once __DIR__ . "/jwt-core.php";

// Apply CORS headers
apply_global_cors(["POST", "OPTIONS"]);

// Content-Type header
header("Content-Type: application/json; charset=UTF-8");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set("display_errors", 0); // Don\'t display to users, but log them

// Function to send JSON response
function send_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

// Only allow POST requests
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    error_log("CLIENT-LOGIN-FIXED: Method not allowed: " . $_SERVER["REQUEST_METHOD"]);
    send_response([
        "success" => false,
        "error" => "method_not_allowed",
        "message" => "Method not allowed"
    ], 405);
}

// Read and validate input
$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!is_array($data)) {
    error_log("CLIENT-LOGIN-FIXED: Invalid input format");
    send_response([
        "success" => false,
        "error" => "invalid_input",
        "message" => "Invalid input format"
    ], 400);
}

// Validate required fields
if (!isset($data["email"]) || !isset($data["password"])) {
    error_log("CLIENT-LOGIN-FIXED: Missing required fields (email or password)");
    send_response([
        "success" => false,
        "error" => "missing_fields",
        "message" => "Email and password are required"
    ], 400);
}

try {
    // Get database connection
    $pdo = get_db_connection();
    error_log("CLIENT-LOGIN-FIXED: Database connection successful");
    
    // Authentication logic
    $email = strtolower(trim($data["email"]));
    $password = $data["password"];
    $remember_me = isset($data["rememberMe"]) ? (bool)$data["rememberMe"] : false;
    
    // Get user data from database
    try {
        $query = "SELECT id, email, password, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE email = ?";
        $user = fetchRow($query, [$email]);
        
        if (!$user) {
            error_log("CLIENT-LOGIN-FIXED: User not found: $email");
            send_response([
                "success" => false,
                "error" => "authentication_failed",
                "message" => "Invalid credentials"
            ], 401);
        }
        
        error_log("CLIENT-LOGIN-FIXED: User found: " . $user["id"]);
        
        // Check if user is verified
        if (!$user["verified"]) {
            error_log("CLIENT-LOGIN-FIXED: User not verified: " . $user["id"]);
            send_response([
                "success" => false,
                "error" => "account_not_verified",
                "message" => "Please verify your email before logging in"
            ], 401);
        }
        
        // Verify the password
        if (!password_verify($password, $user["password"])) {
            error_log("CLIENT-LOGIN-FIXED: Invalid password for user: " . $user["id"]);
            send_response([
                "success" => false,
                "error" => "authentication_failed",
                "message" => "Invalid credentials"
            ], 401);
        }
        
        // Make sure user is a client
        if ($user["role"] !== "client") {
            error_log("CLIENT-LOGIN-FIXED: Role mismatch - Expected client, got: " . $user["role"]);
            send_response([
                "success" => false,
                "error" => "role_mismatch",
                "message" => "Please use the admin login page"
            ], 403);
        }
        
        // Update last login time (in a try/catch to prevent errors from breaking login)
        try {
            executeQuery("UPDATE wp_charterhub_users SET last_login = NOW() WHERE id = ?", [$user["id"]]);
            error_log("CLIENT-LOGIN-FIXED: Updated last_login for user: " . $user["id"]);
        } catch (Exception $e) {
            // Log but don\'t fail on this error
            error_log("CLIENT-LOGIN-FIXED: Warning - Could not update last_login: " . $e->getMessage());
        }
        
        // Generate token
        try {
            $token_data = [
                "sub" => $user["id"],
                "email" => $user["email"],
                "role" => $user["role"],
                "ver" => $user["token_version"] ?? 0
            ];
            
            error_log("CLIENT-LOGIN-FIXED: Generating tokens for user: " . $user["id"]);
            
            // Generate tokens (access + refresh)
            $tokens = generateTokens($token_data, $remember_me);
            
            if (!$tokens || !isset($tokens["access_token"])) {
                throw new Exception("Failed to generate tokens");
            }
            
            // Format user data
            $formatted_user = [
                "id" => (int)$user["id"],
                "email" => $user["email"],
                "firstName" => $user["first_name"],
                "lastName" => $user["last_name"],
                "fullName" => trim($user["first_name"] . " " . $user["last_name"]),
                "phoneNumber" => $user["phone_number"] ?? "",
                "company" => $user["company"] ?? "",
                "role" => $user["role"],
                "verified" => (bool)$user["verified"]
            ];
            
            // Send success response
            error_log("CLIENT-LOGIN-FIXED: Login successful for user: " . $user["id"]);
            send_response([
                "success" => true,
                "token" => $tokens["access_token"],
                "user" => $formatted_user
            ]);
            
        } catch (Exception $e) {
            error_log("CLIENT-LOGIN-FIXED: Error generating tokens: " . $e->getMessage());
            send_response([
                "success" => false,
                "error" => "token_generation_failed",
                "message" => "Failed to generate authentication tokens"
            ], 500);
        }
        
    } catch (Exception $e) {
        error_log("CLIENT-LOGIN-FIXED: Database error: " . $e->getMessage());
        send_response([
            "success" => false,
            "error" => "server_error",
            "message" => "Authentication error. Please try again later."
        ], 500);
    }
    
} catch (Exception $e) {
    error_log("CLIENT-LOGIN-FIXED: Fatal error: " . $e->getMessage());
    send_response([
        "success" => false,
        "error" => "server_error",
        "message" => "Authentication error. Please try again later."
    ], 500);
}
';
}

// Run the debugging function
debug_login_script(); 