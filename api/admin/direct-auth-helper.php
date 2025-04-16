<?php
/**
 * Direct API Authentication Helper
 * 
 * Contains helper functions for authentication with the direct API endpoints
 */

// Prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
    require_once 'admin-cors-helper.php';
    exit('No direct script access allowed');
}

// Database connection
function getDbConnection() {
    require_once dirname(__DIR__, 2) . '/includes/config.php';
    
    try {
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $db;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception("Database connection failed", 500);
    }
}

// JWT Token Validation
function validateAdminAccess() {
    // Get the authorization header
    $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    
    // Check if Bearer token is provided
    if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        error_log("No valid authorization header found");
        throw new Exception('Unauthorized access', 401);
    }
    
    $token = $matches[1];
    
    // Get JWT secret from config
    require_once dirname(__DIR__, 2) . '/includes/config.php';
    
    try {
        // Validate the token (implement proper JWT validation)
        // This is a simple placeholder - implement full JWT validation
        $tokenParts = explode('.', $token);
        if (count($tokenParts) !== 3) {
            throw new Exception('Invalid token format', 401);
        }
        
        // Decode payload
        $payload = json_decode(base64_decode($tokenParts[1]), true);
        
        if (!$payload || !isset($payload['sub']) || !isset($payload['role'])) {
            throw new Exception('Invalid token payload', 401);
        }
        
        // Check if the user is an admin
        if ($payload['role'] !== 'admin') {
            throw new Exception('Unauthorized: admin role required', 403);
        }
        
        // Get admin info from database
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT id, email, username, role FROM wp_charterhub_users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$payload['sub']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            throw new Exception('Admin user not found', 401);
        }
        
        return $admin;
        
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        throw new Exception('Authentication failed: ' . $e->getMessage(), 401);
    }
}

// Input sanitization
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Helper function to send JSON response and exit
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Common function to connect to the database
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
    // Track current output buffering level
    $initial_ob_level = ob_get_level();
    
    try {
        // 1. Apply CORS headers first - CRITICAL: Apply headers before any processing
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
        
        // Use the standardized admin CORS helper
        apply_admin_cors_headers($methods);
        
        // Log detailed request information
        log_admin_request_details();
        
        error_log("DIRECT-AUTH: Starting admin request processing for " . $_SERVER['REQUEST_METHOD']);
        
        // 2. Initialize response structure
        $response = [
            'success' => false,
            'message' => '',
            'data' => null
        ];
        
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
    } finally {
        // Clean up output buffer to the original level
        while (ob_get_level() > $initial_ob_level) {
            ob_end_clean();
        }
        
        // 6. Return JSON response
        error_log("DIRECT-AUTH: Sending final response: " . ($response['success'] ? 'success' : 'error'));
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}
?> 