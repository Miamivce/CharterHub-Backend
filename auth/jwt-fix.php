<?php
/**
 * CharterHub JWT Fix
 * 
 * This file contains improved JWT handling functions to fix token
 * verification issues in the authentication system.
 * 
 * IMPORTANT: These functions only affect client-side authentication.
 * WordPress admin authentication remains unaffected.
 */

// Don't allow direct access
if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access not allowed');
}

/**
 * Helper function for base64url encoding (RFC 7515)
 * 
 * @param string $data The data to encode
 * @return string The base64url encoded string
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Helper function for base64url decoding (RFC 7515)
 * 
 * @param string $data The data to decode
 * @return string The decoded data
 */
function base64url_decode($data) {
    $b64 = strtr($data, '-_', '+/');
    $padlen = 4 - strlen($b64) % 4;
    if ($padlen < 4) {
        $b64 .= str_repeat('=', $padlen);
    }
    return base64_decode($b64);
}

/**
 * Improved JWT token verification that handles base64url encoding properly
 * This should replace the existing verify_jwt_token function
 * 
 * IMPORTANT: This only affects client authentication, not WordPress admin auth
 * 
 * @param string $jwt The JWT token to verify
 * @param bool $allow_expired Whether to allow expired tokens
 * @return object The decoded payload with additional verification info
 * @throws Exception If token verification fails
 */
function improved_verify_jwt_token($jwt, $allow_expired = false) {
    try {
        // Basic format validation
        if (!preg_match('/^[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+$/', $jwt)) {
            error_log("Invalid JWT format: " . substr($jwt, 0, 20) . "...");
            throw new Exception('Invalid token format');
        }

        // Decode JWT parts with proper base64url decoding
        $token_parts = explode('.', $jwt);
        $header = json_decode(base64url_decode($token_parts[0]), true);
        $payload = json_decode(base64url_decode($token_parts[1]), true);

        error_log('improved_verify_jwt_token: Decoded header: ' . json_encode($header));
        error_log('improved_verify_jwt_token: Decoded payload: ' . json_encode($payload));

        // Validate header
        if (!$header || !isset($header['alg']) || $header['alg'] !== 'HS256') {
            error_log("Invalid JWT header");
            throw new Exception('Invalid token header');
        }

        // Validate payload
        if (!$payload || !isset($payload['exp']) || !isset($payload['sub'])) {
            error_log("Invalid JWT payload structure");
            throw new Exception('Invalid token payload');
        }

        // Check expiration (skip if allow_expired is true)
        if (!$allow_expired && time() > $payload['exp']) {
            error_log("Token expired");
            throw new Exception('Token has expired');
        }

        // Verify signature using proper base64url encoding
        $signature = hash_hmac('sha256', 
            $token_parts[0] . '.' . $token_parts[1], 
            $GLOBALS['auth_config']['jwt_secret'], 
            true
        );
        $signature = base64url_encode($signature);

        if ($signature !== $token_parts[2]) {
            error_log("Invalid JWT signature");
            throw new Exception('Invalid token signature');
        }

        // Verify token in database - now returns detailed array
        $db_verification = verify_token_in_database($jwt, $allow_expired);
        
        if (!$db_verification['valid']) {
            $reason = $db_verification['reason'] ?? 'unknown';
            
            // Special handling for tokens that are valid but not in DB
            if ($reason === 'not_found_in_db' && isset($db_verification['user_id'])) {
                // Return payload with database verification info
                $result = (object)$payload;
                $result->_verification = $db_verification;
                return $result;
            }
            
            error_log("Token not valid in database. Reason: " . $reason);
            throw new Exception('Token not valid in database: ' . $reason);
        }
        
        // Return decoded payload with verification info for use in the application
        $result = (object)$payload;
        $result->_verification = $db_verification;
        return $result;

    } catch (Exception $e) {
        error_log("JWT verification failed: " . $e->getMessage());
        throw new Exception('Token verification failed: ' . $e->getMessage());
    }
}

/**
 * Improved JWT token generation with proper base64url encoding
 * This should replace the existing generate_jwt_token function
 * 
 * IMPORTANT: This only affects client authentication, not WordPress admin auth
 * 
 * @param array $user_data User data for token payload
 * @param int $expiration_time Custom expiration time (optional)
 * @return string The generated JWT token
 */
function improved_generate_jwt_token($user_data, $expiration_time = null) {
    global $auth_config;
    
    // Use custom expiration time if provided, otherwise default
    $expiration = $expiration_time ?? $auth_config['jwt_expiration'];
    
    // Create header
    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256'
    ];
    
    // Create payload
    $payload = [
        'sub' => $user_data['sub'] ?? null,
        'email' => $user_data['email'] ?? null,
        'firstName' => $user_data['firstName'] ?? '',
        'lastName' => $user_data['lastName'] ?? '',
        'phoneNumber' => $user_data['phoneNumber'] ?? '',
        'company' => $user_data['company'] ?? '',
        'role' => $user_data['role'] ?? 'client',
        'verified' => $user_data['verified'] ?? false,
        'iat' => $user_data['iat'] ?? time(),
        'exp' => $user_data['exp'] ?? (time() + $expiration)
    ];
    
    // Base64URL encode header and payload
    $base64UrlHeader = base64url_encode(json_encode($header));
    $base64UrlPayload = base64url_encode(json_encode($payload));
    
    // Get JWT secret with fallback
    $jwt_secret = $auth_config['jwt_secret'] ?? 'charterhub_jwt_secret_key_change_in_production';
    
    // Create signature
    $signature = hash_hmac(
        'sha256',
        $base64UrlHeader . '.' . $base64UrlPayload,
        $jwt_secret,
        true
    );
    $base64UrlSignature = base64url_encode($signature);
    
    // Combine all parts
    return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
}

/**
 * Decode a JWT token and return the payload without verification
 * Used only for debugging and token content inspection
 * 
 * @param string $token The JWT token to decode
 * @return object|null The decoded payload, or null if decoding failed
 */
function improved_decode_jwt_token($token) {
    try {
        $token_parts = explode('.', $token);
        if (count($token_parts) !== 3) {
            return null;
        }
        
        $payload_base64 = $token_parts[1];
        $payload_decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload_base64));
        
        if (!$payload_decoded) {
            return null;
        }
        
        $payload = json_decode($payload_decoded);
        return $payload;
    } catch (Exception $e) {
        error_log("Error decoding JWT token: " . $e->getMessage());
        return null;
    }
} 