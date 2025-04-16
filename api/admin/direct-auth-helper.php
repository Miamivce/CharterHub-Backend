<?php
/**
 * API Authentication Helper
 * 
 * Authentication helper functions for direct API endpoints.
 * Handles JWT token validation and admin authorization.
 */

// Prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Initialize the response
$response = array(
    'success' => false,
    'message' => '',
    'data' => null
);

// Include admin-cors-helper.php - check multiple possible locations
$admin_cors_paths = [
    __DIR__ . '/admin-cors-helper.php', 
    dirname(__FILE__) . '/admin-cors-helper.php',
    '/var/www/api/admin/admin-cors-helper.php'
];

$cors_helper_loaded = false;
foreach ($admin_cors_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $cors_helper_loaded = true;
        error_log("Loaded admin-cors-helper.php from: $path");
        break;
    }
}

if (!$cors_helper_loaded) {
    error_log("WARNING: admin-cors-helper.php could not be found in any of the expected locations");
    
    // Define a minimal CORS function if the helper wasn't found
    if (!function_exists('apply_admin_cors_headers')) {
        function apply_admin_cors_headers($allowed_methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']) {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
            $allowed_origins = [
                'http://localhost:3000',
                'http://localhost:5173', 
                'https://admin.yachtstory.be',
                'https://www.admin.yachtstory.be',
                'http://admin.yachtstory.be'
            ];
            
            error_log("Direct API Request from origin: $origin (fallback CORS handler)");
            
            if (in_array($origin, $allowed_origins)) {
                header("Access-Control-Allow-Origin: $origin");
                header("Access-Control-Allow-Credentials: true");
                header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
                header("Access-Control-Allow-Methods: " . implode(', ', $allowed_methods));
                
                if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                    http_response_code(200);
                    exit;
                }
            }
        }
    }
    
    // Define a backup logging function if not found in the helper
    if (!function_exists('log_request_details')) {
        function log_request_details() {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : 'none';
            $method = $_SERVER['REQUEST_METHOD'];
            $uri = $_SERVER['REQUEST_URI'];
            $headers = json_encode(getallheaders());
            
            error_log("FALLBACK LOGGER: API Request: $method $uri from $origin");
            error_log("FALLBACK LOGGER: Request Headers: $headers");
            
            // Log request body for non-GET requests
            if ($method !== 'GET' && $method !== 'OPTIONS') {
                $input = file_get_contents('php://input');
                if (!empty($input)) {
                    error_log("FALLBACK LOGGER: Request Body: " . $input);
                }
            }
        }
    }
}

// Start output buffering to prevent header issues
ob_start();

/**
 * Get Database Connection
 * 
 * Connects to the MySQL database.
 */
function getDbConnection() {
    // Database configuration
    $host = "localhost";
    $dbname = "charter_db";
    $username = "charter_user";
    $password = "charter_pass";
    
    try {
        $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch(PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        sendJsonResponse(false, "Database connection failed", null, 500);
        exit;
    }
}

/**
 * Validate Admin Access
 * 
 * Checks if the request has valid admin credentials.
 */
function validateAdminAccess() {
    // Check for Authorization header
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (empty($authHeader)) {
        error_log("No Authorization header provided");
        sendJsonResponse(false, "Unauthorized: No token provided", null, 401);
        exit;
    }
    
    // Extract token from Bearer format
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        error_log("Invalid Authorization header format");
        sendJsonResponse(false, "Unauthorized: Invalid token format", null, 401);
        exit;
    }
    
    // Validate JWT token (simplified for example)
    // In a real application, use a proper JWT library
    if (!validateJwtToken($token)) {
        error_log("Invalid JWT token");
        sendJsonResponse(false, "Unauthorized: Invalid token", null, 401);
        exit;
    }
    
    return true;
}

/**
 * Validate JWT Token
 * 
 * Simplified JWT validation.
 */
function validateJwtToken($token) {
    // This is a simplified placeholder
    // In production, implement proper JWT validation
    
    // Example: Check if token is in valid format and not expired
    if (empty($token) || strlen($token) < 10) {
        return false;
    }
    
    // For development, always return true
    // TODO: Implement proper validation
    return true;
}

/**
 * Sanitize Input Data
 * 
 * Sanitizes input data to prevent security issues.
 */
function sanitizeInputData($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInputData($value);
        }
    } else {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    return $data;
}

/**
 * Send JSON Response
 * 
 * Sends a formatted JSON response.
 */
function sendJsonResponse($success, $message, $data = null, $statusCode = 200) {
    global $response;
    
    // End output buffering and clean the buffer to prevent any content being sent before headers
    if (ob_get_length()) {
        ob_end_clean();
    }
    
    http_response_code($statusCode);
    
    if (isset($response) && is_array($response)) {
        $response['success'] = $success;
        $response['message'] = $message;
        $response['data'] = $data;
    } else {
        $response = array(
            'success' => $success,
            'message' => $message,
            'data' => $data
        );
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
    // Make sure to exit after sending the response
    exit;
}

// Database connection
function get_database_connection() {
    // Try to use the same database config as the rest of the application
    $possible_config_paths = [
        __DIR__ . '/../../config/db.php',
        __DIR__ . '/../../../config/db.php',
        __DIR__ . '/../../../../config/db.php',
    ];
    
    $db_config = null;
    foreach ($possible_config_paths as $path) {
        if (file_exists($path)) {
            // Include the config file to get the variables
            include($path);
            if (isset($db_host) && isset($db_name) && isset($db_user)) {
                $db_config = [
                    'host' => $db_host,
                    'name' => $db_name,
                    'user' => $db_user,
                    'pass' => isset($db_pass) ? $db_pass : ''
                ];
                break;
            }
        }
    }
    
    // If no config file found, use these hardcoded defaults
    if (!$db_config) {
        $db_config = [
            'host' => 'localhost',
            'name' => 'charterhub_local',
            'user' => 'root',
            'pass' => ''
        ];
    }
    
    try {
        $conn = new mysqli(
            $db_config['host'], 
            $db_config['user'], 
            $db_config['pass'], 
            $db_config['name']
        );
        
        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $conn->connect_error,
                'attempted_config' => [
                    'host' => $db_config['host'],
                    'name' => $db_config['name'],
                    'user' => $db_config['user']
                ]
            ]);
            exit;
        }
        
        return $conn;
    } catch (Exception $e) {
        error_log("Database connection exception: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection exception: ' . $e->getMessage(),
            'attempted_config' => [
                'host' => $db_config['host'],
                'name' => $db_config['name'],
                'user' => $db_config['user']
            ]
        ]);
        exit;
    }
}

