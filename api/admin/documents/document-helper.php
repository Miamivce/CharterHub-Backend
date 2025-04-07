<?php
/**
 * Document Helper Functions
 * 
 * Contains utility functions for document management
 */

// Define CHARTERHUB_LOADED to enable global CORS
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include required files
require_once __DIR__ . '/../../../auth/global-cors.php';
require_once __DIR__ . '/../../../auth/jwt-core.php';
require_once __DIR__ . '/../../../db-config.php';

// Set up CORS headers using the global function
apply_global_cors(['GET', 'POST', 'OPTIONS']);

/**
 * Get valid document types
 * 
 * @return array List of valid document types
 */
function getValidDocumentTypes() {
    return [
        'other',
        'captain_details',
        'passport',
        'passports',
        'itinerary',
        'crew_profile',
        'sample_menu',
        'preference_sheet',
        'payment_overview',
        'brochure',
        'proposal',
        'contract',
        'invoice',
        'receipt'
    ];
}

/**
 * Create document upload directory
 * 
 * @param int|null $userId User ID if document is associated with a user
 * @param int|null $bookingId Booking ID if document is associated with a booking
 * @param string $documentType Type of document
 * 
 * @return string|false Path to upload directory or false on failure
 */
function createDocumentUploadDirectory($userId, $bookingId, $documentType) {
    // NOTE: Updated to use the backend/uploads path instead of uploads at project root
    $basePath = __DIR__ . '/../../../uploads/documents';
    $uploadPath = '';
    
    // Log the base path
    error_log("Document upload base path: " . $basePath);
    
    // Check if base path exists
    if (!is_dir($basePath)) {
        error_log("Creating base upload directory: " . $basePath);
        if (!mkdir($basePath, 0777, true)) {
            error_log("Failed to create base upload directory: " . $basePath);
            $error = error_get_last();
            if ($error) {
                error_log("Error details: " . json_encode($error));
            }
            return false;
        }
    }
    
    // Determine path based on association
    if ($userId) {
        $uploadPath = $basePath . '/user_' . $userId . '/' . $documentType;
    } elseif ($bookingId) {
        $uploadPath = $basePath . '/booking_' . $bookingId . '/' . $documentType;
    } else {
        // Neither user nor booking specified - use general folder
        $uploadPath = $basePath . '/general/' . $documentType;
    }
    
    error_log("Document upload full path: " . $uploadPath);
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadPath)) {
        error_log("Creating document upload directory: " . $uploadPath);
        if (!mkdir($uploadPath, 0777, true)) {
            error_log("Failed to create document upload directory: " . $uploadPath);
            $error = error_get_last();
            if ($error) {
                error_log("Error details: " . json_encode($error));
            }
            return false;
        }
    }
    
    // Check if directory is writable
    if (!is_writable($uploadPath)) {
        error_log("Upload directory is not writable: " . $uploadPath);
        // Try to make it writable
        chmod($uploadPath, 0777);
        if (!is_writable($uploadPath)) {
            error_log("Failed to make upload directory writable: " . $uploadPath);
            return false;
        }
    }
    
    return $uploadPath;
}

/**
 * Generate a secure filename
 * 
 * @param string $originalFilename Original filename
 * @return string Secure filename with timestamp
 */
function generateSecureFilename($originalFilename) {
    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $timestamp = time();
    $randomString = bin2hex(random_bytes(8));
    
    // Create new filename
    $newFilename = $timestamp . '_' . $randomString . '.' . $extension;
    
    return $newFilename;
}

/**
 * Validate file type
 * 
 * @param string $mimeType MIME type to validate
 * @return bool True if valid, false otherwise
 */
