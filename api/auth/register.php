<?php
/**
 * User Registration Endpoint
 * 
 * This endpoint allows new users to register with the system.
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
if (!$data) {
    error_response('Invalid JSON data');
}

// Required fields
$requiredFields = ['email', 'password', 'first_name', 'last_name'];
$errors = [];

// Check for required fields
foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || empty($data[$field])) {
        $errors[$field] = "$field is required";
    }
}

// Validate email format
if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = "Invalid email format";
}

// Validate password strength
if (isset($data['password'])) {
    $password = $data['password'];
    if (strlen($password) < 8) {
        $errors['password'] = "Password must be at least 8 characters";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = "Password must contain at least one uppercase letter";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors['password'] = "Password must contain at least one lowercase letter";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = "Password must contain at least one number";
    }
}

// Return errors if any
if (!empty($errors)) {
    error_response(['errors' => $errors]);
}

try {
    global $pdo;
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Check if email already exists
    $stmt = $pdo->prepare('SELECT id FROM wp_charterhub_users WHERE email = ?');
    $stmt->execute([sanitize_input($data['email'])]);
    
    if ($stmt->fetch()) {
        $pdo->rollBack();
        error_response(['errors' => ['email' => 'Email already in use']], 409);
    }
    
    // Generate username from email if not provided
    $username = isset($data['username']) && !empty($data['username'])
        ? sanitize_input($data['username'])
        : explode('@', sanitize_input($data['email']))[0] . '_' . substr(md5(time()), 0, 6);
    
    // Check if username already exists
    $stmt = $pdo->prepare('SELECT id FROM wp_charterhub_users WHERE username = ?');
    $stmt->execute([$username]);
    
    if ($stmt->fetch()) {
        $username = $username . '_' . substr(md5(time()), 0, 6);
    }
    
    // Set display name if not provided
    $display_name = isset($data['display_name']) && !empty($data['display_name'])
        ? sanitize_input($data['display_name'])
        : sanitize_input($data['first_name']) . ' ' . sanitize_input($data['last_name']);
    
    // Hash password
    $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // Prepare user data
    $userData = [
        'email' => sanitize_input($data['email']),
        'password' => $password_hash,
        'username' => $username,
        'display_name' => $display_name,
        'first_name' => sanitize_input($data['first_name']),
        'last_name' => sanitize_input($data['last_name']),
        'role' => 'client', // Default role for self-registration
        'verified' => 0, // Require email verification
        'verification_token' => bin2hex(random_bytes(32)),
        'verification_expires' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'phone_number' => isset($data['phone_number']) ? sanitize_input($data['phone_number']) : null,
        'company' => isset($data['company']) ? sanitize_input($data['company']) : null
    ];
    
    // Insert new user
    $sql = 'INSERT INTO wp_charterhub_users (
        email, password, username, display_name, first_name, last_name,
        role, verified, verification_token, verification_expires, 
        phone_number, company, created_at, updated_at
    ) VALUES (
        :email, :password, :username, :display_name, :first_name, :last_name,
        :role, :verified, :verification_token, :verification_expires, 
        :phone_number, :company, NOW(), NOW()
    )';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($userData);
    
    // Get the new user ID
    $userId = $pdo->lastInsertId();
    
    // TODO: Send verification email
    // This is a placeholder for email sending functionality
    $verificationUrl = "http://localhost:3000/verify-email?token=" . $userData['verification_token'];
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    json_response([
        'success' => true,
        'message' => 'Registration successful. Please check your email to verify your account.',
        'user_id' => $userId,
        'verification_url' => $verificationUrl // In a production environment, this would not be returned
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    error_log('Registration error: ' . $e->getMessage());
    error_response('Database error', 500);
} catch (Exception $e) {
    // Rollback transaction on other errors
    $pdo->rollBack();
    
    error_log('Registration error: ' . $e->getMessage());
    error_response('An unexpected error occurred', 500);
} 