// Manually validate JWT token
function validate_token_manual($token) {
    if (empty($token)) {
        return false;
    }
    
    // Split the token
    $token_parts = explode('.', $token);
    if (count($token_parts) !== 3) {
        return false;
    }
    
    // Decode payload
    $payload_json = base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1]));
    $payload = json_decode($payload_json);
    
    if (!$payload) {
        return false;
    }
    
    // Check expiration
    if (isset($payload->exp) && $payload->exp < time()) {
        return false;
    }
    
    return $payload;
}

// Extract and validate token from Authorization header
function get_validated_token() {
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : 
                  (isset($headers['authorization']) ? $headers['authorization'] : null);
    
    if (!$auth_header || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Authorization header missing or invalid'
        ]);
        http_response_code(401);
        exit;
    }
    
    $token = $matches[1];
    $payload = validate_token_manual($token);
    
    if (!$payload) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired token'
        ]);
        http_response_code(401);
        exit;
    }
    
    return $payload;
}

// Check if user is an admin
function ensure_admin_access() {
    // Get and validate token
    $payload = get_validated_token();
    
    // Check admin role
    if (!isset($payload->role) || $payload->role !== 'admin') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        http_response_code(403);
        exit;
    }
    
    // Verify user exists in database
    $conn = get_database_connection();
    $user_id = $payload->sub;
    
    $stmt = $conn->prepare("SELECT id, email, role FROM wp_charterhub_users WHERE id = ? AND role = 'admin'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result->num_rows) {
        $conn->close();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Admin user not found in database'
        ]);
        http_response_code(403);
        exit;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return [
        'user_id' => $user_id,
        'email' => $user['email'],
        'role' => $user['role']
    ];
}

// Sanitize input data
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        if (is_array($data)) {
            return array_map('sanitize_input', $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

// Send JSON response
if (!function_exists('json_response')) {
    function json_response($data, $status = 200) {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}

// Alias for ensure_admin_access that returns a boolean
if (!function_exists('is_admin_user')) {
    function is_admin_user() {
        try {
            ensure_admin_access();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Handle admin API request with proper CORS handling
 * 
 * This function safely handles CORS and authentication for admin endpoints
 * 
 * @param callable $callback Function that contains the endpoint-specific logic
 * @return void
 */
function handle_admin_request($callback) {
    // Initialize response structure first to prevent undefined variable
    $response = [
        'success' => false,
        'message' => '',
        'data' => null
    ];
    
    // Ensure we start with clean output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start fresh output buffer to catch any unexpected output
    ob_start();
    
    // Track current output buffering level
    $initial_ob_level = ob_get_level();
    
    try {
        // 1. Apply CORS headers first - CRITICAL: Apply headers before any processing
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
        
        // Use the standardized admin CORS helper
        apply_admin_cors_headers($methods);
        
        // Log detailed request information
        log_request_details();
        
        error_log("DIRECT-AUTH: Starting admin request processing for " . $_SERVER['REQUEST_METHOD']);
        
        try {
            // 3. Perform authentication for non-OPTIONS requests
            error_log("DIRECT-AUTH: Authenticating admin user");
            $admin_user = ensure_admin_access();
            error_log("DIRECT-AUTH: Admin authentication successful for user ID: " . ($admin_user['user_id'] ?? $admin_user['id'] ?? 'unknown'));
            
            // 4. Execute the endpoint-specific callback
            error_log("DIRECT-AUTH: Executing endpoint callback");
            $result = $callback($admin_user);
            
            // 5. Build success response
            $response['success'] = true;
            $response['data'] = $result;
            error_log("DIRECT-AUTH: Request processed successfully");
        } catch (Exception $auth_e) {
            error_log("DIRECT-AUTH: Authentication or processing error: " . $auth_e->getMessage() . " - Code: " . $auth_e->getCode());
            throw $auth_e;
        }
        
    } catch (Exception $e) {
        // Log any exceptions
        error_log("ADMIN API Exception: " . $e->getMessage() . " - Code: " . $e->getCode() . " - File: " . $e->getFile() . " - Line: " . $e->getLine());
        if ($e->getPrevious()) {
            error_log("Caused by: " . $e->getPrevious()->getMessage());
        }
        
        // Build error response
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        $response['error'] = true;
        $response['code'] = $e->getCode() ?: 500;
    }
    
    // Clean up output buffer to ensure no content has been sent
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // 6. Return JSON response
    error_log("DIRECT-AUTH: Sending final response: " . ($response['success'] ? 'success' : 'error'));
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?> 