<?php
/**
 * CharterHub Login API Endpoint - DEBUG VERSION
 * 
 * This file handles user authentication and generates JWT tokens.
 * It includes extensive debug logging to help diagnose issues.
 */

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);
$debug_log_file = __DIR__ . '/login_debug_detailed.log';

// Helper function to log debug messages
function debug_log($message, $data = null) {
    global $debug_log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = $timestamp . ' - ' . $message;
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log_entry .= ' - ' . json_encode($data, JSON_PRETTY_PRINT);
        } else {
            $log_entry .= ' - ' . $data;
        }
    }
    
    file_put_contents($debug_log_file, $log_entry . "\n", FILE_APPEND);
}

debug_log("LOGIN DEBUG - Script started");

// Include the global CORS handler
try {
    debug_log("Including global-cors.php");
    require_once dirname(__FILE__) . '/global-cors.php';
    apply_global_cors(['POST', 'OPTIONS']);
    debug_log("CORS headers applied");
} catch (Exception $e) {
    debug_log("CORS ERROR", $e->getMessage());
}

// Include required files
try {
    debug_log("Including config.php");
    require_once dirname(__FILE__) . '/config.php';
    debug_log("Including jwt-core.php");
    require_once dirname(__FILE__) . '/jwt-core.php';
    debug_log("Including token-blacklist.php");
    require_once dirname(__FILE__) . '/token-blacklist.php';
    debug_log("All required files included");
} catch (Exception $e) {
    debug_log("INCLUDE ERROR", $e->getMessage());
    echo json_encode(['error' => true, 'message' => 'Server configuration error', 'details' => $e->getMessage()]);
    exit;
}

header('Content-Type: application/json');

// Define helper functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function json_response_debug($data, $status = 200) {
    debug_log("Sending JSON response with status: " . $status, $data);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function error_response_debug($message, $status = 400, $code = null) {
    debug_log("Sending error response: " . $message . " with status: " . $status . " and code: " . $code);
    http_response_code($status);
    header('Content-Type: application/json');
    $response = ['error' => true, 'message' => $message];
    if ($code) {
        $response['code'] = $code;
    }
    echo json_encode($response);
    exit;
}

// Check request method
debug_log("Request method: " . $_SERVER['REQUEST_METHOD']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    debug_log("OPTIONS request - exiting");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debug_log("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    error_response_debug('Method not allowed', 405);
}

// Get JSON input
$json = file_get_contents('php://input');
debug_log("Raw input received:", $json);
$data = json_decode($json, true);
debug_log("Decoded JSON data:", $data);

// Validate input
if (!$data) {
    debug_log("Invalid JSON data received");
    error_response_debug('Invalid request format');
}

if (!isset($data['email']) || !isset($data['password'])) {
    debug_log("Missing email or password in request");
    error_response_debug('Email and password are required');
}

// Normalize email
$email = strtolower(trim(sanitize_input($data['email'])));
$password = $data['password']; // Don't sanitize password
debug_log("Processing login for email: " . $email);

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    debug_log("Invalid email format: " . $email);
    error_response_debug('Invalid email format');
}

