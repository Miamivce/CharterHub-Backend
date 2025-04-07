<?php
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
if (!$data || !isset($data['email']) || !isset($data['password'])) {
    error_response('Email and password are required');
}

$email = sanitize_input($data['email']);
$password = $data['password']; // Don't sanitize password

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_response('Invalid email format');
}

try {
    // Query the database for the user
    $stmt = $pdo->prepare('SELECT id, email, password, first_name, last_name, role, verified FROM wp_charterhub_users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    // Check if user exists
    if (!$user) {
        error_response('Invalid credentials', 401);
    }
    
    // Check if user is verified
    if (!$user['verified']) {
        error_response('Account not verified. Please check your email for verification instructions.', 401);
    }
    
    // Verify password (assuming password_hash was used to store passwords)
    if (!password_verify($password, $user['password'])) {
        // Increment login attempts
        $stmt = $pdo->prepare('UPDATE wp_charterhub_users SET login_attempts = login_attempts + 1 WHERE id = ?');
        $stmt->execute([$user['id']]);
        
        error_response('Invalid credentials', 401);
    }
    
    // Reset login attempts
    $stmt = $pdo->prepare('UPDATE wp_charterhub_users SET login_attempts = 0, last_login = NOW(), last_ip = ? WHERE id = ?');
    $stmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
    
    // Generate tokens
    $tokens = generate_tokens($user['id'], $user['role']);
    
    // Store refresh token in database
    $stmt = $pdo->prepare('UPDATE wp_charterhub_users SET refresh_token = ? WHERE id = ?');
    $stmt->execute([$tokens['refresh_token'], $user['id']]);
    
    // Prepare user data to return
    $user_data = [
        'id' => $user['id'],
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'role' => $user['role']
    ];
    
    // Return success response with tokens and user data
    json_response([
        'success' => true,
        'message' => 'Login successful',
        'user' => $user_data,
        'tokens' => $tokens
    ]);
    
} catch (PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    error_response('Database error', 500);
} 