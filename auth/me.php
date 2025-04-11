<?php
/**
 * CharterHub User Profile API Endpoint
 * 
 * This file returns the authenticated user's profile information.
 * Part of the JWT Authentication System Refactoring.
 * Version: 1.1.0 - Fixed database connection issues
 */

// Enable output buffering
ob_start();

// Force JSON response type - even before anything else
header('Content-Type: application/json');

// Prevent any PHP errors from being displayed directly
@ini_set('display_errors', 0);
error_reporting(0);

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include required files before anything else
require_once __DIR__ . '/../utils/database.php';  // Database abstraction layer first
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt-core.php';

// Include the global CORS handler
require_once __DIR__ . '/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Debug endpoint for database connection check
if (isset($_GET['debug']) && $_GET['debug'] === 'connection_test') {
    // Clear buffer
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Ensure JSON response
    header('Content-Type: application/json');
    
    // Re-enable error display for this debug endpoint
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        // Try database connection - use getDbConnection directly
        $conn = getDbConnection();
        
        if (!$conn) {
            echo json_encode([
                'success' => false,
                'message' => 'Could not establish database connection',
                'php_version' => PHP_VERSION,
                'server_time' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
        
        // Check if users table exists
        $tables_result = $conn->query("SHOW TABLES LIKE 'wp_charterhub_users'");
        $users_table_exists = ($tables_result && $tables_result->rowCount() > 0);
        
        // Get columns if table exists
        $user_columns = [];
        if ($users_table_exists) {
            $describe_result = $conn->query("DESCRIBE wp_charterhub_users");
            if ($describe_result) {
                while ($row = $describe_result->fetch(PDO::FETCH_ASSOC)) {
                    $user_columns[] = $row['Field'];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Database connection test',
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'users_table_exists' => $users_table_exists,
            'user_columns' => $user_columns,
            'functions_available' => [
                'fetchRow' => function_exists('fetchRow'),
                'fetchRows' => function_exists('fetchRows'),
                'executeQuery' => function_exists('executeQuery'),
                'getDbConnection' => function_exists('getDbConnection')
            ]
        ]);
        exit;
    } catch (Exception $e) {
        // Clear buffer
        if (ob_get_level()) {
            ob_clean();
        }
        
        echo json_encode([
            'success' => false,
            'message' => 'Error in database test',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'php_version' => PHP_VERSION
        ]);
        exit;
    }
}

// Include required files directly
require_once dirname(__FILE__) . '/token-blacklist.php';

header('Content-Type: application/json');

// Define helper functions
function json_response($data, $status = 200) {
    // Clear any existing output
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function error_response($message, $status = 400, $code = null) {
    // Clear any existing output
    if (ob_get_level()) {
        ob_clean();
    }
    
    http_response_code($status);
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => $message];
    if ($code) {
        $response['code'] = $code;
    }
    echo json_encode($response);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Options requests should be handled by apply_global_cors
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error_response('Method not allowed', 405);
}

// Get authenticated user
try {
    // Extract token from the Authorization header
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
        error_log("Error in me.php: No valid Authorization header found");
        error_response('No token provided', 401, 'token_missing');
    }
    
    // Extract the token
    $token = substr($auth_header, 7);
    
    // Validate the token and get payload
    $payload = validate_token($token);
    
    if (!$payload) {
        error_log("Error in me.php: Token validation failed");
        error_response('Invalid token', 401, 'invalid_token');
    }
    
    // Debug the payload structure
    error_log("ME.PHP - Payload type: " . gettype($payload));
    
    // Get the user ID from the payload
    $user_id = isset($payload->sub) ? $payload->sub : null;
    
    error_log("ME.PHP - User ID extracted: " . ($user_id ?? 'null'));
    
    if (!$user_id) {
        error_log("Error in me.php: No user ID in token");
        error_response('Invalid token - no user ID', 401, 'no_user_id');
    }
    
    try {
        // Get user from database with fetchRow for consistency
        $user = fetchRow(
            "SELECT id, email, first_name, last_name, phone_number, company, role, verified, token_version, created_at, last_login FROM wp_charterhub_users WHERE id = ?",
            [$user_id]
        );
        
        if (!$user) {
            error_log("Error in me.php: User ID $user_id not found in database");
            error_response('User not found', 401, 'user_not_found');
        }
        
        // Verify token version matches
        $token_version = isset($payload->tvr) ? $payload->tvr : null;
        if ($token_version !== null && $user['token_version'] != $token_version) {
            error_log("ME.PHP - Token version mismatch: token has {$token_version}, user has {$user['token_version']}");
            error_response('Token has been invalidated. Please login again.', 401, 'token_invalidated');
        }
        
        error_log("ME.PHP - Successfully retrieved data for user ID: " . $user['id']);
        
        // Format user data for response
        $formatted_user = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'firstName' => $user['first_name'],
            'lastName' => $user['last_name'],
            'phoneNumber' => $user['phone_number'] ?? '',
            'company' => $user['company'] ?? '',
            'role' => $user['role'],
            'verified' => (bool)$user['verified'],
            'createdAt' => $user['created_at'],
            'lastLogin' => $user['last_login']
        ];
        
        // Return the user data
        json_response([
            'success' => true,
            'user' => $formatted_user
        ]);
        
    } catch (Exception $db_e) {
        error_log("ME.PHP - Database error: " . $db_e->getMessage());
        error_response('Database error', 500, 'database_error');
    }
} catch (Exception $e) {
    error_log("ME.PHP - Unexpected error: " . $e->getMessage());
    error_response('Unexpected error', 500, 'server_error');
}

/**
 * Get permissions for a specific role
 * 
 * @param string $role User role
 * @return array Array of permissions
 */
function get_role_permissions($role) {
    $permissions = [];
    
    // Base permissions for all authenticated users
    $permissions = [
        'view_profile' => true,
        'edit_profile' => true,
        'view_bookings' => true
    ];
    
    // Add role-specific permissions
    switch ($role) {
        case 'admin':
            $permissions = array_merge($permissions, [
                'manage_users' => true,
                'manage_bookings' => true,
                'manage_vessels' => true,
                'manage_settings' => true,
                'view_reports' => true,
                'view_all_bookings' => true,
                'approve_bookings' => true,
                'cancel_bookings' => true
            ]);
            break;
            
        case 'client':
            $permissions = array_merge($permissions, [
                'create_bookings' => true,
                'cancel_own_bookings' => true,
                'view_own_bookings' => true
            ]);
            break;
    }
    
    return $permissions;
} 