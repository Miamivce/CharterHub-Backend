<?php
/**
 * Document List API Endpoint
 * 
 * This endpoint retrieves documents associated with bookings or users.
 * 
 * Supports:
 * - GET: List documents by user ID, booking ID, or all documents for admin
 */

// Prevent any output before headers
@ini_set('display_errors', 0);
error_reporting(0); // Temporarily disable error reporting for CORS setup

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include global CORS handler first
require_once __DIR__ . '/../../../auth/global-cors.php';

// Apply CORS headers BEFORE any other operation
// Include OPTIONS method to support preflight requests
if (!apply_cors_headers(['GET', 'OPTIONS'])) {
    exit; // Exit if CORS headers could not be sent
}

// Handle OPTIONS requests immediately for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit; // The apply_cors_headers function already handles this, but just to be sure
}

// Re-enable error reporting now that CORS headers are sent
error_reporting(E_ALL);
@ini_set('display_errors', 1);

// Now include other files after CORS headers are sent
require_once __DIR__ . '/../../../auth/jwt-auth.php';
require_once __DIR__ . '/document-helper.php';

// Include necessary files
require_once __DIR__ . '/../../../models/Document.php';
require_once __DIR__ . '/../../../helpers/Response.php';
require_once __DIR__ . '/../../../constants/DocumentTypes.php';
require_once __DIR__ . '/../../../db-config.php';
require_once __DIR__ . '/../../../auth/jwt-core.php';

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