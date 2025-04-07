<?php
/**
 * Token Refresh Endpoint
 * 
 * This endpoint allows clients to obtain a new access token using a valid refresh token.
 * It implements token rotation by invalidating the old refresh token and issuing a new one.
 */

// Include configuration
require_once 'config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (!$data || !isset($data['refresh_token'])) {
    error_response('Refresh token is required');
}

$refresh_token = $data['refresh_token'];

try {
    // Decode and validate the refresh token
    $payload = jwt_decode($refresh_token, JWT_SECRET);
    
    // Check if token is valid and not expired
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        error_response('Invalid or expired refresh token', 401);
    }
    
    // Verify this is a refresh token
    if (!isset($payload['type']) || $payload['type'] !== 'refresh') {
        error_response('Invalid token type', 401);
    }
    
    // Get user ID from the token
    if (!isset($payload['sub'])) {
        error_response('Invalid token format', 401);
    }
    
    $user_id = $payload['sub'];
    
    // Verify refresh token against the one stored in the database
    $stmt = $pdo->prepare('SELECT refresh_token, role FROM wp_charterhub_users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || $user['refresh_token'] !== $refresh_token) {
        error_response('Invalid refresh token', 401);
    }
    
    // Generate new tokens
    $tokens = generate_tokens($user_id, $user['role']);
    
    // Update refresh token in the database
    $stmt = $pdo->prepare('UPDATE wp_charterhub_users SET refresh_token = ? WHERE id = ?');
    $stmt->execute([$tokens['refresh_token'], $user_id]);
    
    // Return new tokens
    json_response([
        'success' => true,
        'message' => 'Token refreshed successfully',
        'tokens' => $tokens
    ]);
    
} catch (Exception $e) {
    error_log('Token refresh error: ' . $e->getMessage());
    error_response('Token refresh failed', 500);
} 