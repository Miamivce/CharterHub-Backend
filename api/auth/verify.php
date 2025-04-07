<?php
/**
 * Email Verification Endpoint
 * 
 * This endpoint verifies a user's email address using a verification token.
 */

// Include configuration
require_once 'config.php';

// Only allow GET or POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response('Method not allowed', 405);
}

// Get token from query parameter or JSON payload
$token = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = isset($_GET['token']) ? sanitize_input($_GET['token']) : '';
} else {
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Validate input
    if (!$data || !isset($data['token'])) {
        error_response('Token is required');
    }
    
    $token = sanitize_input($data['token']);
}

// Validate token
if (empty($token)) {
    error_response('Token is required');
}

try {
    global $pdo;
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Find the user with this verification token
    $stmt = $pdo->prepare('
        SELECT id, email, verified, verification_expires 
        FROM wp_charterhub_users 
        WHERE verification_token = ?
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    // Check if user exists
    if (!$user) {
        $pdo->rollBack();
        error_response('Invalid verification token', 400);
    }
    
    // Check if already verified
    if ($user['verified']) {
        $pdo->rollBack();
        error_response('Email already verified', 400);
    }
    
    // Check if token has expired
    if ($user['verification_expires'] && strtotime($user['verification_expires']) < time()) {
        $pdo->rollBack();
        error_response('Verification token has expired', 400);
    }
    
    // Update user to verified
    $stmt = $pdo->prepare('
        UPDATE wp_charterhub_users 
        SET verified = 1, verification_token = NULL, verification_expires = NULL, updated_at = NOW() 
        WHERE id = ?
    ');
    $stmt->execute([$user['id']]);
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    json_response([
        'success' => true,
        'message' => 'Email verified successfully. You can now log in.',
        'email' => $user['email']
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    error_log('Email verification error: ' . $e->getMessage());
    error_response('Database error', 500);
} 