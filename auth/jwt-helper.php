<?php
/**
 * CharterHub JWT Helper Functions
 * 
 * This file provides helper functions for JWT token generation and management
 * in the authentication system.
 */

// Don't allow direct access
if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access not allowed');
}

// Include required files
require_once __DIR__ . '/jwt-fix.php';
require_once __DIR__ . '/token-storage.php';

/**
 * Generate access and refresh tokens for a user
 * 
 * @param array $userData User data array containing at least id, email, role
 * @return array Array containing access_token, refresh_token, and expires_in
 */
function generate_tokens($userData) {
    // Access token (15 minutes)
    $access_token_expiry = 900;
    // Refresh token (30 days)
    $refresh_token_expiry = 2592000;
    
    // Validate required fields
    if (!isset($userData['id'])) {
        error_log("generate_tokens: Missing required user ID");
        throw new Exception("User ID is required for token generation");
    }
    
    $user_id = $userData['id'];
    $role = $userData['role'] ?? 'client';
    
    // Create access token payload with expanded user data
    $access_payload = [
        'sub' => $user_id,
        'role' => $role,
        'type' => 'access',
        'exp' => time() + $access_token_expiry
    ];
    
    // Add additional user data from array
    $access_payload['email'] = $userData['email'] ?? '';
    $access_payload['firstName'] = $userData['first_name'] ?? '';
    $access_payload['lastName'] = $userData['last_name'] ?? '';
    $access_payload['phoneNumber'] = $userData['phone_number'] ?? '';
    $access_payload['company'] = $userData['company'] ?? '';
    $access_payload['verified'] = isset($userData['verified']) ? (bool)$userData['verified'] : false;
    
    // Create refresh token payload
    $refresh_payload = [
        'sub' => $user_id,
        'type' => 'refresh',
        'exp' => time() + $refresh_token_expiry
    ];
    
    // Generate tokens using the improved function
    $access_token = improved_generate_jwt_token($access_payload, $access_token_expiry);
    $refresh_token = improved_generate_jwt_token($refresh_payload, $refresh_token_expiry);
    
    // Store access token in database
    store_jwt_token($access_token, $user_id, $access_token_expiry, $refresh_token, $refresh_token_expiry);
    
    return [
        'token' => $access_token,
        'refresh_token' => $refresh_token,
        'expires_in' => $access_token_expiry
    ];
}

/**
 * Generate a new token for a user after their email has changed
 * 
 * @param int $user_id User ID
 * @param string $email New email address
 * @return array Array containing access_token, refresh_token, and expires_in
 */
function generate_tokens_for_email_change($user_id, $email) {
    try {
        $db = get_db_connection();
        
        // Get complete user data
        $stmt = $db->prepare('SELECT * FROM wp_charterhub_users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found for ID: $user_id");
        }
        
        // Ensure the email in user data is updated to the new one
        $user['email'] = $email;
        
        // Generate tokens with updated user data
        return generate_tokens($user);
    } catch (Exception $e) {
        error_log("generate_tokens_for_email_change: Error: " . $e->getMessage());
        throw $e;
    }
} 