<?php
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
ini_set("display_errors", 0); // Don't display to users, but log them

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
            // Log but don't fail on this error
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