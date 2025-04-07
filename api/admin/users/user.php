<?php
/**
 * Admin User Management Endpoint for Individual Users
 * 
 * This endpoint allows admin users to get, update, or delete specific users.
 */

// Include authentication middleware
require_once '../../auth/validate-token.php';

// Ensure the user is an admin
$admin = require_admin();

// Get user ID from the URL parameter
$user_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$user_id) {
    error_response('User ID is required', 400);
}

// Handle different HTTP methods
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        handleGetUser($user_id);
        break;
    case 'POST':
        handleUpdateUser($user_id);
        break;
    case 'DELETE':
        handleDeleteUser($user_id);
        break;
    case 'OPTIONS':
        // Options are handled by CORS in config.php
        break;
    default:
        error_response('Method not allowed', 405);
}

/**
 * Handle GET request to fetch a specific user
 */
function handleGetUser($user_id) {
    try {
        global $pdo;
        
        // Fetch user data
        $stmt = $pdo->prepare('
            SELECT 
                id, email, username, display_name, first_name, last_name, 
                phone_number, company, role, verified, last_login, created_at,
                updated_at, login_attempts
            FROM 
                wp_charterhub_users
            WHERE 
                id = ?
        ');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            error_response('User not found', 404);
        }
        
        // Return user data
        json_response([
            'success' => true,
            'user' => $user
        ]);
        
    } catch (PDOException $e) {
        error_log('User fetch error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
}

/**
 * Handle POST request to update a specific user
 */
function handleUpdateUser($user_id) {
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data) {
        error_response('Invalid JSON data');
    }
    
    // Fields that can be updated by admin
    $allowedFields = [
        'first_name', 'last_name', 'display_name', 
        'phone_number', 'company', 'email', 'role', 'verified'
    ];
    
    // Validate and sanitize input fields
    $updateFields = [];
    $params = [];
    $errors = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $value = sanitize_input($data[$field]);
            
            // Validate email format
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
                continue;
            }
            
            // Validate role
            if ($field === 'role' && !in_array($value, ['admin', 'client'])) {
                $errors['role'] = 'Invalid role';
                continue;
            }
            
            // Validate verified
            if ($field === 'verified' && !in_array($value, [0, 1, '0', '1'])) {
                $errors['verified'] = 'Invalid verified status';
                continue;
            }
            
            // Convert boolean fields to integers
            if ($field === 'verified') {
                $value = (int) $value;
            }
            
            // Add to update fields
            $updateFields[] = "$field = ?";
            $params[] = $value;
        }
    }
    
    // Check for validation errors
    if (!empty($errors)) {
        error_response(['errors' => $errors]);
    }
    
    // If no fields to update, return success
    if (empty($updateFields)) {
        json_response([
            'success' => true,
            'message' => 'No fields to update'
        ]);
    }
    
    // Add updated_at field
    $updateFields[] = "updated_at = NOW()";
    
    // Add user ID to params
    $params[] = $user_id;
    
    try {
        global $pdo;
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Check if user exists
        $stmt = $pdo->prepare('SELECT id FROM wp_charterhub_users WHERE id = ?');
        $stmt->execute([$user_id]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            error_response('User not found', 404);
        }
        
        // Check for email uniqueness if email is being updated
        if (isset($data['email'])) {
            $stmt = $pdo->prepare('SELECT id FROM wp_charterhub_users WHERE email = ? AND id != ?');
            $stmt->execute([$data['email'], $user_id]);
            
            if ($stmt->fetch()) {
                $pdo->rollBack();
                error_response(['errors' => ['email' => 'Email already in use']]);
            }
        }
        
        // Update user data
        $sql = 'UPDATE wp_charterhub_users SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Commit transaction
        $pdo->commit();
        
        // Fetch updated user data
        $stmt = $pdo->prepare('
            SELECT 
                id, email, username, display_name, first_name, last_name, 
                phone_number, company, role, verified, last_login, created_at,
                updated_at, login_attempts
            FROM 
                wp_charterhub_users
            WHERE 
                id = ?
        ');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        // Return success response
        json_response([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $user
        ]);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        error_log('User update error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
}

/**
 * Handle DELETE request to delete a specific user
 */
function handleDeleteUser($user_id) {
    try {
        global $pdo;
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Check if user exists
        $stmt = $pdo->prepare('SELECT id, role FROM wp_charterhub_users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $pdo->rollBack();
            error_response('User not found', 404);
        }
        
        // Prevent deleting the last admin user
        if ($user['role'] === 'admin') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM wp_charterhub_users WHERE role = "admin"');
            $stmt->execute();
            $adminCount = $stmt->fetchColumn();
            
            if ($adminCount <= 1) {
                $pdo->rollBack();
                error_response('Cannot delete the last admin user', 403);
            }
        }
        
        // Delete user
        $stmt = $pdo->prepare('DELETE FROM wp_charterhub_users WHERE id = ?');
        $stmt->execute([$user_id]);
        
        // Commit transaction
        $pdo->commit();
        
        // Return success response
        json_response([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        error_log('User delete error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
} 