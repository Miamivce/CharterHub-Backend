<?php
/**
 * CharterHub User Profile API Endpoint
 * 
 * This file returns the authenticated user's profile information.
 * Part of the JWT Authentication System Refactoring.
 */

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include the global CORS handler
require_once dirname(__FILE__) . '/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Include required files directly
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/jwt-core.php';
require_once dirname(__FILE__) . '/token-blacklist.php';
require_once dirname(__FILE__) . '/../utils/database.php';  // Include the database abstraction layer

header('Content-Type: application/json');

// Define helper functions
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function error_response($message, $status = 400, $code = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    $response = ['error' => true, 'message' => $message];
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
    
    // Debug the payload structure
    error_log("ME.PHP - Payload type: " . gettype($payload));
    error_log("ME.PHP - Payload structure: " . json_encode($payload, JSON_PRETTY_PRINT));
    
    // Check if validation returned an error - handle both array and object formats
    if (is_array($payload) && isset($payload['error']) && $payload['error'] === true) {
        error_log("Error in me.php: " . $payload['message']);
        error_response($payload['message'], 401, $payload['type']);
    }

    // Get the user ID from the payload - handle both object and array formats
    if (is_object($payload)) {
        $user_id = $payload->sub ?? null;
    } else {
        $user_id = $payload['sub'] ?? null;
    }
    
    error_log("ME.PHP - User ID extracted: " . ($user_id ?? 'null'));
    
    if (!$user_id) {
        error_log("Error in me.php: No user ID in token");
        error_response('Invalid token - no user ID', 401, 'no_user_id');
    }
    
    try {
        // Get user from database
        $user = fetchRow(
            "SELECT id, email, first_name, last_name, phone_number, company, role, verified, token_version, created_at, last_login FROM wp_charterhub_users WHERE id = ?",
            [$user_id]
        );
        
        if (!$user) {
            error_log("Error in me.php: User ID $user_id not found in database");
            error_response('User not found', 401, 'user_not_found');
        }
        
        // Verify token version matches - handle both object and array formats
        $token_version = is_object($payload) ? ($payload->tvr ?? null) : ($payload['tvr'] ?? null);
        if ($token_version !== null && $user['token_version'] != $token_version) {
            log_auth_action('me_endpoint_access_denied', $user_id, 'Token version mismatch', [
                'token_version' => $token_version,
                'current_version' => $user['token_version']
            ]);
            error_response('Token has been invalidated. Please login again.', 401, 'token_invalidated');
        }
        
        error_log("Getting additional user data for ID: " . $user['id']);
        
        // First get the basic user data - avoid complex query with JOIN that might fail
        try {
            $user_data = fetchRow("
                SELECT 
                    id,
                    email,
                    first_name,
                    last_name,
                    phone_number,
                    company,
                    role,
                    verified,
                    created_at,
                    last_login
                FROM 
                    wp_charterhub_users
                WHERE 
                    id = ?
                LIMIT 1
            ", [(int)$user['id']]);

            if (!$user_data) {
                log_auth_action('me_endpoint_error', $user['id'], 'Failed to retrieve user data');
                error_response('Failed to retrieve user data', 500, 'database_error');
            }

            // Then separately get the bookings count
            $bookings_count = 0; // Default value
            try {
                // Determine the correct column name first
                $charterer_column = 'main_charterer_id'; // Default
                try {
                    error_log("ME.PHP - Checking bookings table structure");
                    
                    // First, check if the bookings table exists
                    $tables_result = fetchRows("SHOW TABLES LIKE 'wp_charterhub_bookings'");
                    $table_exists = !empty($tables_result);
                    error_log("ME.PHP - Bookings table exists: " . ($table_exists ? "YES" : "NO"));
                    
                    if (!$table_exists) {
                        error_log("ME.PHP - wp_charterhub_bookings table not found");
                        throw new Exception("Bookings table not found in database");
                    }
                    
                    // Check table columns
                    $describe_result = fetchRows("DESCRIBE wp_charterhub_bookings");
                    if ($describe_result) {
                        $columns = array_column($describe_result, 'Field');
                        error_log("ME.PHP - Found columns: " . implode(", ", $columns));
                        
                        // Check if main_charterer_id or customer_id is used
                        if (in_array('main_charterer_id', $columns)) {
                            $charterer_column = 'main_charterer_id';
                            error_log("ME.PHP - Using column: main_charterer_id");
                        } elseif (in_array('customer_id', $columns)) {
                            $charterer_column = 'customer_id';
                            error_log("ME.PHP - Using column: customer_id");
                        } else {
                            error_log("ME.PHP - Neither main_charterer_id nor customer_id found, columns available: " . implode(", ", $columns));
                            throw new Exception("Required column not found in bookings table");
                        }
                    }
                } catch (Exception $col_e) {
                    error_log("ME.PHP - Error determining column name: " . $col_e->getMessage());
                    // Continue with default column name
                }
                
                error_log("ME.PHP - Using charterer column: " . $charterer_column);
                
                // Use the determined column name in the query
                $query = "SELECT COUNT(id) AS bookings_count FROM wp_charterhub_bookings WHERE $charterer_column = ?";
                error_log("ME.PHP - Bookings count query: " . $query);
                
                $booking_result = fetchRow($query, [(int)$user['id']]);
                
                if ($booking_result && isset($booking_result['bookings_count'])) {
                    $bookings_count = (int)$booking_result['bookings_count'];
                    error_log("ME.PHP - Found " . $bookings_count . " bookings for user ID " . $user['id']);
                } else {
                    error_log("ME.PHP - No bookings found or invalid result format");
                }
            } catch (Exception $e) {
                error_log("ME.PHP - Error fetching bookings count: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
                error_log("ME.PHP - Stack trace: " . $e->getTraceAsString());
                // Continue with zero as the count - non-fatal error
            }

            // Format user data
            $response = [
                'id' => (int)$user_data['id'],
                'email' => $user_data['email'],
                'first_name' => $user_data['first_name'],
                'last_name' => $user_data['last_name'],
                'full_name' => trim($user_data['first_name'] . ' ' . $user_data['last_name']),
                'phone_number' => $user_data['phone_number'] ?? '',
                'company' => $user_data['company'] ?? '',
                'role' => $user_data['role'],
                'verified' => (bool)$user_data['verified'],
                'created_at' => $user_data['created_at'],
                'last_login' => $user_data['last_login'],
                'bookings_count' => $bookings_count,
                'permissions' => get_role_permissions($user_data['role'])
            ];

            // Log the access
            log_auth_action('me_endpoint_access', $user['id'], 'User accessed profile data');

            // Return user data
            json_response([
                'success' => true,
                'user' => $response
            ]);
        } catch (Exception $e) {
            error_log("Error in me.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            log_auth_action('me_endpoint_error', $user['id'], 'Database error: ' . $e->getMessage());
            error_response('Failed to retrieve user data: ' . $e->getMessage(), 500, 'database_error');
        }
    } catch (Exception $e) {
        error_log("Error in me.php authentication: " . $e->getMessage());
        error_response('Authentication error: ' . $e->getMessage(), 401, 'auth_error');
    }
} catch (Exception $e) {
    error_log("Error in me.php authentication: " . $e->getMessage());
    error_response('Authentication error: ' . $e->getMessage(), 401, 'auth_error');
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