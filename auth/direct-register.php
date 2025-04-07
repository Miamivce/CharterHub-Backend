<?php
/**
 * CharterHub Direct Registration API Endpoint for Testing
 * 
 * This file provides a simplified registration endpoint that
 * bypasses WordPress integration to avoid timeouts.
 * FOR DEVELOPMENT USE ONLY
 */

// Include configuration
require_once __DIR__ . '/config.php';

// This endpoint is for development use only
if (!defined('DEVELOPMENT_MODE') || DEVELOPMENT_MODE !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint is only available in development mode'
    ]);
    exit;
}

// Set CORS headers for API response
set_cors_headers(['POST', 'OPTIONS']);

// Require POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON request data
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Validate required fields
    $required_fields = ['email', 'password', 'firstName', 'lastName'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    // Validate email format
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Get database connection
    $pdo = get_db_connection();
    
    // Check if email already exists
    $stmt = $pdo->prepare("
        SELECT ID FROM {$db_config['table_prefix']}users 
        WHERE user_email = :email
    ");
    $stmt->execute(['email' => $data['email']]);
    
    if ($stmt->fetch()) {
        throw new Exception('Email already registered');
    }
    
    // For testing, directly create a verified user
    $stmt = $pdo->prepare("
        INSERT INTO {$db_config['table_prefix']}users (
            user_login, 
            user_pass, 
            user_email, 
            user_registered, 
            display_name, 
            first_name, 
            last_name, 
            phone_number, 
            company, 
            role, 
            verified
        ) VALUES (
            :user_login,
            :user_pass,
            :user_email,
            NOW(),
            :display_name,
            :first_name,
            :last_name,
            :phone_number,
            :company,
            'charter_client',
            1
        )
    ");
    
    $stmt->execute([
        'user_login' => $data['email'],
        'user_pass' => password_hash($data['password'], PASSWORD_DEFAULT),
        'user_email' => $data['email'],
        'display_name' => $data['firstName'] . ' ' . $data['lastName'],
        'first_name' => $data['firstName'],
        'last_name' => $data['lastName'],
        'phone_number' => $data['phoneNumber'] ?? null,
        'company' => $data['company'] ?? null
    ]);
    
    // Get the inserted user ID
    $user_id = $pdo->lastInsertId();
    
    // Generate a verification URL for testing
    $verification_token = generate_token();
    $verification_url = "{$frontend_urls['verification_url']}?token={$verification_token}";
    
    // Log successful registration
    log_auth_action(
        $user_id,
        'signup',
        'success',
        [
            'email' => $data['email'],
            'direct' => true
        ]
    );
    
    // Return success response with verification URL for testing
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful.',
        'verification_url' => $verification_url,
        'user_id' => $user_id,
        'email' => $data['email'],
        'verified' => true,
        'dev_mode' => true
    ]);
    
} catch (Exception $e) {
    // Log the error
    error_log("Direct registration error: " . $e->getMessage());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 