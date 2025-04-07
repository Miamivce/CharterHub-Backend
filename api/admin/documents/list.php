<?php
/**
 * Document List Endpoint (Refactored)
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
apply_global_cors(['GET', 'OPTIONS']);

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::methodNotAllowed();
}

// Enable development mode for testing
$isDevelopmentMode = false;

try {
    // Verify JWT token and get user info
    $userPayload = get_authenticated_user(true); // Allow both admin and client roles
    if (!$userPayload) {
        Response::authError('Unauthorized access');
    }

    // Check user role
    $isAdmin = in_array($userPayload['role'], ['admin', 'administrator']);

    // Get filter parameters
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
    $bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : null;
    $documentType = isset($_GET['document_type']) ? $_GET['document_type'] : null;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    
    // Validate limit (max 100)
    if ($limit > 100) {
        $limit = 100;
    }
    
    // Calculate offset
    $offset = ($page - 1) * $limit;
    
    // Prepare criteria for filtering
    $criteria = [];

    // If client user, restrict to their own documents
    if (!$isAdmin) {
        // For clients, always filter by their own user ID
        $criteria['user_id'] = $userPayload['sub'];
        
        // If client tries to request another user's documents, override with their own ID
        if ($userId && $userId != $userPayload['sub']) {
            $userId = $userPayload['sub'];
            error_log("Client attempted to view documents for user ID {$userId}, restricted to own documents");
        }
    } else if ($userId) {
        // For admins with user_id parameter, filter by specified user
        $criteria['user_id'] = $userId;
    }
    if ($bookingId) {
        $criteria['booking_id'] = $bookingId;
    }
    if ($documentType && DocumentTypes::isValid($documentType)) {
        $criteria['document_type'] = $documentType;
    }
    
    // Get database connection
    $pdo = get_db_connection_from_config();
    
    // Create document model
    $document = new Document($pdo);
    
    // Get documents based on criteria
    $result = $document->findByCriteria($criteria, $limit, $offset);
    
    // Format results for response
    $documents = [];
    foreach ($result['documents'] as $doc) {
        $documents[] = $doc->toArray();
    }
    
    // Send success response
    Response::success([
        'documents' => $documents,
        'pagination' => $result['pagination']
    ]);
    
} catch (Exception $e) {
    error_log("Document list error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::serverError('Error retrieving documents: ' . $e->getMessage(), $e);
} 