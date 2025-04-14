<?php
/**
 * JWT Authentication Helper
 * 
 * This file provides JWT authentication functions for API endpoints
 * Version: 1.1.0 - Updated with PDO connection for Render.com
 */

// Include core JWT functionality
require_once __DIR__ . '/jwt-core.php';

// Ensure database utilities are included
require_once __DIR__ . '/../utils/database.php';

/**
 * Verify JWT token from Authorization header
 * 
 * @return array|bool User data if token is valid, false otherwise
 */
// Check if the function is already defined to prevent redeclaration errors
if (!function_exists('verify_jwt_token')) {
function verify_jwt_token() {
    // Get JWT token from Authorization header
    $headers = getallheaders();
    error_log("JWT-AUTH: Headers received: " . json_encode(array_keys($headers)));
    
    $auth_header = null;
    
    // Try multiple header formats for better compatibility
    $header_names = ['Authorization', 'authorization', 'HTTP_AUTHORIZATION'];
    foreach ($header_names as $name) {
        if (isset($headers[$name]) && !empty($headers[$name])) {
            $auth_header = $headers[$name];
            error_log("JWT-AUTH: Found token in header: " . $name);
            break;
        }
    }
    
    // If still not found, check if passed as a GET parameter (not recommended, but good for testing)
    if (empty($auth_header) && isset($_GET['token'])) {
        $auth_header = 'Bearer ' . $_GET['token'];
        error_log("JWT-AUTH: Using token from GET parameter (not secure for production!)");
    }
    
    error_log("JWT-AUTH: Authorization header: " . (empty($auth_header) ? "Not found" : substr($auth_header, 0, 20) . "..."));
    
    // Check if Authorization header exists and contains Bearer token
    if (empty($auth_header) || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        error_log("JWT Authentication Failed: No valid Authorization header");
        return false;
    }
    
    $jwt = $matches[1];
    error_log("JWT-AUTH: Token extracted, length: " . strlen($jwt));
    
    if (strlen($jwt) < 10) {
        error_log("JWT-AUTH: Token too short, likely invalid");
        return false;
    }
    
    try {
        // Verify token using core function
        $payload = verify_token($jwt);
        
        if (!$payload) {
            error_log("JWT Authentication Failed: Invalid token");
            return false;
        }
        
        // Extract user data from payload
        $user = [
            'id' => $payload->sub,
            'email' => $payload->email,
            'role' => $payload->role,
        ];
        
        error_log("JWT-AUTH: Authentication successful for user ID: " . $user['id']);
        
        // Validate that the user actually exists in the database
        try {
            $conn = getDbConnection();
            if ($conn) {
                // Look for users table with or without prefix
                $users_table = 'wp_charterhub_users'; // Default
                $tables_result = $conn->query("SHOW TABLES");
                
                if ($tables_result) {
                    while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
                        if (stripos($row[0], 'charterhub_users') !== false) {
                            $users_table = $row[0];
                            break;
                        }
                    }
                }
                
                $stmt = $conn->prepare("SELECT id FROM " . $users_table . " WHERE id = ? LIMIT 1");
                $stmt->execute([$user['id']]);
                
                if ($stmt->rowCount() == 0) {
                    error_log("JWT-AUTH: User ID " . $user['id'] . " from token not found in database");
                    return false;
                }
            }
        } catch (Exception $e) {
            error_log("JWT-AUTH: Error checking user in database: " . $e->getMessage());
            // Continue even if we can't validate against the database
        }
        
        return $user;
    } catch (Exception $e) {
        error_log("JWT Authentication Failed: " . $e->getMessage());
        return false;
    }
}
}

/**
 * Verify that user has admin role
 * 
 * @param array $user User data from verify_jwt_token
 * @return bool True if user is admin, false otherwise
 */
function is_admin_user($user) {
    if (!$user || !isset($user['role'])) {
        return false;
    }
    
    $role = strtolower($user['role']);
    return ($role === 'admin' || $role === 'administrator');
}

/**
 * Send JSON response
 * 
 * @param array $data Response data
 * @param int $status HTTP status code
 */
function json_response($data, $status = 200) {
    // Clear any existing output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers
    header('Content-Type: application/json');
    http_response_code($status);
    
    // Handle JSON encoding errors
    try {
        // Encode data with error handling
        $json = json_encode($data, JSON_PRETTY_PRINT);
        
        // Check for JSON encoding errors
        if ($json === false) {
            $json_error = json_last_error_msg();
            error_log("JSON encoding error: " . $json_error);
            
            // Provide a sanitized response
            echo json_encode([
                'success' => false,
                'message' => 'Error encoding response',
                'error' => 'json_encode_error',
                'error_message' => $json_error
            ], JSON_PRETTY_PRINT);
        } else {
            // Output successful JSON
            echo $json;
        }
    } catch (Exception $e) {
        // Fallback for any other errors
        error_log("Exception in json_response: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error while generating response',
            'error' => 'response_generation_error'
        ], JSON_PRETTY_PRINT);
    }
    
    exit;
}

/**
 * Get database connection - Updated for cloud deployment
 * 
 * @return PDO Database connection
 */
function get_database_connection() {
    try {
        // Call the getDbConnection function directly
        error_log("JWT-AUTH: Creating database connection using utils/database.php");
        $conn = getDbConnection();
        error_log("JWT-AUTH: Database connection created successfully");
        return $conn;
    } catch (Exception $e) {
        error_log("JWT-AUTH: Database connection failed: " . $e->getMessage());
        json_response([
            'success' => false,
            'message' => 'Database connection error: ' . $e->getMessage()
        ], 500);
        exit;
    }
} 