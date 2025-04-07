<?php
/**
 * Document Download Endpoint (Refactored)
 * 
 * Uses the Document model and standardized responses
 */

// Define CHARTERHUB_LOADED before any includes to prevent "Direct access not allowed" messages
define('CHARTERHUB_LOADED', true);

// Include necessary files
require_once __DIR__ . '/../../../auth/global-cors.php';
require_once __DIR__ . '/../../../models/Document.php';
require_once __DIR__ . '/../../../helpers/Response.php';
require_once __DIR__ . '/../../../db-config.php';
require_once __DIR__ . '/../../../auth/jwt-core.php';

// Always send CORS headers first, before any potential errors or content
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin) {
    // Specifically allow localhost:3000 for development
    if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires");
    } else {
        // Call regular CORS handler for other origins
        apply_global_cors(['GET', 'POST', 'OPTIONS']);
    }
} else {
    // No origin, still apply CORS headers for API clients
    apply_global_cors(['GET', 'POST', 'OPTIONS']);
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::methodNotAllowed();
}

try {
    // Extract auth token from URL parameter, POST data, or header
    $token = null;
    
    // Check in various places for the token
    if (isset($_GET['auth_token'])) {
        $token = $_GET['auth_token'];
    } elseif (isset($_POST['auth_token'])) {
        $token = $_POST['auth_token'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }
    }
    
    // Verify the token manually if provided
    if ($token) {
        // Set token in a way that the authentication function can use it
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    
    // Get document ID from GET or POST
    $documentId = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0);
    
    if (!$documentId) {
        Response::validationError(['id' => 'Document ID is required']);
    }
    
    // Verify JWT token and get user info
    $userPayload = get_authenticated_user(true, ['admin']);
    if (!$userPayload) {
        Response::authError('Unauthorized access');
    }
    
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Create document model and find document
    $document = new Document($pdo);
    $document = $document->findById($documentId);
    
    if (!$document) {
        Response::notFound('Document not found');
    }
    
    // Get document data
    $documentData = $document->toArray(false);
    
    // Get file path
    $filePath = __DIR__ . '/../../../../' . $documentData['file_path'];
    
    // Check if file exists
    if (!file_exists($filePath)) {
        Response::notFound('Document file not found');
    }
    
    // Check if this is a download request or just viewing
    $isDownload = isset($_GET['download']) && $_GET['download'] == '1';
    if (!$isDownload) {
        $isDownload = isset($_POST['download']) && $_POST['download'] == '1';
    }
    
    // Clear output buffer before sending headers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set appropriate headers for download or viewing
    header('Content-Type: ' . $documentData['file_type']);
    
    if ($isDownload) {
        // Force download with attachment disposition
        header('Content-Disposition: attachment; filename="' . $documentData['title'] . '"');
    } else {
        // Inline viewing
        header('Content-Disposition: inline; filename="' . $documentData['title'] . '"');
    }
    
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Flush any remaining buffers
    flush();
    
    // Output file and exit
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    // Log errors
    error_log('Document download error: ' . $e->getMessage());
    
    // In case of error, ensure CORS headers are sent
    if ($origin) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
    }
    
    Response::serverError('Error downloading document', $e);
} 