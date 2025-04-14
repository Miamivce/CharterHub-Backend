<?php
/**
 * Simplified Bookings API for Diagnostics
 * This is a minimal version to help diagnose server crashes
 */

// Buffer output
ob_start();

// Define constant for includes
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include essential dependencies for authentication and database
require_once __DIR__ . '/../../utils/database.php';
require_once __DIR__ . '/../../auth/jwt-core.php';

// Log memory usage
$memory_start = memory_get_usage();
error_log("MINIMAL-BOOKINGS: Starting with memory usage: " . $memory_start . " bytes");

// Basic error handling
set_error_handler(function($severity, $message, $file, $line) {
    // Clean output buffer
    if (ob_get_level()) ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'php_error',
        'message' => $message,
        'file' => $file,
        'line' => $line
    ]);
    exit;
}, E_ALL);

set_exception_handler(function($e) {
    // Clean output buffer
    if (ob_get_level()) ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
});

// Handle CORS properly for security
$incoming_origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Content-Type: application/json');

// Set specific origin if provided (for credentials support)
if ($incoming_origin !== '*') {
    header("Access-Control-Allow-Origin: $incoming_origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Extract auth header (more robust version)
    $auth_header = null;
    $token = null;
    
    // Method 1: Try getallheaders() function
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        
        foreach (['Authorization', 'authorization'] as $header_name) {
            if (isset($headers[$header_name])) {
                $auth_header = $headers[$header_name];
                break;
            }
        }
    }
    
    // Method 2: Try $_SERVER variables
    if (empty($auth_header)) {
        $server_vars = ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'];
        
        foreach ($server_vars as $var) {
            if (isset($_SERVER[$var]) && !empty($_SERVER[$var])) {
                $auth_header = $_SERVER[$var];
                break;
            }
        }
    }
    
    // Log auth header status
    $auth_status = !empty($auth_header) ? "Found: " . substr($auth_header, 0, 20) . "..." : "Not found";
    error_log("MINIMAL-BOOKINGS: Auth header - " . $auth_status);
    
    // Verify token if auth header exists (optional for diagnostic endpoint)
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $token_verified = false;
    
    if (!empty($auth_header) && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = $matches[1];
        
        try {
            // Use minimal token verification from jwt-core
            $payload = verify_token($token);
            if ($payload && isset($payload->sub)) {
                // Override user_id with the authenticated one for security
                $user_id = intval($payload->sub);
                $token_verified = true;
                error_log("MINIMAL-BOOKINGS: Token verified for user ID: $user_id");
            }
        } catch (Exception $auth_e) {
            // Just log the error, don't fail the diagnostic endpoint
            error_log("MINIMAL-BOOKINGS: Token verification error: " . $auth_e->getMessage());
        }
    }
    
    // Only proceed with verified token or explicit debug mode
    $debug_mode = isset($_GET['debug']) && $_GET['debug'] === 'true';
    
    if (!$token_verified && !$debug_mode) {
        // Return a generic error without details for security
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required',
            'error' => 'auth_required'
        ]);
        exit;
    }
    
    // Simplified database connection
    try {
        error_log("MINIMAL-BOOKINGS: Connecting to database");
        $conn = getDbConnection();
        error_log("MINIMAL-BOOKINGS: Database connected successfully");
        
        error_log("MINIMAL-BOOKINGS: User ID from request: " . $user_id);
        
        // Find booking table with proper escaping
        $tables_result = $conn->query("SHOW TABLES");
        $tables = [];
        while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Determine which booking table to use
        $booking_table = null;
        if (in_array('wp_charterhub_bookings', $tables)) {
            $booking_table = 'wp_charterhub_bookings';
        } else if (in_array('charterhub_bookings', $tables)) {
            $booking_table = 'charterhub_bookings';
        }
        
        error_log("MINIMAL-BOOKINGS: Using booking table: " . ($booking_table ?? 'none found'));
        
        // Get columns (simplified)
        $columns = [];
        if ($booking_table) {
            $desc_stmt = $conn->prepare("DESCRIBE " . $booking_table);
            $desc_stmt->execute();
            while ($row = $desc_stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
        }
        
        error_log("MINIMAL-BOOKINGS: Table columns: " . implode(", ", $columns));
        
        // Determine charterer column
        $charterer_column = in_array('customer_id', $columns) ? 'customer_id' : 'main_charterer_id';
        error_log("MINIMAL-BOOKINGS: Using charterer column: " . $charterer_column);
        
        // Simple count query only - no complex joins or data processing
        if ($booking_table && $user_id > 0) {
            // Get total count
            $count_query = "SELECT COUNT(*) as count FROM {$booking_table} WHERE {$charterer_column} = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->execute([$user_id]);
            $count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);
            $count = $count_data['count'];
            
            error_log("MINIMAL-BOOKINGS: Found {$count} bookings for user {$user_id}");
            
            // Get a few basic bookings for diagnostics (limit to 3)
            $sample_bookings = [];
            if ($count > 0) {
                $sample_query = "SELECT id, {$charterer_column}, start_date, end_date, status FROM {$booking_table} 
                                WHERE {$charterer_column} = ? ORDER BY start_date DESC LIMIT 3";
                $sample_stmt = $conn->prepare($sample_query);
                $sample_stmt->execute([$user_id]);
                $sample_bookings = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Success response with minimal data
            echo json_encode([
                'success' => true,
                'message' => 'Minimal bookings API working',
                'user_id' => $user_id,
                'token_verified' => $token_verified,
                'booking_count' => $count,
                'sample_bookings' => $sample_bookings,
                'memory_usage' => [
                    'start' => $memory_start,
                    'peak' => memory_get_peak_usage(),
                    'current' => memory_get_usage()
                ],
                'tables' => [
                    'booking_table' => $booking_table,
                    'charterer_column' => $charterer_column
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Incomplete data',
                'details' => [
                    'booking_table_found' => !empty($booking_table),
                    'user_id_provided' => $user_id > 0
                ]
            ]);
        }
    } catch (PDOException $e) {
        error_log("MINIMAL-BOOKINGS: Database error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'database_error',
            'message' => $e->getMessage()
        ]);
    }
} catch (Exception $e) {
    error_log("MINIMAL-BOOKINGS: Unexpected error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'unexpected_error',
        'message' => $e->getMessage()
    ]);
}

// End output and exit
if (ob_get_length()) ob_end_flush();
exit; 