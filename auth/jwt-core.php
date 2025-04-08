<?php
/**
 * JWT Core Functionality
 * 
 * This file centralizes all JWT validation logic for the CharterHub application.
 * It handles token extraction, validation, payload parsing, and comprehensive error logging.
 * 
 * Part of the JWT Authentication System Refactoring
 * 
 * @package CharterHub
 * @subpackage Authentication
 */

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../utils/database.php';  // Include the database abstraction layer
require_once __DIR__ . '/token-blacklist.php';

// Always include the Composer autoloader if it exists
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Import Firebase JWT classes
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use \Firebase\JWT\ExpiredException;
use \Firebase\JWT\SignatureInvalidException;
use \Firebase\JWT\BeforeValidException;

/**
 * Generate a new JWT access token
 * 
 * @param int $user_id User ID
 * @param string $email User email
 * @param string $role User role (client, admin)
 * @param int $token_version User's token version for invalidation
 * @return string JWT token
 */
function generate_access_token($user_id, $email, $role, $token_version) {
    global $jwt_secret, $jwt_algorithm, $jwt_expiration;
    
    // Make sure jwt_expiration has a valid value, default to 30 minutes if not set
    if (!isset($jwt_expiration) || !is_numeric($jwt_expiration) || $jwt_expiration <= 0) {
        $jwt_expiration = 1800; // 30 minutes
        error_log("WARNING: Invalid or missing jwt_expiration value, using default of 1800 seconds");
    }
    
    $issued_at = time();
    $expiration = $issued_at + $jwt_expiration;
    $token_id = bin2hex(random_bytes(16)); // Generate a unique token ID
    
    // Double-check that expiration is actually in the future
    if ($expiration <= $issued_at) {
        error_log("WARNING: Token expiration time not in the future, forcing to issued_at + 1800");
        $expiration = $issued_at + 1800; // Force to 30 minutes if something went wrong
    }
    
    $payload = [
        'iss' => 'charterhub', // Issuer
        'aud' => 'charterhub-app', // Audience
        'iat' => $issued_at, // Issued at
        'exp' => $expiration, // Expiration
        'jti' => $token_id, // JWT ID (unique)
        'sub' => $user_id, // Subject (user ID)
        'email' => $email,
        'role' => $role,
        'tvr' => $token_version // For invalidating all tokens (using tvr key for consistency)
    ];
    
    try {
        $jwt = JWT::encode($payload, $jwt_secret, $jwt_algorithm);
        
        log_auth_action('token_generated', $user_id, 'Generated access token', ['token_id' => $token_id, 'type' => 'access']);
        return $jwt;
    } catch (Exception $e) {
        log_auth_action('token_error', $user_id, 'Failed to generate access token: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate a new JWT refresh token
 * 
 * @param int $user_id User ID
 * @param string $email User email
 * @param string $role User role (client, admin)
 * @param int $token_version User's token version for invalidation
 * @return array Array containing the token and its ID
 */
function generate_refresh_token($user_id, $email, $role, $token_version) {
    global $jwt_secret, $jwt_algorithm, $refresh_expiration;
    
    // Make sure refresh_expiration has a valid value, default to 7 days if not set
    if (!isset($refresh_expiration) || !is_numeric($refresh_expiration) || $refresh_expiration <= 0) {
        $refresh_expiration = 604800; // 7 days
        error_log("WARNING: Invalid or missing refresh_expiration value, using default of 604800 seconds");
    }
    
    $issued_at = time();
    $expiration = $issued_at + $refresh_expiration;
    $token_id = bin2hex(random_bytes(16)); // Generate a unique token ID
    
    // Double-check that expiration is actually in the future
    if ($expiration <= $issued_at) {
        error_log("WARNING: Refresh token expiration time not in the future, forcing to issued_at + 604800");
        $expiration = $issued_at + 604800; // Force to 7 days if something went wrong
    }
    
    $payload = [
        'iss' => 'charterhub', // Issuer
        'aud' => 'charterhub-refresh', // Audience
        'iat' => $issued_at, // Issued at
        'exp' => $expiration, // Expiration
        'jti' => $token_id, // JWT ID (unique)
        'sub' => $user_id, // Subject (user ID)
        'email' => $email,
        'role' => $role,
        'token_version' => $token_version, // For invalidating all tokens
        'type' => 'refresh'
    ];
    
    try {
        $jwt = JWT::encode($payload, $jwt_secret, $jwt_algorithm);
        
        // Store refresh token in database
        $conn = get_db_connection_from_config();
        $stmt = $conn->prepare("INSERT INTO wp_charterhub_jwt_tokens (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at, created_at, revoked, last_used_at) 
                              VALUES (?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), NOW(), 0, NOW())");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $token_id, PDO::PARAM_STR);
        $stmt->bindParam(3, $token_id, PDO::PARAM_STR);
        $stmt->bindParam(4, $expiration, PDO::PARAM_INT);
        $stmt->bindParam(5, $expiration, PDO::PARAM_INT);
        $stmt->execute();

        log_auth_action('token_generated', $user_id, 'Generated refresh token', ['token_id' => $token_id, 'type' => 'refresh']);
        
        return [
            'token' => $jwt,
            'token_id' => $token_id,
            'expires' => $expiration
        ];
    } catch (Exception $e) {
        log_auth_action('token_error', $user_id, 'Failed to generate refresh token: ' . $e->getMessage());
        return false;
    }
}

/**
 * Extract JWT token from Authorization header
 * 
 * @return string|false The JWT token or false if not found
 */
function extract_token_from_header() {
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : 
                  (isset($headers['authorization']) ? $headers['authorization'] : null);
    
    if (!$auth_header) {
        return false;
    }
    
    // Check if it's a Bearer token
    if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        return $matches[1];
    }
    
    return false;
}

/**
 * Extract JWT token from request (checks header, then GET, then POST)
 * 
 * @return string|false The JWT token or false if not found
 */
function extract_token() {
    // First try Authorization header
    $token = extract_token_from_header();
    if ($token) {
        return $token;
    }
    
    // Then try GET parameter
    if (isset($_GET['token'])) {
        return $_GET['token'];
    }
    
    // Finally try POST parameter
    if (isset($_POST['token'])) {
        return $_POST['token'];
    }
    
    return false;
}

/**
 * Validate a JWT token
 * 
 * @param string $token JWT token
 * @param bool $check_blacklist Whether to check token blacklist
 * @return object|array Returns payload if token is valid, or error array if invalid
 */
function validate_token($token, $check_blacklist = true) {
    global $jwt_secret, $jwt_algorithm;
    
    // Debug logging to check the JWT parameters
    error_log("JWT validation - Secret defined: " . (isset($jwt_secret) ? "YES" : "NO"));
    error_log("JWT validation - Algorithm defined: " . (isset($jwt_algorithm) ? "YES" : "NO"));
    error_log("JWT validation - Secret value: " . substr($jwt_secret ?? 'null', 0, 5) . "...");
    error_log("JWT validation - Algorithm value: " . ($jwt_algorithm ?? 'null'));
    
    if (!$token) {
        return [
            'error' => true,
            'type' => 'token_missing',
            'message' => 'No token provided'
        ];
    }
    
    // Safely check if JWT parameters are defined
    if (!isset($jwt_secret) || !isset($jwt_algorithm)) {
        error_log("JWT validation error: Secret or algorithm not defined");
        return [
            'error' => true,
            'type' => 'server_config_error',
            'message' => 'JWT configuration error'
        ];
    }
    
    try {
        // First check if token is in the blacklist
        if ($check_blacklist && is_token_blacklisted($token)) {
            return [
                'error' => true,
                'type' => 'token_revoked',
                'message' => 'Token has been revoked'
            ];
        }
        
        // Verify token has three parts (header, payload, signature)
        $tokenParts = explode('.', $token);
        if (count($tokenParts) !== 3) {
            return [
                'error' => true,
                'type' => 'token_invalid',
                'message' => 'Invalid token format'
            ];
        }
        
        // Decode the token with strict algorithm checking
        $decoded = JWT::decode($token, new Key($jwt_secret, $jwt_algorithm));
        
        // Check for required claims
        if (!isset($decoded->sub) || !isset($decoded->jti) || !isset($decoded->role)) {
            return [
                'error' => true,
                'type' => 'token_invalid',
                'message' => 'Token missing required claims'
            ];
        }
        
        // Check user token version if provided
        if (isset($decoded->token_version)) {
            $current_token_version = get_user_token_version($decoded->sub);
            if ($current_token_version !== null && $decoded->token_version < $current_token_version) {
                return [
                    'error' => true,
                    'type' => 'token_version_invalid',
                    'message' => 'Token version is invalid (user changed password or logged out on all devices)'
                ];
            }
        }
        
        return $decoded;
    } catch (Exception $e) {
        $error_type = 'token_invalid';
        $error_message = 'Token validation error: ' . $e->getMessage();
        
        if ($e instanceof ExpiredException) {
            $error_type = 'token_expired';
            $error_message = 'Token expired: ' . $e->getMessage();
        } else if ($e instanceof SignatureInvalidException) {
            $error_type = 'token_invalid';
            $error_message = 'Invalid token signature: ' . $e->getMessage();
        } else if ($e instanceof BeforeValidException) {
            $error_type = 'token_invalid';
            $error_message = 'Token not yet valid: ' . $e->getMessage();
        }
        
        log_auth_error($error_type, $error_message);
        
        return [
            'error' => true,
            'type' => $error_type,
            'message' => $error_message
        ];
    }
}

/**
 * Verify a JWT token (wrapper for validate_token for compatibility)
 * 
 * @param string $token JWT token
 * @param bool $allow_expired Whether to allow expired tokens
 * @return object|false Decoded token payload or false on failure
 */
function verify_token($token, $allow_expired = false) {
    $result = validate_token($token, !$allow_expired);
    
    // If result is an array with error key, return false
    if (is_array($result) && isset($result['error'])) {
        return false;
    }
    
    return $result;
}

/**
 * Check if user has required role
 * 
 * @param object $token_payload Decoded token payload
 * @param string|array $required_roles Required role(s)
 * @return bool True if user has required role
 */
function has_role($token_payload, $required_roles) {
    if (!$token_payload || !isset($token_payload->role)) {
        return false;
    }
    
    if (is_array($required_roles)) {
        return in_array($token_payload->role, $required_roles);
    } else {
        return $token_payload->role === $required_roles;
    }
}

/**
 * Get the authenticated user from the JWT token
 * 
 * @param bool $require_auth Whether to require authentication
 * @param array|string|null $required_roles Required role(s) for the user
 * @return array|false User data or false
 */
function get_authenticated_user($require_auth = true, $required_roles = null) {
    $token = extract_token();
    
    if (!$token) {
        if ($require_auth) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        return false;
    }
    
    $payload = validate_token($token);
    
    if (!$payload) {
        if ($require_auth) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
            exit;
        }
        return false;
    }
    
    if ($required_roles && !has_role($payload, $required_roles)) {
        if ($require_auth) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }
        return false;
    }
    
    try {
        error_log("Getting user data for ID: " . $payload->sub);
        
        // Use simple query to avoid complex JOIN operations which might fail
        $user = fetchRow(
            "SELECT id, email, first_name, last_name, role, token_version FROM wp_charterhub_users WHERE id = ? LIMIT 1",
            [(int)$payload->sub]
        );
        
        if (!$user) {
            error_log("User not found for ID: " . $payload->sub);
            if ($require_auth) {
                http_response_code(401);
                echo json_encode(['error' => 'User not found']);
                exit;
            }
            return false;
        }
        
        // Verify token version if token versioning is enabled
        if (isset($payload->tvr) && $user['token_version'] != $payload->tvr) {
            error_log("Token version mismatch: expected " . $user['token_version'] . ", got " . $payload->tvr);
            if ($require_auth) {
                http_response_code(401);
                echo json_encode(['error' => 'Token has been revoked. Please login again.']);
                exit;
            }
            return false;
        }
        
        error_log("Successfully retrieved user data for ID: " . $payload->sub);
        return $user;
    } catch (Exception $e) {
        error_log("Database error in get_authenticated_user: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        if ($require_auth) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
            exit;
        }
        return false;
    }
}

