<?php
// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Include shared config and utilities
require_once 'config.php';
require_once 'global-cors.php'; // Apply CORS headers first
apply_global_cors(['POST', 'OPTIONS', 'PUT', 'PATCH']);
require_once 'jwt-fix.php';

// Start output buffering to prevent any unwanted output
ob_start();

// Add detailed logging for debugging
error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Script started");
error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Received request with method " . $_SERVER['REQUEST_METHOD']);
error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'not set'));
error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Authorization header present: " . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'yes' : 'no'));

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // OPTIONS requests already handled by apply_global_cors()
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Helper function to send JSON response
function send_profile_json_response($data, $status = 200) {
    // Clear any output buffering to prevent PHP comments
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Helper function to sanitize input data
function sanitize_input($data) {
    if ($data === null) {
        return null;
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Get the token from the Authorization header
$token = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        $token = $matches[1];
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Found token: " . substr($token, 0, 10) . "...");
    }
}

if (!$token) {
    error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: No token found in Authorization header");
    send_profile_json_response([
        'success' => false,
        'message' => 'Authentication required',
        'code' => 'auth_required'
    ], 401);
}

// Read and parse input data
$input_json = file_get_contents('php://input');
error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Input JSON: " . $input_json);

$data = json_decode($input_json, true);
if (!$data) {
    error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Invalid JSON input");
    send_profile_json_response([
        'success' => false,
        'message' => 'Invalid input data',
        'code' => 'invalid_input'
    ], 400);
}

// Verify the token and get user data
try {
    // Split the token into parts
    $token_parts = explode('.', $token);
    if (count($token_parts) !== 3) {
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Invalid token format");
        throw new Exception("Invalid token format");
    }
    
    // Decode the payload
    $payload_json = base64url_decode($token_parts[1]);
    $payload = json_decode($payload_json);
    
    if (!$payload || !isset($payload->sub)) {
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Invalid token payload");
        throw new Exception("Invalid token payload");
    }
    
    error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Token payload contains user ID: " . $payload->sub);
    
    // Get the user from the database
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM wp_charterhub_users WHERE id = ?");
    $stmt->execute([$payload->sub]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: User not found in database: " . $payload->sub);
        throw new Exception("User not found");
    }
    
    error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: User found in database: " . $user['email']);
    
    // Extract and sanitize profile data
    $first_name = sanitize_input($data['firstName'] ?? '');
    $last_name = sanitize_input($data['lastName'] ?? '');
    $email = sanitize_input($data['email'] ?? '');
    $phone_number = sanitize_input($data['phoneNumber'] ?? '');
    $company = sanitize_input($data['company'] ?? '');
    
    // Check if email is changing and validate new email
    $email_changing = false;
    $old_email = $user['email'];
    
    if ($email && $email !== $old_email) {
        $email_changing = true;
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            send_profile_json_response([
                'success' => false,
                'message' => 'Invalid email format'
            ], 400);
        }
        
        // Check if new email is already in use by another user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM wp_charterhub_users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $payload->sub]);
        if ($stmt->fetchColumn() > 0) {
            send_profile_json_response([
                'success' => false,
                'message' => 'Email already in use by another account'
            ], 400);
        }
        
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Email change detected: {$old_email} -> {$email}");
    }
    
    // Prepare update data
    $update_parts = [];
    
    if (!empty($first_name)) {
        $update_parts[] = "first_name = " . $pdo->quote($first_name);
    }
    
    if (!empty($last_name)) {
        $update_parts[] = "last_name = " . $pdo->quote($last_name);
    }
    
    if (!empty($email)) {
        $update_parts[] = "email = " . $pdo->quote($email);
    }
    
    if ($phone_number !== null) {
        $update_parts[] = "phone_number = " . $pdo->quote($phone_number);
    }
    
    if ($company !== null) {
        $update_parts[] = "company = " . $pdo->quote($company);
    }
    
    if (empty($update_parts)) {
        send_profile_json_response([
            'success' => false,
            'message' => 'No fields to update'
        ], 400);
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Build SQL query
        $sql = "UPDATE wp_charterhub_users SET " . implode(", ", $update_parts) . " WHERE id = " . intval($payload->sub);
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: SQL: " . $sql);
        
        // Execute the query
        $rows_affected = $pdo->exec($sql);
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Rows affected: " . $rows_affected);
        
        // Verify the update
        $verify_stmt = $pdo->prepare("SELECT * FROM wp_charterhub_users WHERE id = ?");
        $verify_stmt->execute([$payload->sub]);
        $updated_user = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Commit the transaction
        $pdo->commit();
        
        // Format the user data for response
        $user_data = [
            'id' => (int)$updated_user['id'],
            'email' => $updated_user['email'],
            'firstName' => $updated_user['first_name'],
            'lastName' => $updated_user['last_name'],
            'displayName' => trim($updated_user['first_name'] . ' ' . $updated_user['last_name']),
            'phoneNumber' => $updated_user['phone_number'] ?? '',
            'company' => $updated_user['company'] ?? '',
            'role' => $updated_user['role'],
            'verified' => (bool)$updated_user['verified']
        ];
        
        // Send success response
        send_profile_json_response([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user_data
        ]);
        
    } catch (Exception $e) {
        // Roll back transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Error: " . $e->getMessage());
        
        send_profile_json_response([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ], 500);
    }
    
} catch (Exception $e) {
    error_log("UPDATE-PROFILE-SIMPLIFIED.PHP: Error: " . $e->getMessage());
    
    send_profile_json_response([
        'success' => false,
        'message' => 'Authentication failed: ' . $e->getMessage(),
        'code' => 'auth_failed'
    ], 401);
} 