<?php
/**
 * CharterHub Authentication Configuration
 * 
 * This file contains configuration settings for the authentication system.
 * Updated to use environment variables for sensitive information.
 */

// Define the CHARTERHUB_LOADED constant to prevent direct access to included files
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Development mode flag - from environment or default to false
define('DEVELOPMENT_MODE', getenv('DEVELOPMENT_MODE') === 'true' ? true : false);

// Include required dependencies
require_once __DIR__ . '/../db-config.php';

// Authentication settings
$auth_config = [
    // JWT Configuration
    'jwt_secret' => getenv('JWT_SECRET') ?: 'charterhub_jwt_secret_key_change_in_production',
    'jwt_algorithm' => getenv('JWT_ALGORITHM') ?: 'HS256',
    'jwt_expiration' => getenv('JWT_EXPIRY_MINUTES') ? (int)getenv('JWT_EXPIRY_MINUTES') * 60 : 1800, // Default: 30 minutes
    'refresh_expiration' => getenv('REFRESH_TOKEN_EXPIRY_DAYS') ? (int)getenv('REFRESH_TOKEN_EXPIRY_DAYS') * 86400 : 604800, // Default: 7 days
    'token_blacklist_cleanup_days' => 30, // Days to keep expired tokens in blacklist

    // Password and Security Settings
    'password_min_length' => 8,
    'password_require_special' => true,
    'password_require_uppercase' => true,
    'password_require_number' => true,

    // Rate Limiting
    'max_login_attempts' => 5,
    'lockout_time' => 30, // minutes

    // Account Management
    'invitation_expiration' => 7, // days
    'verification_expiration' => 48, // hours
    
    // Development Mode
    'dev_mode' => DEVELOPMENT_MODE,
];

// Frontend configuration
$frontend_config = get_frontend_config();

// Extract auth config values into global variables for easier access
$jwt_secret = $auth_config['jwt_secret'];
$jwt_algorithm = $auth_config['jwt_algorithm'];
$jwt_expiration = $auth_config['jwt_expiration'];
$refresh_expiration = $auth_config['refresh_expiration'];

// Email configuration
$email_config = [
    'from_email' => getenv('EMAIL_FROM') ?: 'noreply@charterhub.com',
    'from_name' => getenv('EMAIL_NAME') ?: 'CharterHub',
    'smtp_host' => getenv('SMTP_HOST') ?: '', // Configure in production
    'smtp_port' => getenv('SMTP_PORT') ?: 587,
    'smtp_username' => getenv('SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'tls',
];

/**
 * Get database connection
 * 
 * @return PDO A database connection
 * @deprecated Use get_db_connection_from_config() from db-config.php instead
 */
function get_db_connection() {
    error_log("WARNING: Using deprecated get_db_connection() function. Use get_db_connection_from_config() instead.");
    return get_db_connection_from_config();
}

/**
 * Determine frontend configuration based on request origin
 * 
 * @return array Frontend configuration
 */
function get_frontend_config() {
    $frontend_port = 3000; // Default port as fallback
    $frontend_base = 'http://localhost';
    
    // Try to detect port from HTTP_ORIGIN
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin_parts = parse_url($_SERVER['HTTP_ORIGIN']);
        if (isset($origin_parts['port'])) {
            $frontend_port = $origin_parts['port'];
        }
        if (isset($origin_parts['scheme']) && isset($origin_parts['host'])) {
            $frontend_base = $origin_parts['scheme'] . '://' . $origin_parts['host'];
        }
    }
    
    // Frontend URLs with dynamic detection
    return [
        'base_url' => (isset($origin_parts['port'])) ? 
            "{$frontend_base}:{$frontend_port}" : $frontend_base,
        'login_url' => (isset($origin_parts['port'])) ? 
            "{$frontend_base}:{$frontend_port}/login" : "{$frontend_base}/login",
        'verification_url' => (isset($origin_parts['port'])) ? 
            "{$frontend_base}:{$frontend_port}/verify-email" : "{$frontend_base}/verify-email",
        'password_reset_url' => (isset($origin_parts['port'])) ? 
            "{$frontend_base}:{$frontend_port}/reset-password" : "{$frontend_base}/reset-password",
    ];
}