/**
 * Set refresh token as HTTP-only cookie
 * 
 * @param string $token Refresh token
 * @param int $expires Expiration timestamp
 * @return void
 */
function set_refresh_token_cookie($token, $expires) {
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $domain = ''; // Set to your domain if needed
    
    // Check if token is an array (from generate_refresh_token) or a string
    $token_value = is_array($token) ? $token['token'] : $token;
    $token_expires = is_array($token) && isset($token['expires']) ? $token['expires'] : $expires;
    
    // Set HTTP-only cookie
    setcookie(
        'refresh_token',
        $token_value,
        [
            'expires' => $token_expires,
            'path' => '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );
}

/**
 * Clear refresh token cookie
 * 
 * @return void
 */
function clear_refresh_token_cookie() {
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $domain = ''; // Set to your domain if needed
    
    // Expire the cookie
    setcookie(
        'refresh_token',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );
}

/**
 * Get refresh token from cookie
 * 
 * @return string|false Refresh token or false if not found
 */
function get_refresh_token_from_cookie() {
    return isset($_COOKIE['refresh_token']) ? $_COOKIE['refresh_token'] : false;
}

/**
 * Invalidate all tokens for a user by incrementing their token version
 * 
 * @param int $user_id User ID
 * @return bool True on success
 */
function invalidate_all_user_tokens($user_id) {
    if (!$user_id) {
        return false;
    }
    
    try {
        // Begin transaction
        beginTransaction();
        
        // Get current token version
        $user = fetchRow(
            "SELECT token_version FROM wp_charterhub_users WHERE id = ?",
            [$user_id]
        );
        
        if (!$user) {
            rollbackTransaction();
            return false;
        }
        
        // Increment token version
        $new_version = isset($user['token_version']) ? $user['token_version'] + 1 : 1;
        
        // Update token version
        $updated = executeUpdate(
            "UPDATE wp_charterhub_users SET token_version = ? WHERE id = ?",
            [$new_version, $user_id]
        );
        
        if ($updated) {
            commitTransaction();
            log_auth_action('tokens_invalidated', $user_id, 'All tokens invalidated for user');
            return true;
        } else {
            rollbackTransaction();
            return false;
        }
    } catch (Exception $e) {
        rollbackTransaction();
        log_auth_action('token_error', $user_id, 'Failed to invalidate tokens: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get standardized error response
 * 
 * @param string $message Error message
 * @param int $code HTTP status code
 * @param array $details Additional error details
 * @return array Error response array
 */
function get_auth_error($message, $code = 401, $details = []) {
    return [
        'error' => true,
        'message' => $message,
        'code' => $code,
        'details' => $details
    ];
}

// Function to log auth actions for auditing
if (!function_exists('log_auth_action')) {
    function log_auth_action($param1, $param2 = null, $param3 = null, $param4 = null) {
        try {
            // Check if the required function exists in the config file
            if (function_exists('log_auth_action')) {
                // Call the implementation from config.php
                return log_auth_action($param1, $param2, $param3, $param4);
            }
            
            // Legacy implementation for backward compatibility
            $action = $param1;
            $user_id = $param2;
            $description = $param3;
            $additional_data = $param4;
            
            // If first param is a string action type and not numeric, use 0 as user_id
            if (is_string($param1) && !is_numeric($param1)) {
                $action = $param1;
                $description = $param2;
                $additional_data = $param3;
                $user_id = 0; // Default user ID for system actions
            }
            // If first parameter is numeric and second is a string, assume format ($user_id, $action, ...)
            else if (is_numeric($param1) && is_string($param2)) {
                $user_id = $param1;
                $action = $param2;
                $description = $param3;
                $additional_data = $param4;
            }
            
            // Simple logging to error_log for now
            $log_message = "AUTH LOG: [$action] User: $user_id - $description";
            if ($additional_data) {
                $log_message .= " - Details: " . json_encode($additional_data);
            }
            error_log($log_message);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to log authentication action: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get user's current token version for validation
 * 
 * @param int $user_id The user ID to check
 * @return int|null The user's token version or null if user not found
 */
function get_user_token_version($user_id) {
    if (!$user_id || !is_numeric($user_id)) {
        return null;
    }
    
    try {
        $row = fetchRow(
            "SELECT token_version FROM wp_charterhub_users WHERE id = ?",
            [$user_id]
        );
        
        if ($row && isset($row['token_version'])) {
            return (int) $row['token_version'];
        }
    } catch (Exception $e) {
        log_auth_error('db_error', 'Failed to get user token version: ' . $e->getMessage());
    }
    
    return null;
}

/**
 * Log authentication errors
 * 
 * @param string $error_type The type of error
 * @param string $message The error message
 */
function log_auth_error($error_type, $message) {
    error_log("[" . date('Y-m-d H:i:s') . "] AUTH ERROR: $error_type - $message");
} 