function isValidFileType($mimeType) {
    $allowedTypes = [
        // PDF
        'application/pdf',
        
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/jpg',
        
        // Documents
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        
        // Archive
        'application/zip',
        'application/x-rar-compressed',
        
        // Other common types
        'application/octet-stream', // For testing - generic binary data
        'image/heic',
        'image/heif',
        ''  // Empty mime type (for testing)
    ];
    
    // Log the MIME type for debugging
    error_log("Validating MIME type: " . $mimeType);
    
    // For development/testing, accept all file types
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("Development mode: Accepting all file types");
        return true;
    }
    
    // Check if the MIME type is in the allowed list
    $result = in_array($mimeType, $allowedTypes);
    error_log("MIME type validation result: " . ($result ? 'Valid' : 'Invalid'));
    
    return $result;
}

/**
 * Get document by ID
 * 
 * @param int $documentId Document ID
 * @return array|null Document data or null if not found
 */
function getDocumentById($documentId) {
    try {
        $pdo = get_db_connection_from_config();
        
        $stmt = $pdo->prepare("
            SELECT * FROM wp_charterhub_documents
            WHERE id = :id
        ");
        $stmt->bindParam(':id', $documentId, PDO::PARAM_INT);
        $stmt->execute();
        
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            // Map database field names to the expected fields in the API
            return [
                'id' => $document['id'],
                'user_id' => $document['user_id'],
                'document_type' => 'other', // Default since we don't have this field
                'filename' => $document['title'],
                'file_path' => $document['file_path'],
                'mime_type' => $document['file_type'],
                'size' => $document['file_size'],
                'uploaded_at' => $document['created_at'],
                'updated_at' => $document['updated_at'],
                'visibility' => $document['visibility']
            ];
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Error fetching document: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if user has access to document
 * 
 * @param array $document Document data
 * @param array $user User data
 * @return bool True if user has access, false otherwise
 */
function userHasDocumentAccess($document, $user) {
    // Admin users have access to all documents
    if ($user['role'] === 'admin') {
        return true;
    }
    
    // Client users can only access their own documents
    if ($user['role'] === 'client') {
        // Check if document belongs to this user
        if ($document['user_id'] == $user['id']) {
            return true;
        }
        
        // TODO: Add logic for clients to access documents from their bookings
    }
    
    return false;
}

/**
 * Verify JWT token and get user info
 * 
 * @return array|false User payload if valid, false otherwise
 */
function verifyJWT() {
    // Check if we're in development mode and skip auth
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("DEVELOPMENT MODE: Skipping JWT verification");
        
        // Return a mock admin user for development
        return [
            'id' => 1,
            'email' => 'admin@example.com',
            'role' => 'admin',
            'first_name' => 'Dev',
            'last_name' => 'Admin'
        ];
    }
    
    // For production, use the get_authenticated_user function from jwt-core.php
    $user = get_authenticated_user(true, ['admin']);
    
    if (!$user) {
        // Authentication failed or user not authorized
        return false;
    }
    
    return $user;
}

/**
 * Get a valid wp_users ID for a given wp_charterhub_users ID
 * This is needed because the documents table has a foreign key constraint to wp_users
 * 
 * @param int $charterHubUserId User ID from wp_charterhub_users
 * @return int|null Valid user ID from wp_users or null if not found
 */
function getValidWpUserId($charterHubUserId) {
    try {
        $pdo = get_db_connection_from_config();
        
        // First, try to find a matching email in both tables
        $stmt = $pdo->prepare("
            SELECT wu.ID 
            FROM wp_users wu 
            JOIN wp_charterhub_users cu ON wu.user_email = cu.email
            WHERE cu.id = :charterhub_user_id
            LIMIT 1
        ");
        $stmt->bindParam(':charterhub_user_id', $charterHubUserId, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return $result['ID'];
        }
        
        // If that fails, just use the first admin user as a fallback
        $stmt = $pdo->prepare("
            SELECT ID FROM wp_users 
            WHERE ID = 1 
            LIMIT 1
        ");
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return $result['ID'];
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Error finding valid WP user ID: " . $e->getMessage());
        return null;
    }
} 