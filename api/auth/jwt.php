<?php
/**
 * Simple JWT Implementation
 * 
 * This provides basic JWT functionality for token generation and validation.
 * In a production environment, you should use a proper library like firebase/php-jwt.
 */

error_log("jwt.php: File loaded");

/**
 * Encodes a payload into a JWT token
 * 
 * @param array $payload The data to encode in the token
 * @param string $key The secret key for signing
 * @return string The JWT token
 */
function jwt_encode($payload, $key) {
    error_log("jwt_encode: Encoding payload: " . json_encode($payload));
    
    // Define the header
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    
    // Encode header
    $header_encoded = base64url_encode(json_encode($header));
    
    // Encode payload
    $payload_encoded = base64url_encode(json_encode($payload));
    
    // Create signature
    $signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", $key, true);
    $signature_encoded = base64url_encode($signature);
    
    // Create JWT
    $token = "$header_encoded.$payload_encoded.$signature_encoded";
    error_log("jwt_encode: Generated token: " . substr($token, 0, 20) . "...");
    
    return $token;
}

/**
 * Decodes a JWT token and verifies its signature
 * 
 * @param string $token The JWT token to decode
 * @param string $key The secret key for verification
 * @return array|false The decoded payload if valid, false otherwise
 */
function jwt_decode($token, $key) {
    error_log("jwt_decode: Decoding token: " . substr($token, 0, 20) . "...");
    
    // Split the token
    $parts = explode('.', $token);
    if (count($parts) != 3) {
        error_log("jwt_decode: Invalid token format - wrong number of parts");
        return false;
    }
    
    list($header_encoded, $payload_encoded, $signature_encoded) = $parts;
    
    // Verify signature
    $signature = base64url_decode($signature_encoded);
    $expected_signature = hash_hmac('sha256', "$header_encoded.$payload_encoded", $key, true);
    
    if (!hash_equals($signature, $expected_signature)) {
        error_log("jwt_decode: Invalid signature");
        return false;
    }
    
    // Decode payload
    $payload = json_decode(base64url_decode($payload_encoded), true);
    error_log("jwt_decode: Decoded payload: " . json_encode($payload));
    
    return $payload;
}

/**
 * Base64URL encoding (URL-safe version of base64)
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64URL decoding (URL-safe version of base64)
 */
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
} 