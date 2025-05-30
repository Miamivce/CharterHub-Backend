/**
 * IMPORTANT: This file is now ONLY used for WordPress admin authentication.
 * It should NOT be used for client authentication which uses the JWT system.
 * The wp_user_id field has been removed from wp_charterhub_users table.
 */
<?php
/**
 * Admin Authentication Handler
 * 
 * This file provides a simple and secure way to authenticate WordPress administrators
 * using WordPress cookies. It does not use JWT tokens for admin authentication.
 */

// Don't allow direct access
if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access not allowed');
}

// Include required files
if (!function_exists('get_db_connection')) {
    include_once __DIR__ . '/config.php';
}

/**
 * Check if the current request is authenticated as a WordPress administrator
 * 
 * @return object|false The authenticated admin user data or false if not authenticated
 */
function check_admin_auth() {
    // Look for WordPress admin cookie
    $wp_cookie_name = 'wordpress_logged_in_';
    $has_wp_cookie = false;
    $cookie_value = null;
    
    foreach ($_COOKIE as $name => $value) {
        if (strpos($name, $wp_cookie_name) === 0) {
            $has_wp_cookie = true;
            $cookie_value = $value;
            break;
        }
    }
    
    if (!$has_wp_cookie || !$cookie_value) {
        error_log("No WordPress admin cookie found");
        return false;
    }
    
    // Parse WordPress cookie value (format: username|expiration|token|hash)
    $cookie_parts = explode('|', $cookie_value);
    if (count($cookie_parts) < 4) {
        error_log("Invalid WordPress cookie format");
        return false;
    }
    
    // Check if cookie is expired
    if (intval($cookie_parts[1]) < time()) {
        error_log("WordPress cookie has expired");
        return false;
    }
    
    try {
        $pdo = get_db_connection();
        
        // Get user data and verify administrator role
        $stmt = $pdo->prepare("
            SELECT u.ID, u.user_login, u.display_name, u.user_email,
                   m1.meta_value as first_name,
                   m2.meta_value as last_name,
                   m3.meta_value as capabilities
            FROM {$GLOBALS['db_config']['table_prefix']}users u
            LEFT JOIN {$GLOBALS['db_config']['table_prefix']}usermeta m1 
                ON u.ID = m1.user_id AND m1.meta_key = 'first_name'
            LEFT JOIN {$GLOBALS['db_config']['table_prefix']}usermeta m2 
                ON u.ID = m2.user_id AND m2.meta_key = 'last_name'
            LEFT JOIN {$GLOBALS['db_config']['table_prefix']}usermeta m3 
                ON u.ID = m3.user_id AND m3.meta_key = '{$GLOBALS['db_config']['table_prefix']}capabilities'
            WHERE u.user_login = :username
        ");
        
        $stmt->execute(['username' => $cookie_parts[0]]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            error_log("WordPress user not found: " . $cookie_parts[0]);
            return false;
        }
        
        // Parse capabilities to verify admin role
        $capabilities = unserialize($user['capabilities'] ?? 'a:0:{}');
        if (!isset($capabilities['administrator']) || $capabilities['administrator'] !== true) {
            error_log("User is not an administrator: " . $cookie_parts[0]);
            return false;
        }
        
        // Return user data
        return (object)[
            'id' => $user['ID'],
            'username' => $user['user_login'],
            'email' => $user['user_email'],
            'display_name' => $user['display_name'],
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'role' => 'admin'
        ];
        
    } catch (Exception $e) {
        error_log("Admin authentication error: " . $e->getMessage());
        return false;
    }
}

/**
 * Middleware function to require admin authentication for endpoints
 * 
 * @return object|false The authenticated admin user or false if auth fails
 */
function require_admin_auth() {
    $admin = check_admin_auth();
    
    if (!$admin) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Admin authentication required',
            'code' => 'admin_auth_required'
        ]);
        exit;
    }
    
    return $admin;
} 