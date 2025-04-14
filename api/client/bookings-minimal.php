<?php
/**
 * Simplified Bookings API for Diagnostics
 * This is a minimal version to help diagnose server crashes
 */

// Buffer output
ob_start();

// Set headers immediately
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Basic error handling
set_error_handler(function($severity, $message, $file, $line) {
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
    echo json_encode([
        'success' => false,
        'error' => 'exception',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
});

// Define constant for includes
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include only essential dependencies
require_once __DIR__ . '/../../utils/database.php';

// Log memory usage
$memory_start = memory_get_usage();
error_log("MINIMAL-BOOKINGS: Starting with memory usage: " . $memory_start . " bytes");

try {
    // Extract auth header (simplified)
    $auth_header = null;
    $headers = getallheaders();
    
    if (isset($headers['Authorization'])) {
        $auth_header = $headers['Authorization'];
    } else if (isset($headers['authorization'])) {
        $auth_header = $headers['authorization'];
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    // Just log auth header status - don't validate it in this diagnostic version
    $auth_status = !empty($auth_header) ? "Found: " . substr($auth_header, 0, 20) . "..." : "Not found";
    error_log("MINIMAL-BOOKINGS: Auth header - " . $auth_status);
    
    // Simplified database connection
    try {
        error_log("MINIMAL-BOOKINGS: Connecting to database");
        $conn = getDbConnection();
        error_log("MINIMAL-BOOKINGS: Database connected successfully");
        
        // Get user ID from request
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        error_log("MINIMAL-BOOKINGS: User ID from request: " . $user_id);
        
        // Find booking table
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
            $count_query = "SELECT COUNT(*) as count FROM {$booking_table} WHERE {$charterer_column} = ?";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->execute([$user_id]);
            $count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);
            $count = $count_data['count'];
            
            error_log("MINIMAL-BOOKINGS: Found {$count} bookings for user {$user_id}");
            
            // Success response with minimal data
            echo json_encode([
                'success' => true,
                'message' => 'Minimal bookings API working',
                'user_id' => $user_id,
                'booking_count' => $count,
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