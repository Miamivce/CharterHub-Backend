<?php
/**
 * Authentication Helper Functions
 * 
 * This file contains common functions for authentication, token validation, and user management.
 */

// Send a JSON response
function send_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

// Send an error response
function error_response($message, $status_code = 400, $error_code = 'error') {
    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'error' => $error_code,
        'message' => $message
    ]);
    exit;
}

// Check if an invitation token is valid
function check_invitation_token($token) {
    global $mysqli;
    
    // Connect to database if not already connected
    if (!isset($mysqli) || $mysqli->connect_error) {
        $mysqli = new mysqli('localhost', 'root', '', 'charterhub_local');
        if ($mysqli->connect_error) {
            error_log("Database connection failed: " . $mysqli->connect_error);
            return [
                'success' => false,
                'error' => 'database_error',
                'message' => 'Could not connect to database'
            ];
        }
    }
    
    // Log what we're doing
    error_log("Checking invitation token: " . substr($token, 0, 8) . "...");
    
    // Find the invitation in the database
    $stmt = $mysqli->prepare("SELECT * FROM wp_charterhub_invitations WHERE token = ? LIMIT 1");
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $mysqli->error);
        return [
            'success' => false,
            'error' => 'database_error',
            'message' => 'Failed to prepare statement'
        ];
    }
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return [
            'success' => false,
            'error' => 'invalid_token',
            'message' => 'Invitation not found'
        ];
    }
    
    $invitation = $result->fetch_assoc();
    $stmt->close();
    
    error_log("Found invitation record for token: " . substr($token, 0, 8) . "...");
    
    // Check if the invitation has already been used
    // First check used_at field, then fall back to is_used for backwards compatibility
    if ((!empty($invitation['used_at']) && $invitation['used_at'] !== NULL) || 
        (isset($invitation['is_used']) && $invitation['is_used']) ||
        (isset($invitation['used']) && $invitation['used'])) {
        
        error_log("Invitation token " . substr($token, 0, 8) . "... has been used");
        
        return [
            'success' => false,
            'error' => 'token_used',
            'message' => 'Invitation has already been used',
            'customer_id' => isset($invitation['customer_id']) ? $invitation['customer_id'] : null,
            'customer_email' => isset($invitation['email']) ? $invitation['email'] : null
        ];
    }
    
    // Check if the invitation has expired
    $expires_at = isset($invitation['expires_at']) ? strtotime($invitation['expires_at']) : null;
    if ($expires_at && $expires_at < time()) {
        error_log("Invitation token " . substr($token, 0, 8) . "... has expired");
        return [
            'success' => false,
            'error' => 'token_expired',
            'message' => 'Invitation has expired'
        ];
    }
    
    // Get customer information
    $customer_id = isset($invitation['customer_id']) ? $invitation['customer_id'] : 0;
    if (!$customer_id) {
        error_log("Invitation token " . substr($token, 0, 8) . "... missing customer ID");
        return [
            'success' => false,
            'error' => 'invalid_invitation',
            'message' => 'Invalid invitation: missing customer ID'
        ];
    }
    
    // Try to get user data - first try wp_charterhub_users, then fall back to other tables if needed
    $user_data = null;
    $table_tried = '';
    
    // First try wp_charterhub_users (preferred)
    try {
        $stmt = $mysqli->prepare("SELECT * FROM wp_charterhub_users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $table_tried = 'wp_charterhub_users';
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user_data = $result->fetch_assoc();
                error_log("Found user in wp_charterhub_users with ID: " . $customer_id);
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error checking wp_charterhub_users: " . $e->getMessage());
    }
    
    // Last resort - try wp_users if we haven't found anything yet
    if (!$user_data) {
        try {
            $stmt = $mysqli->prepare("SELECT * FROM wp_users WHERE ID = ? LIMIT 1");
            if ($stmt) {
                $table_tried = 'wp_users';
                $stmt->bind_param("i", $customer_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user_data = $result->fetch_assoc();
                    error_log("Found user in wp_users with ID: " . $customer_id);
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Error checking wp_users: " . $e->getMessage());
        }
    }
    
    // If we still don't have user data, but the invitation exists and is valid,
    // return success with minimal data
    if (!$user_data) {
        error_log("No user data found in any table for ID: " . $customer_id);
        
        // Construct a minimal customer object from invitation data
        $customer = [
            'id' => $customer_id,
            'email' => isset($invitation['email']) ? $invitation['email'] : ''
        ];
    } else {
        // Map the user data to a standard customer object
        $customer = [
            'id' => $user_data['id'] ?? $user_data['ID'] ?? $customer_id,
            'email' => $user_data['email'] ?? $user_data['user_email'] ?? '',
            'first_name' => $user_data['first_name'] ?? $user_data['display_name'] ?? '',
            'last_name' => $user_data['last_name'] ?? ''
        ];
    }
    
    error_log("Invitation check for token: " . substr($token, 0, 8) . "... - Result: valid");
    
    // Return success with invitation and customer data
    return [
        'success' => true,
        'invitation' => [
            'id' => $invitation['id'],
            'token' => $invitation['token'],
            'expires_at' => $invitation['expires_at'] ?? null,
            'created_at' => $invitation['created_at'] ?? null
        ],
        'customer' => $customer,
        'source_table' => $table_tried
    ];
}

// Mark an invitation as used
function mark_invitation_used($token) {
    global $mysqli;
    
    // Connect to database if not already connected
    if (!isset($mysqli) || $mysqli->connect_error) {
        $mysqli = new mysqli('localhost', 'root', '', 'charterhub_local');
        if ($mysqli->connect_error) {
            error_log("Database connection failed: " . $mysqli->connect_error);
            return false;
        }
    }
    
    // Begin transaction for data consistency
    $mysqli->begin_transaction();
    
    try {
        // Update both is_used and used columns for backward compatibility
        $stmt = $mysqli->prepare("
            UPDATE wp_charterhub_invitations 
            SET is_used = 1, used = 1, used_at = NOW() 
            WHERE token = ?
        ");
        
        if (!$stmt) {
            error_log("SQL Prepare Error: " . $mysqli->error);
            $mysqli->rollback();
            return false;
        }
        
        $stmt->bind_param("s", $token);
        $success = $stmt->execute();
        $affectedRows = $mysqli->affected_rows;
        $stmt->close();
        
        if (!$success) {
            error_log("Failed to mark invitation as used: " . $mysqli->error);
            $mysqli->rollback();
            return false;
        }
        
        // Log the action for audit purposes
        $stmt = $mysqli->prepare("
            INSERT INTO wp_charterhub_auth_logs 
            (action, status, details, created_at) 
            VALUES ('invitation_used', 'success', ?, NOW())
        ");
        
        if ($stmt) {
            $details = json_encode(['token' => substr($token, 0, 8) . '...', 'rows_affected' => $affectedRows]);
            $stmt->bind_param("s", $details);
            $stmt->execute();
            $stmt->close();
        }
        
        // Commit the transaction
        $mysqli->commit();
        
        error_log("Successfully marked invitation token " . substr($token, 0, 8) . "... as used. Affected rows: " . $affectedRows);
        return $affectedRows > 0;
    } catch (Exception $e) {
        // Rollback on error
        $mysqli->rollback();
        error_log("Exception marking invitation as used: " . $e->getMessage());
        return false;
    }
}

// Find and get active invitation by email
function get_active_invitation_by_email($email) {
    global $mysqli;
    
    // Connect to database if not already connected
    if (!isset($mysqli) || $mysqli->connect_error) {
        $mysqli = new mysqli('localhost', 'root', '', 'charterhub_local');
        if ($mysqli->connect_error) {
            error_log("Database connection failed: " . $mysqli->connect_error);
            return null;
        }
    }
    
    $stmt = $mysqli->prepare("
        SELECT id, token, customer_id, email, created_at, expires_at, is_used, used, used_at
        FROM wp_charterhub_invitations 
        WHERE LOWER(email) = LOWER(?) AND expires_at > NOW() AND is_used = 0 AND used = 0
        ORDER BY created_at DESC LIMIT 1
    ");
    
    if (!$stmt) {
        error_log("SQL Prepare Error: " . $mysqli->error);
        return null;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }
    
    $invitation = $result->fetch_assoc();
    $stmt->close();
    
    return $invitation;
}

// Log an invitation check for debugging
function log_invitation_check($token, $result) {
    error_log("Invitation check for token: " . substr($token, 0, 8) . "... - Result: " . ($result['success'] ? 'valid' : 'invalid - ' . $result['error']));
} 