try {
    // Get database connection
    debug_log("Establishing database connection");
    $conn = get_db_connection_from_config();
    debug_log("Database connection established");
    
    // Log login attempt
    debug_log("Logging login attempt to auth_logs table");
    try {
        log_auth_action('login_attempt', 0, 'Login attempt', ['email' => $email]);
        debug_log("Login attempt logged successfully");
    } catch (Exception $logError) {
        debug_log("Error logging login attempt", $logError->getMessage());
        // Non-critical error, continue with login
    }
    
    // Verify database tables
    debug_log("Checking database tables");
    try {
        $tables_result = $conn->query("SHOW TABLES");
        $tables = [];
        while ($row = $tables_result->fetch_array()) {
            $tables[] = $row[0];
        }
        debug_log("Database tables found:", $tables);
        
        if (!in_array('wp_charterhub_users', $tables)) {
            debug_log("ERROR: wp_charterhub_users table not found");
        }
        
        if (!in_array('wp_charterhub_jwt_tokens', $tables)) {
            debug_log("ERROR: wp_charterhub_jwt_tokens table not found");
        }
    } catch (Exception $tableError) {
        debug_log("Error checking database tables", $tableError->getMessage());
    }
    
    // Try to find user by email
    debug_log("Searching for user with email: " . $email);
    try {
        $stmt = $conn->prepare('SELECT id, email, password, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE email = ?');
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        // If not found, try case-insensitive match
        if (!$user) {
            debug_log("User not found with exact email match, trying case-insensitive match");
            $stmt = $conn->prepare('SELECT id, email, password, first_name, last_name, phone_number, company, role, verified, token_version FROM wp_charterhub_users WHERE LOWER(email) = LOWER(?)');
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
        }
        
        // Check if user exists
        if (!$user) {
            debug_log("User not found for email: " . $email);
            log_auth_action('login_failed', 0, 'User not found', ['email' => $email]);
            error_response_debug('Invalid credentials', 401, 'invalid_credentials');
        }
        
        debug_log("User found:", array_diff_key($user, ['password' => ''])); // Log user without password
        
        // Check if user is verified
        if (!$user['verified']) {
            debug_log("Account not verified for user ID: " . $user['id']);
            log_auth_action('login_failed', $user['id'], 'Account not verified', ['email' => $email]);
            error_response_debug('Account not verified. Please check your email for verification instructions.', 401, 'account_not_verified');
        }
        
        // Verify password
        debug_log("Verifying password for user ID: " . $user['id']);
        if (!password_verify($password, $user['password'])) {
            debug_log("Password verification failed for user ID: " . $user['id']);
            log_auth_action('login_failed', $user['id'], 'Invalid password', ['email' => $email]);
            error_response_debug('Invalid credentials', 401, 'invalid_credentials');
        }
        
        debug_log("Password verification successful for user ID: " . $user['id']);
        
        // Update last login time
        debug_log("Updating last login time for user ID: " . $user['id']);
        $update_stmt = $conn->prepare('UPDATE wp_charterhub_users SET last_login = NOW() WHERE id = ?');
        $update_stmt->bind_param("i", $user['id']);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Generate tokens
        debug_log("Generating access token for user ID: " . $user['id']);
        $access_token = generate_access_token(
            $user['id'],
            $user['email'],
            $user['role'],
            $user['token_version']
        );
        
        if (!$access_token) {
            debug_log("Failed to generate access token for user ID: " . $user['id']);
            log_auth_action('token_generation_failed', $user['id'], 'Failed to generate access token');
            error_response_debug('Authentication error. Please try again later.', 500, 'token_generation_failed');
        }
        
        debug_log("Access token generated successfully");
        
        debug_log("Generating refresh token for user ID: " . $user['id']);
        $refresh_token_data = generate_refresh_token(
            $user['id'],
            $user['email'],
            $user['role'],
            $user['token_version']
        );
        
        if (!$refresh_token_data) {
            debug_log("Failed to generate refresh token for user ID: " . $user['id']);
            log_auth_action('token_generation_failed', $user['id'], 'Failed to generate refresh token');
            error_response_debug('Authentication error. Please try again later.', 500, 'token_generation_failed');
        }
        
        debug_log("Refresh token generated successfully with ID: " . $refresh_token_data['token_id']);
        
        // Set refresh token as HTTP-only cookie
        debug_log("Setting refresh token cookie");
        set_refresh_token_cookie($refresh_token_data['token'], $refresh_token_data['expires']);
        
        // Prepare user data to return
        $user_data = [
            'id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'phone_number' => $user['phone_number'] ?? '',
            'company' => $user['company'] ?? '',
            'role' => $user['role'],
            'verified' => (bool)$user['verified']
        ];
        
        // Log successful login
        debug_log("Logging successful login");
        log_auth_action('login_success', $user['id'], 'Login successful', [
            'token_id' => $refresh_token_data['token_id']
        ]);
        
        // Prepare response
        debug_log("Sending successful login response");
        json_response_debug([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user_data,
            'access_token' => $access_token,
            'expires_in' => $jwt_expiration,
            'token_type' => 'Bearer'
        ]);
        
    } catch (Exception $queryError) {
        debug_log("Error during user query", $queryError->getMessage());
        log_auth_action('login_error', 0, 'Database query error: ' . $queryError->getMessage());
        error_response_debug('Authentication error. Please try again later.', 500, 'database_error');
    }
    
} catch (Exception $e) {
    debug_log("Critical error during login", $e->getMessage());
    log_auth_action('login_error', 0, 'Login error: ' . $e->getMessage());
    error_response_debug('Authentication error. Please try again later.', 500, 'server_error');
} 