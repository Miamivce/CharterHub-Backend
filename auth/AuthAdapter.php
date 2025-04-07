<?php

require_once __DIR__ . '/JWTAuthService.php';
require_once __DIR__ . '/WPAuthService.php';

class AuthAdapter {
    /**
     * Authenticate a request using either JWT token or WordPress cookie.
     *
     * @return array|false Returns authentication data array on success, or false if authentication fails.
     */
    public static function authenticate() {
        // Check for JWT token in the Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            $payload = (array)JWTAuthService::validateToken($token);
            if ($payload) {
                // Token valid, attach auth method information
                $payload['auth_method'] = 'jwt';
                return $payload;
            }
        }

        // Fallback to WordPress authentication
        $wpUser = WPAuthService::validateRequest();
        if ($wpUser) {
            return [
                'auth_method' => 'wordpress',
                'user_data' => $wpUser
            ];
        }

        // If neither method passes, return false
        return false;
    }
}

?> 