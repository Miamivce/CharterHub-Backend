<?php
/**
 * CharterHub Client Bookings Debug API
 * 
 * This is a diagnostic endpoint for the bookings API to help troubleshoot issues
 * It provides detailed information about the database, tables, and queries
 */

// Start output buffering immediately
ob_start();

// Define CHARTERHUB_LOADED constant first
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Prevent direct output
error_reporting(0);
@ini_set('display_errors', 0);

// Include required files
require_once '../../config/config.php';
require_once '../../utils/database.php';
require_once '../../utils/ensure-views.php';
require_once '../../utils/auth.php';

// Capture any unexpected output from included files
$unexpected_output = ob_get_clean();

// Reset output buffer
ob_start();

// Now we can enable error reporting for our script
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize debug info with server information
$debug_info = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'user_id' => isset($_GET['user_id']) ? intval($_GET['user_id']) : 0,
        'auth_header' => null,
        'header_methods' => [
            'getallheaders' => function_exists('getallheaders'),
            'apache_request_headers' => function_exists('apache_request_headers')
        ]
    ]
];

// Add server information to debug output
$debug_info['server_info'] = [
    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'php_sapi' => PHP_SAPI,
    'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'unknown'
];

// If there was unexpected output, add it to the debug info
if (!empty($unexpected_output)) {
    $debug_info['warnings'] = [
        'unexpected_output' => substr($unexpected_output, 0, 500), // Limit to 500 chars
        'output_source' => 'Likely from an included file'
    ];
}

// Include additional dependencies
require_once __DIR__ . '/../../auth/jwt-core.php';

// Function to safely output JSON and exit
function debug_json_response($data, $status_code = 200) {
    // Clean any previous output by flushing all buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start fresh output buffer
    ob_start();
    
    // Set appropriate headers - handle specific origin for credentials
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
    
    // Set headers
    http_response_code($status_code);
    header('Content-Type: application/json');
    header("Access-Control-Allow-Origin: $origin");
    
    // If using a specific origin, allow credentials
    if ($origin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }
    
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Output JSON
    echo json_encode($data, JSON_PRETTY_PRINT);
    
    // Flush buffer and exit
    ob_end_flush();
    exit;
}

// Handle CORS OPTIONS request immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    debug_json_response([
        'success' => true,
        'message' => 'CORS preflight request successful'
    ], 200);
}

// Get authorization header using multiple methods for maximum compatibility
$auth_header = null;

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

// Method 2: Try apache_request_headers() if available
if (empty($auth_header) && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    foreach (['Authorization', 'authorization'] as $header_name) {
        if (isset($headers[$header_name])) {
            $auth_header = $headers[$header_name];
            break;
        }
    }
}

// Method 3: Try $_SERVER variables
if (empty($auth_header)) {
    $server_vars = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_X_AUTHORIZATION',
        'HTTP_BEARER',
        'AUTHORIZATION'
    ];
    
    foreach ($server_vars as $var) {
        if (isset($_SERVER[$var])) {
            $auth_header = $_SERVER[$var];
            break;
        }
    }
}

// Method 4: Check query string (less secure but useful for debugging)
if (empty($auth_header) && isset($_GET['token'])) {
    $auth_header = 'Bearer ' . $_GET['token'];
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Update request info
$debug_info['request']['auth_header'] = $auth_header ? substr($auth_header, 0, 20) . '...' : 'None';
$debug_info['request']['user_id'] = $user_id;

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
            $token = null;
            // Extract the token from the Authorization header
            if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                $token = $matches[1];
                $debug_info['auth']['token_found'] = true;
                $debug_info['auth']['token_length'] = strlen($token);
            } else {
                $debug_info['auth']['token_found'] = false;
                $debug_info['auth']['error'] = 'No Bearer token found in Authorization header';
            }
            
            $debug_info['auth']['token_provided'] = true;
            
            if ($token) {
                try {
                    // Try verify_token first (from jwt-core.php)
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
                } catch (Exception $verify_e) {
                    $debug_info['auth']['token_valid'] = false;
                    $debug_info['auth']['verify_error'] = $verify_e->getMessage();
                    
                    // Add more detailed diagnostics about the token
                    try {
                        $parts = explode('.', $token);
                        if (count($parts) === 3) {
                            $debug_info['auth']['token_format'] = 'Valid JWT format (3 parts)';
                            
                            // Try to decode the header
                            $header_json = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[0]));
                            if ($header_json) {
                                $header = json_decode($header_json);
                                if ($header) {
                                    $debug_info['auth']['token_header'] = $header;
                                }
                            }
                        } else {
                            $debug_info['auth']['token_format'] = 'Invalid JWT format (not 3 parts)';
                        }
                    } catch (Exception $token_e) {
                        $debug_info['auth']['token_decode_error'] = $token_e->getMessage();
                    }
                }
            } else {
                $debug_info['auth']['error'] = 'Unable to extract token from authorization header';
            }
        } catch (Exception $e) {
            $debug_info['auth']['token_error'] = $e->getMessage();
        }
    } else {
        $debug_info['auth']['token_provided'] = false;
    }
    
    // Return diagnostic information
    debug_json_response($debug_info);
    
} catch (PDOException $e) {
    $debug_info['success'] = false;
    $debug_info['error'] = [
        'message' => 'Database connection failed',
        'details' => $e->getMessage(),
        'code' => $e->getCode(),
        'trace' => explode("\n", $e->getTraceAsString())
    ];
    
    debug_json_response($debug_info, 500);
} 