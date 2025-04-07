<?php
/**
 * Admin User Creation Endpoint
 * 
 * This endpoint allows admin users to create new users.
 */

// Include authentication middleware
require_once '../../auth/validate-token.php';

// Ensure the user is an admin
$admin = require_admin();

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
$requiredFields = ['email', 'password', 'first_name', 'last_name', 'role'];
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

// Validate role
if (isset($data['role']) && !in_array($data['role'], ['admin', 'client'])) {
    $errors['role'] = "Invalid role. Must be 'admin' or 'client'";
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
        error_response(['errors' => ['email' => 'Email already in use']]);
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
        'role' => sanitize_input($data['role']),
        'verified' => isset($data['verified']) ? (int) $data['verified'] : 1, // Auto-verify users created by admin
        'phone_number' => isset($data['phone_number']) ? sanitize_input($data['phone_number']) : null,
        'company' => isset($data['company']) ? sanitize_input($data['company']) : null
    ];
    
    // Insert new user
    $sql = 'INSERT INTO wp_charterhub_users (
        email, password, username, display_name, first_name, last_name,
        role, verified, phone_number, company, created_at, updated_at
    ) VALUES (
        :email, :password, :username, :display_name, :first_name, :last_name,
        :role, :verified, :phone_number, :company, NOW(), NOW()
    )';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($userData);
    
    // Get the new user ID
    $userId = $pdo->lastInsertId();
    
    // Commit transaction
    $pdo->commit();
    
    // Fetch the newly created user
    $stmt = $pdo->prepare('
        SELECT 
            id, email, username, display_name, first_name, last_name, 
            phone_number, company, role, verified, created_at
        FROM 
            wp_charterhub_users
        WHERE 
            id = ?
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    // Return success response
    json_response([
        'success' => true,
        'message' => 'User created successfully',
        'user' => $user
    ]);
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    error_log('User creation error: ' . $e->getMessage());
    error_response('Database error', 500);
} 