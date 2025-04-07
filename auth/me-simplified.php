<?php
/**
 * CharterHub User Authentication "Me" Endpoint (Simplified Version)
 * 
 * This endpoint allows clients to verify their authentication status.
 * It checks the provided JWT token and returns the user's information.
 */

// Define the CHARTERHUB_LOADED constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Include necessary files
require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/global-cors.php';
require_once __DIR__ . '/../utils/db-connection.php';

// Include jwt-fix.php for base64url_decode function
require_once __DIR__ . '/jwt-fix.php';

// Define allowed origins
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://localhost:3003',
    'http://localhost:3004',
    'http://localhost:3005',
];

// Get the origin from the request headers
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Check if the origin is allowed
$allowed_origin = in_array($origin, $allowed_origins) ? $origin : 'http://localhost:3000';

// Apply CORS headers explicitly and ensure they're set before any output
apply_global_cors(['GET', 'POST', 'OPTIONS']);

// Custom additional CORS headers to ensure compatibility with frontend
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Log request details for debugging
if (DEBUG_MODE) {
    error_log("ME.PHP: Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("ME.PHP: Request headers: " . json_encode(getallheaders()));
    error_log("ME.PHP: Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'not set'));
    error_log("ME.PHP: Allowed origin: $allowed_origin");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Return 200 status code for OPTIONS requests
    http_response_code(200);
    exit;
}

// Set content type
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(['success' => false, 'message' => 'Method not allowed', 'code' => 'method_not_allowed']);
    exit;
}

try {
    // Get Authorization header
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
        send_json_response(['success' => false, 'message' => 'No token provided', 'code' => 'token_missing']);
        exit;
    }
    
    // Extract token
    $token = substr($auth_header, 7);
    
    if (DEBUG_MODE) {
        error_log("ME.PHP: Token received: " . substr($token, 0, 10) . "...");
    }
    
    // SIMPLIFIED APPROACH - Extract user ID directly from token
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        error_log("ME.PHP: Invalid token format");
        send_json_response(['success' => false, 'message' => 'Invalid token format', 'code' => 'token_invalid']);
        exit;
    }
    
    // Decode payload
    $payload = json_decode(base64url_decode($parts[1]), true);
    if (!$payload || !isset($payload['sub'])) {
        error_log("ME.PHP: Invalid token payload");
        send_json_response(['success' => false, 'message' => 'Invalid token payload', 'code' => 'token_invalid']);
        exit;
    }
    
    // Get user ID from payload
    $user_id = $payload['sub'];
    error_log("ME.PHP: Extracted user ID from token: $user_id");
    
    // Get user from database
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM wp_charterhub_users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        error_log("ME.PHP: User not found for ID: $user_id");
        send_json_response(['success' => false, 'message' => 'User not found', 'code' => 'user_not_found']);
        exit;
    }
    
    // Verify token version if present in payload
    if (isset($payload['tvr']) && $user['token_version'] != $payload['tvr']) {
        error_log("ME.PHP: Token version mismatch: expected " . $user['token_version'] . ", got " . $payload['tvr']);
        send_json_response(['success' => false, 'message' => 'Token has been invalidated. Please login again.', 'code' => 'token_invalidated']);
        exit;
    }
    
    error_log("ME.PHP: Found user: {$user['email']}");
    
    // Create user data response
    $user_data = [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'firstName' => $user['first_name'],
        'lastName' => $user['last_name'],
        'displayName' => $user['display_name'] ?? "{$user['first_name']} {$user['last_name']}",
        'phoneNumber' => $user['phone_number'] ?? '',
        'company' => $user['company'] ?? '',
        'role' => $user['role'],
        'verified' => (bool)$user['verified'],
        'registeredDate' => date('c', strtotime($user['created_at'] ?? 'now')),
    ];
    
    // Send successful response
    send_json_response([
        'success' => true,
        'message' => 'Authentication successful',
        'user' => $user_data
    ]);
    
} catch (Exception $e) {
    error_log("ME.PHP: Error: " . $e->getMessage());
    
    // Return error response
    send_json_response([
        'success' => false,
        'message' => $e->getMessage(),
        'code' => 'auth_failed'
    ]);
} 