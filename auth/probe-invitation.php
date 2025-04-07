<?php
/**
 * Invitation Token Probe
 * 
 * Ultra-lightweight endpoint to check if a token exists in the system
 * without doing full validation. Used for fallback token checking.
 */

// Set the CHARTERHUB_LOADED constant to allow include files to run
define('CHARTERHUB_LOADED', true);

// Include the global CORS handler
require_once dirname(__FILE__) . '/global-cors.php';
apply_global_cors(['GET', 'OPTIONS']);

// Include required files
require_once dirname(__FILE__) . '/../db-config.php';

// Set proper content type header
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Ensure this endpoint only handles GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get token from query parameters
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'tokenExists' => false, 'message' => 'No token provided']);
    exit;
}

try {
    // Get database connection directly from db-config.php
    $conn = get_db_connection_from_config();
    
    // Prepare a simple query to check if the token exists
    $stmt = $conn->prepare("SELECT id, used FROM wp_charterhub_invitations WHERE token = ?");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invitation) {
        $used = (bool)$invitation['used'];
        echo json_encode([
            'success' => true,
            'tokenExists' => true,
            'used' => $used,
            'status' => $used ? 'used' : 'valid',
            'message' => $used ? 'Token exists but has already been used' : 'Token exists in the system'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'tokenExists' => false,
            'message' => 'Token not found'
        ]);
    }
} catch (Exception $e) {
    // In case of database error, provide a simple fallback response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'tokenExists' => false,
        'message' => 'Error checking token',
        'error' => DEVELOPMENT_MODE ? $e->getMessage() : 'Internal server error'
    ]);
} 