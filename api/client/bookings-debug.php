<?php
/**
 * CharterHub Client Bookings Debug API
 * 
 * This is a diagnostic endpoint for the bookings API to help troubleshoot issues
 * It provides detailed information about the database, tables, and queries
 */

// Define CHARTERHUB_LOADED constant if not already defined
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include dependencies
require_once __DIR__ . '/../../utils/database.php';
require_once __DIR__ . '/../../auth/jwt-core.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set appropriate headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle options request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Output a JSON response
 * 
 * @param array $data The data to output
 * @param int $status HTTP status code
 */
function debug_json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// Get authorization header
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Main debugging information collector
$debug_info = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'user_id' => $user_id,
        'auth_header' => $auth_header ? substr($auth_header, 0, 20) . '...' : 'None'
    ]
];

try {
    // Try to connect to the database
    $debug_info['database'] = [
        'connection_attempted' => true,
        'status' => 'pending'
    ];
    
    $conn = getDbConnection();
    
    $debug_info['database']['status'] = 'connected';
    
    // Check for tables
    $tables = [];
    $tables_result = $conn->query("SHOW TABLES");
    
    if (!$tables_result) {
        $debug_info['database']['tables_error'] = $conn->errorInfo();
    } else {
        while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $debug_info['database']['tables'] = $tables;
        
        // Check for specific tables
        $bookings_table = null;
        if (in_array('wp_charterhub_bookings', $tables)) {
            $bookings_table = 'wp_charterhub_bookings';
        } else if (in_array('charterhub_bookings', $tables)) {
            $bookings_table = 'charterhub_bookings';
        }
        
        $debug_info['database']['bookings_table'] = $bookings_table;
        
        if ($bookings_table) {
            // Check table structure
            $columns = [];
            $describe_stmt = $conn->prepare("DESCRIBE {$bookings_table}");
            $describe_stmt->execute();
            
            while ($column = $describe_stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $column;
            }
            
            $debug_info['database']['bookings_columns'] = $columns;
            
            // Check column names for charterer identification
            $column_names = array_column($columns, 'Field');
            $debug_info['database']['has_customer_id'] = in_array('customer_id', $column_names);
            $debug_info['database']['has_main_charterer_id'] = in_array('main_charterer_id', $column_names);
            
            // Determine charterer column
            $charterer_column = in_array('main_charterer_id', $column_names) ? 'main_charterer_id' : 'customer_id';
            $debug_info['database']['charterer_column'] = $charterer_column;
            
            // Count bookings
            if ($user_id > 0) {
                try {
                    $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM {$bookings_table} WHERE {$charterer_column} = ?");
                    $count_stmt->execute([$user_id]);
                    $count = $count_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $debug_info['database']['bookings_count'] = $count['count'];
                    
                    // Try to get first booking
                    if ($count['count'] > 0) {
                        $booking_stmt = $conn->prepare("SELECT * FROM {$bookings_table} WHERE {$charterer_column} = ? LIMIT 1");
                        $booking_stmt->execute([$user_id]);
                        $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $debug_info['database']['sample_booking'] = $booking;
                    }
                } catch (Exception $e) {
                    $debug_info['database']['count_error'] = $e->getMessage();
                }
            }
        } else {
            $debug_info['database']['bookings_error'] = 'Bookings table not found';
        }
    }
    
    // Check token validation
    if ($auth_header) {
        try {
            $token = str_replace('Bearer ', '', $auth_header);
            $debug_info['auth']['token_provided'] = true;
            
            $payload = verify_token($token);
            if ($payload) {
                $debug_info['auth']['token_valid'] = true;
                $debug_info['auth']['user_id'] = $payload->sub;
                $debug_info['auth']['role'] = $payload->role;
                $debug_info['auth']['expiry'] = date('Y-m-d H:i:s', $payload->exp);
            } else {
                $debug_info['auth']['token_valid'] = false;
                $debug_info['auth']['error'] = 'Token verification failed';
            }
        } catch (Exception $e) {
            $debug_info['auth']['token_error'] = $e->getMessage();
        }
    } else {
        $debug_info['auth']['token_provided'] = false;
    }
    
    // Return diagnostic information
    debug_json_response($debug_info);
    
} catch (Exception $e) {
    debug_json_response([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ], 500);
} 