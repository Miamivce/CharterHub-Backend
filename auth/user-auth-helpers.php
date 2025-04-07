<?php
/**
 * User Authentication Helper Functions
 * 
 * This file contains helper functions for user authentication
 * including JWT token generation and validation.
 */

// Prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access to this file is not allowed.');
}

/**
 * Generate JWT token pair (access token and refresh token)
 * 
 * @param array $user User data from database
 * @return array|false Array containing access and refresh tokens or false on failure
 */
function generate_token_pair($user) {
    // Access token expiration (1 hour)
    $access_token_expiry = time() + 3600;
    // Refresh token expiration (30 days)
    $refresh_token_expiry = time() + (30 * 24 * 60 * 60);
    
    // Create token payload
    $payload = [
        'sub' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'iat' => time(),
        'exp' => $access_token_expiry
    ];
    
    // Generate JWT token
    $access_token = generate_jwt($payload);
    
    // Generate refresh token
    $refresh_token = bin2hex(random_bytes(32));
    
    if (!$access_token) {
        return false;
    }
    
    try {
        $db = get_db_connection();
        
        // Store token in database
        $stmt = $db->prepare('
            INSERT INTO wp_charterhub_jwt_tokens 
            (user_id, token_hash, refresh_token_hash, expires_at, refresh_expires_at) 
            VALUES (?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))
        ');
        
        $stmt->execute([
            $user['id'],
            hash('sha256', $access_token),
            hash('sha256', $refresh_token),
            $access_token_expiry,
            $refresh_token_expiry
        ]);
        
        // Update last login time
        $stmt = $db->prepare('
            UPDATE wp_charterhub_users 
            SET last_login = NOW() 
            WHERE id = ?
        ');
        
        $stmt->execute([$user['id']]);
        
        return [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'expires_in' => 3600
        ];
        
    } catch (PDOException $e) {
        // Log error
        error_log('Error storing token: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate JWT token
 * 
 * @param array $payload Token payload
 * @return string|false JWT token or false on failure
 */
function generate_jwt($payload) {
    // Get JWT secret from environment or use default for development
    $secret = getenv('JWT_SECRET') ?: 'charterhub_jwt_secret_development_only';
    
    // Create JWT header
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    
    // Encode header and payload
    $header_encoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $payload_encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    
    // Create signature
    $signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", $secret, true);
    $signature_encoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    
    // Create token
    $token = "$header_encoded.$payload_encoded.$signature_encoded";
    
    return $token;
}

/**
 * Verify JWT token
 * 
 * @param string $token JWT token to verify
 * @return array|false Payload if token is valid, false otherwise
 */
function verify_jwt($token) {
    // Get JWT secret from environment or use default for development
    $secret = getenv('JWT_SECRET') ?: 'charterhub_jwt_secret_development_only';
    
    // Split token into parts
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        return false;
    }
    
    // Get header, payload and signature
    $header_encoded = $parts[0];
    $payload_encoded = $parts[1];
    $signature_encoded = $parts[2];
    
    // Verify signature
    $signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", $secret, true);
    $signature_check = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    
    if ($signature_check !== $signature_encoded) {
        return false;
    }
    
    // Decode payload
    $payload = json_decode(base64_decode(strtr($payload_encoded, '-_', '+/')), true);
    
    if (!$payload) {
        return false;
    }
    
    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }
    
    return $payload;
} 