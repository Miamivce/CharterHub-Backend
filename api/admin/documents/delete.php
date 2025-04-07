<?php
/**
 * Document Delete Endpoint (Refactored)
 * 
 * Uses the Document model and standardized responses
 */

// Define CHARTERHUB_LOADED for global CORS
define('CHARTERHUB_LOADED', true);

// Include necessary files
require_once __DIR__ . '/../../../auth/global-cors.php';
require_once __DIR__ . '/../../../models/Document.php';
require_once __DIR__ . '/../../../helpers/Response.php';
require_once __DIR__ . '/../../../db-config.php';
require_once __DIR__ . '/../../../auth/jwt-core.php';

// Apply CORS for the endpoint
apply_global_cors(['POST', 'DELETE', 'OPTIONS']);

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Response::methodNotAllowed();
}

// Enable development mode for testing
$isDevelopmentMode = true;

try {
    // In development mode, skip JWT verification
    if (!$isDevelopmentMode) {
        // Verify JWT token and get user info
        $userPayload = get_authenticated_user(true, ['admin']);
        if (!$userPayload) {
            Response::authError('Unauthorized access');
        }
    } else {
        // Mock user payload for development
        $userPayload = [
            'sub' => 14,
            'email' => 'admin@charterhub.com',
            'role' => 'admin'
        ];
    }

    // Get document ID from post or JSON body
    $documentId = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $documentId = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
    } else {
        // For DELETE method, try to parse JSON body
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        $documentId = isset($data['document_id']) ? intval($data['document_id']) : 0;
    }
    
    if (!$documentId) {
        Response::validationError(['document_id' => 'Document ID is required']);
    }
    
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Create document model and find document
    $document = new Document($pdo);
    $document = $document->findById($documentId);
    
    if (!$document) {
        Response::notFound('Document not found');
    }
    
    // Delete document
    if (!$document->delete()) {
        Response::serverError('Failed to delete document');
    }
    
    // Return success response
    Response::success([], 200, 'Document deleted successfully');
    
} catch (Exception $e) {
    error_log("Document delete error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('Error deleting document: ' . $e->getMessage(), $e);
} 