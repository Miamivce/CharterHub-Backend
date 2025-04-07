<?php
/**
 * Direct Authentication Helper
 * 
 * Provides token validation and admin access control without relying on
 * external JWT libraries or middleware.
 * 
 * FOR DEVELOPMENT USE ONLY - NOT FOR PRODUCTION
 */

// Enable CORS for local development
if (!function_exists('apply_cors_headers')) {
    function apply_cors_headers() {
        // Define allowed origins
        $allowed_origins = [
            'http://localhost:3000',
            'http://localhost:5173',
            'http://localhost:8000',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:8000',
            'https://charterhub.yachtstory.com',
            'https://staging-charterhub.yachtstory.com'
        ];
        
        // Get the origin from the request headers
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        
        // Check if origin is allowed
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-HTTP-Method-Override");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Max-Age: 86400"); // 24 hours cache
            
            error_log("CORS headers applied for origin: $origin");
        } else if (empty($origin)) {
            // Default fallback if no origin is provided
            header("Access-Control-Allow-Origin: http://localhost:3000");
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-HTTP-Method-Override");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Max-Age: 86400"); // 24 hours cache
            error_log("CORS headers applied with default localhost:3000 (no origin provided)");
        } else {
            // Log unauthorized origin attempts
            error_log("CORS request rejected from non-allowed origin: " . $origin);
            // Don't set Access-Control-Allow-Origin header for non-allowed origins
        }
        
        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            error_log("OPTIONS preflight request handled with 200 response");
            exit;
        }
    }
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
?> 