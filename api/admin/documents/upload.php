<?php
/**
 * Document Upload Endpoint (Refactored)
 * 
 * Uses the Document model and standardized responses
 */

// Define CHARTERHUB_LOADED for global CORS
define('CHARTERHUB_LOADED', true);

// Include necessary files
require_once __DIR__ . '/../../../auth/global-cors.php';
require_once __DIR__ . '/../../../models/Document.php';
require_once __DIR__ . '/../../../helpers/Response.php';
require_once __DIR__ . '/../../../constants/DocumentTypes.php';
require_once __DIR__ . '/../../../db-config.php';
require_once __DIR__ . '/../../../auth/jwt-core.php';

// Apply CORS for the endpoint
apply_global_cors(['POST', 'OPTIONS']);

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    // Validate inputs
    $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $bookingId = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    
    // Either user_id or booking_id should be provided
    if (!$userId && !$bookingId) {
        Response::validationError(['user_id' => 'Either user_id or booking_id is required']);
    }

    $documentType = isset($_POST['document_type']) ? $_POST['document_type'] : null;
    if (!$documentType || !DocumentTypes::isValid($documentType)) {
        $validTypesStr = implode(', ', DocumentTypes::getAllTypes());
        Response::validationError([
            'document_type' => 'Valid document type is required. Allowed types: ' . $validTypesStr
        ]);
    }

    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'No file uploaded';
        if (isset($_FILES['file'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMessage = 'File is too large';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMessage = 'File was only partially uploaded';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMessage = 'No file was uploaded';
                    break;
                default:
                    $errorMessage = 'Unknown upload error: ' . $_FILES['file']['error'];
                    break;
            }
        }
        Response::validationError(['file' => $errorMessage]);
    }

    // Get optional parameters
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    $title = isset($_POST['title']) ? $_POST['title'] : '';
    
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Get user information for the uploaded_by field
    $uploaded_by = $userPayload['sub']; // Default to authenticated user's ID

    // Check if uploaded_by_user_id was provided in the request
    // This allows client uploads to be properly attributed to clients
    if (isset($_POST['uploaded_by_user_id']) && intval($_POST['uploaded_by_user_id']) > 0) {
        $uploaded_by = intval($_POST['uploaded_by_user_id']);
        
        // Log the override for debugging
        error_log("Upload attribution override: Using uploaded_by_user_id {$uploaded_by} instead of authenticated user {$userPayload['sub']}");
    }

    $uploaderStmt = $pdo->prepare("SELECT first_name, last_name FROM wp_charterhub_users WHERE id = ?");
    $uploaderStmt->execute([$uploaded_by]);
    $uploader = $uploaderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$uploader) {
        // Fall back to authenticated user if uploader not found
        error_log("Uploader with ID {$uploaded_by} not found, falling back to authenticated user");
        $uploaded_by = $userPayload['sub'];
        
        $uploaderStmt = $pdo->prepare("SELECT first_name, last_name FROM wp_charterhub_users WHERE id = ?");
        $uploaderStmt->execute([$uploaded_by]);
        $uploader = $uploaderStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$uploader) {
            Response::notFound('Uploader information not found');
        }
    }

    $uploaderName = $uploader['first_name'] . ' ' . $uploader['last_name'];

    // Create and populate document model
    $document = new Document($pdo);
    $document->fromArray([
        'document_type' => $documentType,
        'title' => $title,
        'notes' => $notes,
        'booking_id' => $bookingId ? $bookingId : null,
        'uploaded_by' => $uploaded_by, // Store user ID
        'uploader_name' => $uploaderName, // Store formatted name
        'user_id' => $userId ? $userId : null,
        'visibility' => 'private'
    ]);

    // Process file upload
    $filePath = $document->handleFileUpload($_FILES['file']);
    if (!$filePath) {
        Response::serverError('Failed to upload file');
    }

    // Save document to database
    if (!$document->save()) {
        Response::serverError('Failed to save document to database');
    }

    // Return success response
    Response::success(
        $document->toArray(),
        201,
        'Document uploaded successfully'
    );

} catch (Exception $e) {
    error_log("Document upload error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('Error uploading document: ' . $e->getMessage(), $e);
} 