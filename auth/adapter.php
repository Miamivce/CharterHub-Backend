<?php

// Include necessary files. Adjust paths as needed.
require_once __DIR__ . '/../jwt-fix.php';
require_once __DIR__ . '/AdminAuthService.php';

class AuthAdapter {
    /**
     * Authenticate the request using JWT or WordPress cookie.
     * Returns a unified user object on success, or null on failure.
     */
    public static function authenticate() {
        // Attempt to get JWT token from Authorization header
        $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            // Verify JWT token
            $jwtUser = improved_verify_jwt_token($token);
            if ($jwtUser) {
                $jwtUser['auth_method'] = 'jwt';
                return $jwtUser;
            }
        }

        // Fallback: Check for WordPress cookie
        if (isset($_COOKIE['wordpress_logged_in_cd9b744c619529c4988e0e94344eaf12'])) { // example cookie name
            $wpUser = AdminAuthService::authenticate();
            if ($wpUser) {
                $wpUser['auth_method'] = 'wordpress';
                return $wpUser;
            }
        }

        return null;
    }
}

// For testing purposes
if (php_sapi_name() === 'cli-server') {
    header('Content-Type: application/json');
    $user = AuthAdapter::authenticate();
    echo json_encode($user);
}

?> 