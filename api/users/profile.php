<?php
/**
 * User Profile API Endpoint
 * 
 * Handles GET and POST requests for user profile data
 */

// Debug logging
error_log("profile.php: Starting execution");

// Include authentication middleware
require_once '../auth/validate-token.php';
error_log("profile.php: Included validate-token.php");

// Handle different HTTP methods
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        error_log("profile.php: Handling GET request");
        handleGetProfile();
        break;
    case 'POST':
        error_log("profile.php: Handling POST request");
        handleUpdateProfile();
        break;
    case 'OPTIONS':
        error_log("profile.php: Handling OPTIONS request");
        // Options are handled by CORS in config.php
        break;
    default:
        error_log("profile.php: Method not allowed: " . $_SERVER['REQUEST_METHOD']);
        error_response('Method not allowed', 405);
}

/**
 * Handle GET request to fetch user profile
 */
function handleGetProfile() {
    // Authenticate user
    $user = require_auth();
    
    // Fetch additional user data if needed
    global $pdo;
    $stmt = $pdo->prepare('
        SELECT 
            id, email, username, display_name, first_name, last_name, 
            phone_number, company, role, verified, last_login, created_at
        FROM 
            wp_charterhub_users
        WHERE 
            id = ?
    ');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    
    if (!$profile) {
        error_response('Profile not found', 404);
    }
    
    // Return profile data
    json_response([
        'success' => true,
        'profile' => $profile
    ]);
}

/**
 * Handle POST request to update user profile
 */
function handleUpdateProfile() {
    error_log("handleUpdateProfile: Starting function");
    
    // Authenticate user
    error_log("handleUpdateProfile: Calling require_auth()");
    $user = require_auth();
    error_log("handleUpdateProfile: Authentication successful for user ID: " . $user['id']);
    
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    error_log("handleUpdateProfile: Received data: " . $json);
    
    if (!$data) {
        error_log("handleUpdateProfile: Invalid JSON data");
        error_response('Invalid JSON data');
    }
    
    // Fields that can be updated
    $allowedFields = [
        'first_name', 'last_name', 'display_name', 
        'phone_number', 'company', 'email'
    ];
    
    // Validate and sanitize input fields
    $updateFields = [];
    $params = [];
    $errors = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $value = sanitize_input($data[$field]);
            error_log("handleUpdateProfile: Processing field $field with value: $value");
            
            // Validate email format
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
                error_log("handleUpdateProfile: Invalid email format: $value");
                continue;
            }
            
            // Add to update fields
            $updateFields[] = "$field = ?";
            $params[] = $value;
        }
    }
    
    // Check for validation errors
    if (!empty($errors)) {
        error_log("handleUpdateProfile: Validation errors: " . json_encode($errors));
        error_response(['errors' => $errors]);
    }
    
    // If no fields to update, return success
    if (empty($updateFields)) {
        error_log("handleUpdateProfile: No fields to update");
        json_response([
            'success' => true,
            'message' => 'No fields to update'
        ]);
    }
    
    // Add updated_at field
    $updateFields[] = "updated_at = NOW()";
    
    // Add user ID to params
    $params[] = $user['id'];
    
    // Update the user profile
    try {
        global $pdo;
        
        // Begin transaction
        $pdo->beginTransaction();
        error_log("handleUpdateProfile: Started database transaction");
        
        // Check for email uniqueness if email is being updated
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            error_log("handleUpdateProfile: Checking email uniqueness for: " . $data['email']);
            $stmt = $pdo->prepare('SELECT id FROM wp_charterhub_users WHERE email = ? AND id != ?');
            $stmt->execute([$data['email'], $user['id']]);
            
            if ($stmt->fetch()) {
                $pdo->rollBack();
                error_log("handleUpdateProfile: Email already in use: " . $data['email']);
                error_response(['errors' => ['email' => 'Email already in use']]);
            }
        }
        
        // Update user profile
        $sql = 'UPDATE wp_charterhub_users SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
        error_log("handleUpdateProfile: Executing SQL: " . $sql);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Commit transaction
        $pdo->commit();
        error_log("handleUpdateProfile: Transaction committed successfully");
        
        // Fetch updated profile
        $stmt = $pdo->prepare('
            SELECT 
                id, email, username, display_name, first_name, last_name, 
                phone_number, company, role, verified, last_login, created_at
            FROM 
                wp_charterhub_users
            WHERE 
                id = ?
        ');
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
        error_log("handleUpdateProfile: Fetched updated profile for user ID: " . $user['id']);
        
        // Generate new tokens if email was updated
        $tokens = null;
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            error_log("handleUpdateProfile: Email updated, generating new tokens");
            $tokens = generate_tokens($user['id'], $user['role']);
            
            // Store refresh token in database
            $stmt = $pdo->prepare('UPDATE wp_charterhub_users SET refresh_token = ? WHERE id = ?');
            $stmt->execute([$tokens['refresh_token'], $user['id']]);
            error_log("handleUpdateProfile: New tokens generated and stored");
        }
        
        // Return success response
        error_log("handleUpdateProfile: Returning success response");
        json_response([
            'success' => true,
            'message' => 'Profile updated successfully',
            'profile' => $profile,
            'tokens' => $tokens
        ]);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        error_log('Profile update error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
} 