/**
 * Log authentication related actions for auditing and security monitoring
 * 
 * @param mixed $user_id The user ID or action type if no user is involved
 * @param string $action The action being performed or status if user_id is an action
 * @param string $status The status of the action (success, failure, etc.)
 * @param array $details Additional details about the action
 * @return bool Whether the action was successfully logged
 */
if (!function_exists('log_auth_action')) {
    function log_auth_action($user_id, $action, $status, $details = []) {
        try {
            $pdo = get_db_connection_from_config();
            
            // Create logs table if it doesn't exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS wp_charterhub_auth_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(50) NOT NULL,
                action VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (action),
                INDEX (status),
                INDEX (ip_address),
                INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            
            // Handle string action types for backward compatibility
            if (is_string($user_id) && !is_numeric($user_id)) {
                // When the first parameter is a string action type, shift parameters
                $details = $status;
                $status = $action;
                $action = $user_id;
                $user_id = 0; // Use 0 for system actions
            }
            
            // Prepare log data
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $details_json = !empty($details) ? json_encode($details) : null;
            
            // Insert log entry
            $stmt = $pdo->prepare("INSERT INTO wp_charterhub_auth_logs 
                                  (user_id, action, status, details, ip_address, user_agent) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $user_id,
                $action,
                $status,
                $details_json,
                $ip_address,
                $user_agent
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to log authentication action: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Generate a secure random token
 * 
 * @param int $length The length of the token
 * @return string The generated token
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Send an email
 * 
 * @param string $to The recipient's email address
 * @param string $subject The email subject
 * @param string $body The email body
 * @return bool Whether the email was sent successfully
 */
function send_email($to, $subject, $body) {
    global $email_config;
    
    // Simple mail function for development
    if (DEVELOPMENT_MODE) {
        error_log("DEV MODE - Email would be sent to: $to");
        error_log("Subject: $subject");
        error_log("Body: " . substr($body, 0, 100) . "...");
        return mail($to, $subject, $body);
    }
    
    // In production, implement proper SMTP mail sending
    // using PHPMailer or similar library
    return mail($to, $subject, $body);
}

/**
 * Validate password strength
 * 
 * @param string $password The password to validate
 * @return array Result with 'valid' and 'message' keys
 */
function validate_password($password) {
    global $auth_config;
    
    $result = [
        'valid' => true,
        'message' => 'Password is valid'
    ];
    
    // Check length
    if (strlen($password) < $auth_config['password_min_length']) {
        $result['valid'] = false;
        $result['message'] = "Password must be at least {$auth_config['password_min_length']} characters";
        return $result;
    }
    
    // Check for uppercase if required
    if ($auth_config['password_require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
        $result['valid'] = false;
        $result['message'] = "Password must contain at least one uppercase letter";
        return $result;
    }
    
    // Check for number if required
    if ($auth_config['password_require_number'] && !preg_match('/[0-9]/', $password)) {
        $result['valid'] = false;
        $result['message'] = "Password must contain at least one number";
        return $result;
    }
    
    // Check for special character if required
    if ($auth_config['password_require_special'] && !preg_match('/[^a-zA-Z0-9]/', $password)) {
        $result['valid'] = false;
        $result['message'] = "Password must contain at least one special character";
        return $result;
    }
    
    return $result;
}

// -------------------------------------------
// COMPATIBILITY FUNCTIONS (LEGACY SUPPORT)
// -------------------------------------------

/**
 * Verify JWT token (compatibility function)
 * 
 * @deprecated Use functions from jwt-core.php instead
 */
if (!function_exists('verify_jwt_token')) {
    function verify_jwt_token($jwt, $allow_expired = false) {
        require_once __DIR__ . '/jwt-core.php';
        
        error_log("WARNING: Using deprecated verify_jwt_token() function from config.php.");
        
        $validation = validate_jwt_token($jwt, $allow_expired);
        
        if (!$validation['valid']) {
            throw new Exception('Token validation failed: ' . $validation['error']);
        }
        
        return (object)$validation['payload'];
    }
}

/**
 * Generate JWT token (compatibility function)
 * 
 * @deprecated Use functions from jwt-core.php instead
 */
if (!function_exists('generate_jwt_token')) {
    function generate_jwt_token($user_data, $expiration_time = null) {
        require_once __DIR__ . '/jwt-core.php';
        
        error_log("WARNING: Using deprecated generate_jwt_token() function.");
        
        $token_data = generate_jwt_token_core($user_data, $expiration_time);
        return $token_data['token'];
    }
}

/**
 * Check rate limiting for a specific action
 * 
 * @param string $ip_address The IP address to check
 * @param string $action The action being performed
 * @return array Status with 'allowed', 'remaining_attempts', and 'lockout_time' keys
 */
function get_rate_limit_status($ip_address, $action = 'login') {
    global $auth_config;
    
    try {
        $pdo = get_db_connection_from_config();
        
        // Ensure rate limiting table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS wp_charterhub_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            action VARCHAR(50) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 1,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            lockout_until TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY unique_ip_action (ip_address, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        
        // Get current rate limit status
        $stmt = $pdo->prepare("SELECT * FROM wp_charterhub_rate_limits 
                              WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip_address, $action]);
        $rate_limit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $max_attempts = $auth_config['max_login_attempts'];
        $lockout_time = $auth_config['lockout_time'] * 60; // Convert to seconds
        
        // No previous attempts
        if (!$rate_limit) {
            return [
                'allowed' => true,
                'remaining_attempts' => $max_attempts - 1,
                'lockout_time' => 0
            ];
        }
        
        // Check if currently locked out
        if ($rate_limit['lockout_until'] && strtotime($rate_limit['lockout_until']) > time()) {
            $remaining_lockout = strtotime($rate_limit['lockout_until']) - time();
            return [
                'allowed' => false,
                'remaining_attempts' => 0,
                'lockout_time' => $remaining_lockout
            ];
        }
        
        // If lockout expired, reset attempt count
        if ($rate_limit['lockout_until'] && strtotime($rate_limit['lockout_until']) <= time()) {
            $update_stmt = $pdo->prepare("UPDATE wp_charterhub_rate_limits 
                                         SET attempt_count = 1, lockout_until = NULL 
                                         WHERE ip_address = ? AND action = ?");
            $update_stmt->execute([$ip_address, $action]);
            
            return [
                'allowed' => true,
                'remaining_attempts' => $max_attempts - 1,
                'lockout_time' => 0
            ];
        }
        
        // Check if attempts exceed maximum
        if ($rate_limit['attempt_count'] >= $max_attempts) {
            // Set lockout period
            $lockout_until = date('Y-m-d H:i:s', time() + $lockout_time);
            $update_stmt = $pdo->prepare("UPDATE wp_charterhub_rate_limits 
                                         SET lockout_until = ? 
                                         WHERE ip_address = ? AND action = ?");
            $update_stmt->execute([$lockout_until, $ip_address, $action]);
            
            return [
                'allowed' => false,
                'remaining_attempts' => 0,
                'lockout_time' => $lockout_time
            ];
        }
        
        // Within allowed attempts
        return [
            'allowed' => true,
            'remaining_attempts' => $max_attempts - $rate_limit['attempt_count'],
            'lockout_time' => 0
        ];
    } catch (Exception $e) {
        error_log("Error checking rate limit: " . $e->getMessage());
        
        // Default to allowing the request if there's an error
        return [
            'allowed' => true,
            'remaining_attempts' => $auth_config['max_login_attempts'],
            'lockout_time' => 0
        ];
    }
}

/**
 * Reset rate limiting for an IP address
 * 
 * @param string $ip_address The IP address to reset
 * @param string $action The action to reset
 * @return bool Whether the reset was successful
 */
function reset_rate_limiting($ip_address, $action = 'login') {
    try {
        $pdo = get_db_connection_from_config();
        
        $stmt = $pdo->prepare("DELETE FROM wp_charterhub_rate_limits 
                              WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip_address, $action]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error resetting rate limit: " . $e->getMessage());
        return false;
    }
} 