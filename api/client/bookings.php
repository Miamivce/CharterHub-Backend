<?php
/**
 * Client Bookings API Endpoint
 * 
 * This endpoint handles client access to booking data with JWT authentication.
 * 
 * Supports:
 * - GET: Retrieve bookings where the authenticated user is either the main charterer or a guest
 * - POST: Create a new booking (currently empty, to be implemented)
 * 
 * Version: 1.1.4 - Improved output buffering and header handling
 */

// Start output buffering immediately
ob_start();

// Force JSON response type - even before anything else
header('Content-Type: application/json');

// Prevent any PHP errors from being displayed directly
@ini_set('display_errors', 0);
error_reporting(0);

// Define the CHARTERHUB_LOADED constant
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Capture any unexpected output from included files
$unexpected_output = ob_get_clean();
ob_start(); // Start a new buffer

// Include essential dependencies for debugging
require_once __DIR__ . '/../../utils/database.php';

// Simple debug endpoint that doesn't require authentication and bypasses CORS
if (isset($_GET['debug']) && $_GET['debug'] === 'connection_test') {
    // Debug data
    $debug_data = [
        'time' => date('Y-m-d H:i:s'),
        'request' => $_SERVER['REQUEST_URI'],
        'method' => $_SERVER['REQUEST_METHOD'],
    ];
    
    try {
        $conn = get_database_connection();
        $debug_data['status'] = 'connected';
        
        // Get available tables in database
        $tables_query = "SHOW TABLES";
        $tables_result = $conn->query($tables_query);
        $tables = [];
        
        while ($row = $tables_result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        $debug_data['available_tables'] = $tables;
        
        // Check which booking table exists
        $booking_table = '';
        if (in_array('wp_charterhub_bookings', $tables)) {
            $booking_table = 'wp_charterhub_bookings';
        } elseif (in_array('charterhub_bookings', $tables)) {
            $booking_table = 'charterhub_bookings';
        }
        
        $debug_data['bookings_table'] = $booking_table;
        
        // Get columns for booking table
        if (!empty($booking_table)) {
            $columns_query = "SHOW COLUMNS FROM $booking_table";
            $columns_result = $conn->query($columns_query);
            $columns = [];
            
            while ($row = $columns_result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            
            $debug_data['table_columns'] = $columns;
            
            // Determine which column is used for the charterer
            $charterer_column = 'customer_id';
            if (in_array('main_charterer_id', $columns)) {
                $charterer_column = 'main_charterer_id';
            } elseif (in_array('user_id', $columns)) {
                $charterer_column = 'user_id';
            }
            
            $debug_data['charterer_column'] = $charterer_column;
            
            // Check if customer_id column exists
            $debug_data['has_customer_id'] = in_array('customer_id', $columns);
            $debug_data['has_main_charterer_id'] = in_array('main_charterer_id', $columns);
            
            // Try to retrieve bookings count for a test user
            $test_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 505;
            $count_query = "SELECT COUNT(*) as count FROM $booking_table WHERE $charterer_column = ?";
            $stmt = $conn->prepare($count_query);
            $stmt->bind_param('i', $test_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count_data = $result->fetch_assoc();
            
            $debug_data['user_id_tested'] = $test_user_id;
            $debug_data['bookings_count'] = $count_data['count'];
        }
        
        // Check if token was provided
        $headers = apache_request_headers();
        $token_provided = isset($headers['Authorization']);
        $debug_data['token_provided'] = $token_provided;
        
    } catch (Exception $e) {
        $debug_data['status'] = 'error';
        $debug_data['message'] = $e->getMessage();
    }
    
    debug_json_response($debug_data);
}

// Process special debug requests before CORS handling
if (isset($_GET['debug']) && $_GET['debug'] === 'full_debug') {
    // This bypasses CORS for direct debugging in browser
    header('Content-Type: application/json');
    
    try {
        $conn = getDbConnection();
        
        // Show basic connection information
        $debug_info = [
            'success' => true,
            'php_version' => PHP_VERSION,
            'database_connected' => ($conn !== null),
            'server_time' => date('Y-m-d H:i:s'),
        ];
        
        // Get list of tables
        $tables = [];
        $tables_result = $conn->query("SHOW TABLES");
        while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Check for specific tables
        $debug_info['tables'] = $tables;
        $debug_info['has_bookings_table'] = in_array('wp_charterhub_bookings', $tables) || in_array('charterhub_bookings', $tables);
        
        // Try test query on the bookings table
        $bookings_table = in_array('wp_charterhub_bookings', $tables) ? 'wp_charterhub_bookings' : 'charterhub_bookings';
        
        // Get table structure
        $columns = [];
        $describe_stmt = $conn->prepare("DESCRIBE $bookings_table");
        $describe_stmt->execute();
        while ($row = $describe_stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row;
        }
        $debug_info['table_structure'] = $columns;
        
        // Try to see if there are any bookings
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM $bookings_table");
        $count_stmt->execute();
        $count = $count_stmt->fetch(PDO::FETCH_ASSOC);
        $debug_info['total_bookings'] = $count['count'];
        
        // Output debug info
        echo json_encode($debug_info, JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Debug query error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

// Detect if this is a debug request with a specific parameter
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    // Activate detailed error reporting for debugging only
    set_exception_handler(function($exception) {
    ob_clean();
        
        // Get detailed exception information
        $trace = $exception->getTraceAsString();
        $file = $exception->getFile();
        $line = $exception->getLine();
        $message = $exception->getMessage();
        
        // Log detailed information
        error_log("BOOKINGS.PHP - Detailed exception: $message in $file:$line");
        error_log("BOOKINGS.PHP - Stack trace: $trace");
        
        echo json_encode([
            'success' => false,
            'message' => 'Server exception (debug mode)',
            'error' => 'exception',
            'details' => $message,
            'file' => $file,
            'line' => $line,
            'trace' => explode("\n", $trace)
        ]);
        exit;
    });
    
    error_log("BOOKINGS.PHP - Debug mode activated");
}

// Include CORS dependencies for normal API usage
require_once __DIR__ . '/../../auth/global-cors.php';

// Apply CORS headers for all supported methods - with better error handling
try {
    // Record incoming request details for better debugging
    $incoming_origin = $_SERVER['HTTP_ORIGIN'] ?? 'none';
    $incoming_method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
    error_log("BOOKINGS.PHP - Request received from origin: {$incoming_origin}, method: {$incoming_method}");

    // Check if this is a direct debug or test request - allow it without CORS checks
    if (isset($_GET['debug']) || (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Mozilla') !== false)) {
        // For debug and browser requests, set specific origin (instead of wildcard) if provided
        if ($incoming_origin !== 'none') {
            header("Access-Control-Allow-Origin: $incoming_origin");
            header("Access-Control-Allow-Credentials: true");
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control');
        error_log("BOOKINGS.PHP - Debug/Test mode: Setting CORS headers for origin: {$incoming_origin}");
    } else {
        // Normal API operation with strict CORS
        $cors_result = apply_cors_headers(['GET', 'POST', 'OPTIONS']);
        if (!$cors_result) {
            error_log("BOOKINGS.PHP - CORS check failed for origin: {$incoming_origin}");
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'CORS error: Origin not allowed',
                'error' => 'cors_error',
                'origin' => $incoming_origin
            ]);
            exit;
        }
    }
} catch (Exception $cors_e) {
    error_log("BOOKINGS.PHP - CORS exception: " . $cors_e->getMessage());
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'CORS error: ' . $cors_e->getMessage(),
        'error' => 'cors_exception'
    ]);
    exit;
}

// Handle OPTIONS preflight immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'CORS preflight successful']);
    exit;
}

// Now we can include other dependencies
require_once __DIR__ . '/../../auth/jwt-auth.php';

// Set custom error handler to force JSON responses
set_error_handler(function($severity, $message, $file, $line) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => 'php_error',
        'details' => "$message in $file on line $line"
    ]);
    exit;
}, E_ALL);

// Set exception handler
set_exception_handler(function($exception) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Server exception',
        'error' => 'exception',
        'details' => $exception->getMessage()
    ]);
    exit;
});

/**
 * Get a reliable database connection for bookings
 * This function ensures we have a valid database connection with fallbacks
 */
function get_bookings_db_connection() {
    try {
        // First try the standard connection
        error_log("BOOKINGS.PHP - Attempting primary database connection");
        return getDbConnection();
    } catch (Exception $e) {
        error_log("BOOKINGS.PHP - Primary database connection failed: " . $e->getMessage());
        
        // Try a direct connection from config
        try {
            require_once __DIR__ . '/../../auth/config.php';
            error_log("BOOKINGS.PHP - Attempting config-based connection");
            return get_db_connection_from_config();
        } catch (Exception $config_e) {
            error_log("BOOKINGS.PHP - Config connection failed: " . $config_e->getMessage());
            
            // Last resort - try fallback connection from environment variables
            try {
                error_log("BOOKINGS.PHP - Attempting PDO fallback connection with environment variables");
                
                // Try to get credentials from environment first, if not available use the ones from debug
                $host = getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com';
                $port = getenv('DB_PORT') ?: '19174';
                $dbname = getenv('DB_NAME') ?: 'defaultdb';
                $username = getenv('DB_USER') ?: 'avnadmin';
                $password = getenv('DB_PASSWORD') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C';
                
                error_log("BOOKINGS.PHP - Using host: {$host}, port: {$port}, dbname: {$dbname}");
                
                // Build DSN
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname}";
                
                // Enable SSL with verification disabled for cloud database
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 10 // Increase timeout for potentially slow cloud DB
                ];
                
                // Disable SSL certificate verification (needed for cloud DBs)
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                    error_log("BOOKINGS.PHP - Disabled SSL certificate verification");
                }
                
                try {
                    error_log("BOOKINGS.PHP - Attempting emergency fallback connection");
                    $pdo = new PDO($dsn, $username, $password, $options);
                    
                    // Test the connection with a simple query
                    $test = $pdo->query("SELECT 1");
                    if (!$test) {
                        throw new Exception("Connection test query failed");
                    }
                    
                    error_log("BOOKINGS.PHP - Fallback connection successful");
                    return $pdo;
                } catch (PDOException $pdo_e) {
                    error_log("BOOKINGS.PHP - PDO exception during fallback connection: " . $pdo_e->getMessage());
                    throw new Exception("PDO exception: " . $pdo_e->getMessage());
                }
            } catch (Exception $fallback_e) {
                error_log("BOOKINGS.PHP - All database connection attempts failed: " . $fallback_e->getMessage());
                throw new Exception("Failed to establish any database connection: " . $fallback_e->getMessage());
            }
        }
    }
}

// Initialize response
$response = [
    'success' => false,
    'message' => 'Initializing request',
];

// Log that the endpoint was called
error_log("Client bookings.php endpoint called with method: " . $_SERVER['REQUEST_METHOD']);

// Verify JWT token for all requests
error_log("BOOKINGS.PHP - Starting token verification");
$auth_header = '';
if (isset(getallheaders()['Authorization'])) {
    $auth_header = getallheaders()['Authorization'];
    error_log("BOOKINGS.PHP - Authorization header found: " . substr($auth_header, 0, 20) . "...");
} else {
    error_log("BOOKINGS.PHP - No Authorization header found");
}

try {
    $user = verify_jwt_token();
    if (!$user) {
        error_log("BOOKINGS.PHP - JWT verification failed, unauthorized access");
        bookings_json_response([
            'success' => false,
            'message' => 'Unauthorized access'
        ], 401);
        exit;
    } else {
        error_log("BOOKINGS.PHP - JWT verification successful, user ID: " . $user['id']);
    }

    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handle_get_request($user);
            break;
        case 'POST':
            handle_post_request($user);
            break;
        default:
            bookings_json_response([
                'success' => false,
                'message' => 'Method not allowed'
            ], 405);
    }
} catch (Exception $e) {
    // Catch any unexpected errors and return proper JSON response
    error_log("BOOKINGS.PHP - Unexpected error: " . $e->getMessage());
    bookings_json_response([
        'success' => false,
        'message' => 'An unexpected error occurred',
        'error' => 'internal_server_error'
    ], 500);
}

/**
 * Handle GET request - Retrieve bookings for the authenticated user
 * 
 * @param array $user Authenticated user data
 */
function handle_get_request($user) {
    try {
        error_log("BOOKINGS.PHP - Starting GET request handler for user ID: " . $user['id']);
        
        // Check if we have unexpected output before any database operations
        $unexpected_output = ob_get_contents();
        if (!empty($unexpected_output)) {
            error_log("BOOKINGS.PHP - Unexpected output before database operations: " . $unexpected_output);
            ob_clean();
        }
        
        // Using our direct connection function with fallbacks
        $conn = get_bookings_db_connection();
        if (!$conn) {
            error_log("BOOKINGS.PHP - Failed to get database connection");
            throw new Exception("Database connection failed");
        }
        error_log("BOOKINGS.PHP - Database connection established");
        
        // Check again for unexpected output after database connection
        $unexpected_output = ob_get_contents();
        if (!empty($unexpected_output)) {
            error_log("BOOKINGS.PHP - Unexpected output after database connection: " . $unexpected_output);
            ob_clean();
        }
        
        // Get user ID from authenticated token
        $user_id = $user['id'];
        error_log("BOOKINGS.PHP - Processing request for user ID: {$user_id}");
        
        // Get specific booking if ID is provided
        $booking_id = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        // Determine the correct tables to use for queries based on what's available in the database
        $tables = [];
        $bookings_table = null;
        $yachts_table = null;
        $users_table = null;
        $guests_table = null;
        $charterer_column = 'customer_id'; // Default based on our debug info
        
        try {
            error_log("BOOKINGS.PHP - Finding database tables");
            $tables_result = $conn->query("SHOW TABLES");
            
            if (!$tables_result) {
                error_log("BOOKINGS.PHP - Error checking tables: " . implode(", ", $conn->errorInfo()));
                throw new Exception("Error checking database tables");
            }
            
            while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            // Set the default tables based on debug information
            if (in_array('wp_charterhub_bookings', $tables)) {
                $bookings_table = 'wp_charterhub_bookings';
                error_log("BOOKINGS.PHP - Using prefixed bookings table: {$bookings_table}");
            } else if (in_array('charterhub_bookings', $tables)) {
                $bookings_table = 'charterhub_bookings';
                error_log("BOOKINGS.PHP - Using non-prefixed bookings table: {$bookings_table}");
            } else {
                error_log("BOOKINGS.PHP - No bookings table found in: " . implode(", ", $tables));
                throw new Exception("Bookings table not found in database");
            }
            
            if (in_array('wp_charterhub_users', $tables)) {
                $users_table = 'wp_charterhub_users';
                error_log("BOOKINGS.PHP - Using prefixed users table: {$users_table}");
            } else if (in_array('charterhub_users', $tables)) {
                $users_table = 'charterhub_users';
                error_log("BOOKINGS.PHP - Using non-prefixed users table: {$users_table}");
            } else {
                error_log("BOOKINGS.PHP - No users table found");
                // Non-fatal, continue without users table
            }
            
            // For yacht details if available
            if (in_array('wp_charterhub_yachts', $tables)) {
                $yachts_table = 'wp_charterhub_yachts';
            } else if (in_array('charterhub_yachts', $tables)) {
                $yachts_table = 'charterhub_yachts';
            }
            
            // For guest information if available
            if (in_array('wp_charterhub_booking_guests', $tables)) {
                $guests_table = 'wp_charterhub_booking_guests';
            } else if (in_array('charterhub_booking_guests', $tables)) {
                $guests_table = 'charterhub_booking_guests';
            }
            
            // Determine the correct column name for the main charterer
            try {
                $describe_query = "DESCRIBE " . $bookings_table;
                $describe_stmt = $conn->prepare($describe_query);
                
                if (!$describe_stmt) {
                    error_log("BOOKINGS.PHP - Error preparing DESCRIBE statement: " . implode(", ", $conn->errorInfo()));
                    // Use default column name
                } else {
                    $describe_result = $describe_stmt->execute();
                    
                    if (!$describe_result) {
                        error_log("BOOKINGS.PHP - Error executing DESCRIBE statement: " . implode(", ", $describe_stmt->errorInfo()));
                        // Use default column name
                    } else {
                        $columns = $describe_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                        
                        error_log("BOOKINGS.PHP - Found columns in bookings table: " . implode(", ", $columns));
            
            // Check if main_charterer_id or customer_id is used
            if (in_array('main_charterer_id', $columns)) {
                $charterer_column = 'main_charterer_id';
                            error_log("BOOKINGS.PHP - Using main_charterer_id column");
                        } else if (in_array('customer_id', $columns)) {
                $charterer_column = 'customer_id';
                            error_log("BOOKINGS.PHP - Using customer_id column");
            } else {
                            error_log("BOOKINGS.PHP - No charterer column found, using default: {$charterer_column}");
                        }
                    }
                }
            } catch (Exception $column_e) {
                error_log("BOOKINGS.PHP - Error checking columns: " . $column_e->getMessage());
                // Non-fatal, continue with default column name
            }
            
            // Log the tables and column we're using
            error_log("BOOKINGS.PHP - Using tables: bookings={$bookings_table}, users={$users_table}, yachts={$yachts_table}, guests={$guests_table}");
            error_log("BOOKINGS.PHP - Using charterer column: {$charterer_column}");
            
            // Build a query based on the tables we have
            if ($booking_id) {
                // Return a specific booking
                $query = "SELECT b.* ";
                
                // Add formatted dates fields using MySQL DATE_FORMAT function if available
                try {
                    $date_format_test = $conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d') as test");
                    if ($date_format_test && $date_format_test->fetch()) {
                        $query .= ", DATE_FORMAT(b.start_date, '%Y-%m-%d') as formatted_start_date, 
                                  DATE_FORMAT(b.end_date, '%Y-%m-%d') as formatted_end_date";
                    }
                } catch (Exception $date_e) {
                    // Skip date formatting if it's not supported
                    error_log("BOOKINGS.PHP - Date formatting not supported: " . $date_e->getMessage());
                }
                
                // Add yacht details if available
                if ($yachts_table) {
                    $query .= ", y.name as yacht_name";
                }
                
                $query .= " FROM {$bookings_table} b";
                
                // Join yacht table if available
                if ($yachts_table) {
                    $query .= " LEFT JOIN {$yachts_table} y ON b.yacht_id = y.id";
                }
                
                $query .= " WHERE b.id = ? AND b.{$charterer_column} = ?";
                
                error_log("BOOKINGS.PHP - Specific booking query: " . $query);
                error_log("BOOKINGS.PHP - Params: booking_id=" . $booking_id . ", user_id=" . $user_id);
                
                // Prepare and execute query
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    error_log("BOOKINGS.PHP - Error preparing statement: " . implode(", ", $conn->errorInfo()));
                    throw new Exception("Database query preparation failed");
                }
                
                $result = $stmt->execute([$booking_id, $user_id]);
                if (!$result) {
                    error_log("BOOKINGS.PHP - Error executing statement: " . implode(", ", $stmt->errorInfo()));
                    throw new Exception("Database query execution failed");
                }
                
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$booking) {
                    // No booking found or not authorized
                    bookings_json_response([
                        'success' => false,
                        'message' => 'Booking not found or you do not have permission to view it',
                        'error' => 'not_found'
                    ], 404);
                    return;
                }
                
                // Add guest information if available
                if ($guests_table && $users_table) {
                    try {
                        $guests_query = "SELECT g.id as guest_booking_id, u.id as user_id, 
                                        u.first_name, u.last_name, u.email 
                                        FROM {$guests_table} g
                                        JOIN {$users_table} u ON g.user_id = u.id
                                        WHERE g.booking_id = ?";
                        
                        $guests_stmt = $conn->prepare($guests_query);
                        if ($guests_stmt) {
                            $guests_stmt->execute([$booking_id]);
                            $booking['guests'] = $guests_stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    } catch (Exception $guests_e) {
                        error_log("BOOKINGS.PHP - Error fetching guests: " . $guests_e->getMessage());
                        // Non-fatal, continue without guests
                    }
                }
                
                // Return response
                bookings_json_response([
                    'success' => true,
                    'message' => 'Booking retrieved successfully',
                    'data' => $booking
                ]);
            } else {
                // Return all bookings for the user
                $query = "SELECT b.* ";
                
                // Add formatted dates if supported
                try {
                    $date_format_test = $conn->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d') as test");
                    if ($date_format_test && $date_format_test->fetch()) {
                        $query .= ", DATE_FORMAT(b.start_date, '%Y-%m-%d') as formatted_start_date, 
                                  DATE_FORMAT(b.end_date, '%Y-%m-%d') as formatted_end_date";
                    }
                } catch (Exception $date_e) {
                    // Skip date formatting if it's not supported
                    error_log("BOOKINGS.PHP - Date formatting not supported: " . $date_e->getMessage());
                }
                
                // Add yacht details if available
                if ($yachts_table) {
                    $query .= ", y.name as yacht_name";
                }
                
                $query .= " FROM {$bookings_table} b";
                
                // Join yacht table if available
                if ($yachts_table) {
                    $query .= " LEFT JOIN {$yachts_table} y ON b.yacht_id = y.id";
                }
                
                $query .= " WHERE b.{$charterer_column} = ?";
                
                // Include any filters
                $status = isset($_GET['status']) ? $_GET['status'] : null;
                $params = [$user_id];
                
                if ($status) {
                    $query .= " AND b.status = ?";
                    $params[] = $status;
                }
                
                // Add ordering
                $query .= " ORDER BY b.start_date DESC";
                
                // Add limit and offset for pagination
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
                $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
                
                // Get total count for pagination
                $count_query = "SELECT COUNT(*) as total FROM {$bookings_table} WHERE {$charterer_column} = ?";
                $count_params = [$user_id];
                
                if ($status) {
                    $count_query .= " AND status = ?";
                    $count_params[] = $status;
                }
                
                error_log("BOOKINGS.PHP - Count query: " . $count_query);
                error_log("BOOKINGS.PHP - Count params: " . json_encode($count_params));
                
                $count_stmt = $conn->prepare($count_query);
                if (!$count_stmt) {
                    error_log("BOOKINGS.PHP - Error preparing count statement: " . implode(", ", $conn->errorInfo()));
                    throw new Exception("Database count query preparation failed");
                }
                
                $count_result = $count_stmt->execute($count_params);
                if (!$count_result) {
                    error_log("BOOKINGS.PHP - Error executing count statement: " . implode(", ", $count_stmt->errorInfo()));
                    throw new Exception("Database count query execution failed");
                }
                
                $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
                $total = $count_row ? intval($count_row['total']) : 0;
                
                // Now add limit and offset to main query
                $query .= " LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                // Debug log the query and params
                error_log("BOOKINGS.PHP - Query: {$query}");
                error_log("BOOKINGS.PHP - Params: " . json_encode($params));
                
                // Prepare and execute query
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    error_log("BOOKINGS.PHP - Error preparing statement: " . implode(", ", $conn->errorInfo()));
                    throw new Exception("Database query preparation failed");
                }
                
                $result = $stmt->execute($params);
                if (!$result) {
                    error_log("BOOKINGS.PHP - Error executing statement: " . implode(", ", $stmt->errorInfo()));
                    throw new Exception("Database query execution failed");
                }
                
                $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("BOOKINGS.PHP - Retrieved " . count($bookings) . " bookings");
                
                // Return response
                bookings_json_response([
                'success' => true,
                'message' => 'Bookings retrieved successfully',
                    'data' => $bookings,
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
            ]);
            }
        } catch (Exception $query_e) {
            error_log("BOOKINGS.PHP - Error in query processing: " . $query_e->getMessage());
            throw $query_e; // Rethrow to be caught by outer catch block
        }
    } catch (Exception $e) {
        error_log("BOOKINGS.PHP - Error in GET request: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("BOOKINGS.PHP - Stack trace: " . $e->getTraceAsString());
        
        // Return a more detailed error response for troubleshooting
        bookings_json_response([
            'success' => false,
            'message' => 'Error retrieving booking data: ' . $e->getMessage(),
            'error' => 'database_error',
            'details' => $e->getMessage(),
            'diagnostics' => [
                'php_version' => PHP_VERSION,
                'user_id' => isset($user_id) ? $user_id : 'unknown',
                'tables_found' => isset($tables) ? $tables : [],
                'bookings_table' => isset($bookings_table) ? $bookings_table : 'unknown',
                'charterer_column' => isset($charterer_column) ? $charterer_column : 'unknown',
                'columns_found' => isset($columns) ? $columns : []
            ]
        ], 500);
    }
}

/**
 * Handle POST request - Create a new booking
 * 
 * @param array $user Authenticated user data
 */
function handle_post_request($user) {
    try {
        // Get database connection using our reliable connection function
        $conn = get_bookings_db_connection();
        
        // Determine table names
        $bookings_table = 'wp_charterhub_bookings';
        $guests_table = 'wp_charterhub_booking_guests';
        
        try {
            // Look for bookings table with or without prefix
            $tables_query = "SHOW TABLES";
            $tables_result = $conn->query($tables_query);
            
            if ($tables_result) {
                while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
                    $table_name = $row[0];
                    
                    if (stripos($table_name, 'charterhub_bookings') !== false) {
                        $bookings_table = $table_name;
                        error_log("BOOKINGS POST - Found bookings table: " . $bookings_table);
                    }
                    
                    if (stripos($table_name, 'charterhub_booking_guests') !== false) {
                        $guests_table = $table_name;
                        error_log("BOOKINGS POST - Found booking guests table: " . $guests_table);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("BOOKINGS POST - Error finding tables: " . $e->getMessage());
            // Continue with defaults
        }
        
        // Parse request body
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            throw new Exception('Invalid request data');
        }
        
        // Validate required fields
        $required_fields = ['yachtId', 'startDate', 'endDate', 'mainCharterer'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        // Extract data
        $yacht_id = intval($data['yachtId']);
        $start_date = date('Y-m-d', strtotime($data['startDate']));
        $end_date = date('Y-m-d', strtotime($data['endDate']));
        $main_charterer = $data['mainCharterer'];
        $main_charterer_id = isset($main_charterer['id']) ? intval($main_charterer['id']) : null;
        $status = isset($data['status']) ? $data['status'] : 'pending';
        $total_price = isset($data['totalPrice']) ? floatval($data['totalPrice']) : 0;
        $guests = isset($data['guests']) ? $data['guests'] : [];
        
        // Begin transaction
        $conn->beginTransaction();
        
        // Determine the correct column name by checking table structure
        $charterer_column = 'main_charterer_id'; // Default column name
        try {
            $describe_query = "DESCRIBE " . $bookings_table;
            $describe_stmt = $conn->prepare($describe_query);
            $describe_stmt->execute();
            $columns = $describe_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            error_log("Booking table columns: " . implode(", ", $columns));
            
            // Check if main_charterer_id or customer_id is used
            $charterer_column = in_array('main_charterer_id', $columns) ? 'main_charterer_id' : 'customer_id';
            error_log("Using charterer column: " . $charterer_column);
        } catch (Exception $e) {
            error_log("Error determining column name, using default: " . $e->getMessage());
            // Continue with default column name
        }
        
        // Insert booking record using the correct column name
        $insert_booking_query = "INSERT INTO " . $bookings_table . " 
                               (yacht_id, start_date, end_date, status, total_price, {$charterer_column}, created_at, updated_at) 
                               VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($insert_booking_query);
        if (!$stmt) {
            throw new Exception("Failed to prepare booking insert: " . implode(", ", $conn->errorInfo()));
        }
        
        $result = $stmt->execute([
            $yacht_id, 
            $start_date, 
            $end_date, 
            $status, 
            $total_price, 
            $main_charterer_id
        ]);
        
        if (!$result) {
            throw new Exception("Failed to create booking: " . implode(", ", $stmt->errorInfo()));
        }
        
        // Get the new booking ID
        $booking_id = $conn->lastInsertId();
        
        // Add guests if provided
        $guest_ids = [];
        if (!empty($guests)) {
            $insert_guest_query = "INSERT INTO " . $guests_table . " (booking_id, user_id, created_at) VALUES (?, ?, NOW())";
            $guest_stmt = $conn->prepare($insert_guest_query);
            
            foreach ($guests as $guest) {
                if (isset($guest['id']) && !empty($guest['id'])) {
                    $guest_id = intval($guest['id']);
                    $result = $guest_stmt->execute([$booking_id, $guest_id]);
                    if (!$result) {
                        throw new Exception("Failed to add guest: " . implode(", ", $guest_stmt->errorInfo()));
                    }
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        bookings_json_response([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => [
                'id' => $booking_id,
                'startDate' => $start_date,
                'endDate' => $end_date,
                'status' => $status,
                'totalPrice' => $total_price,
                'yacht_id' => $yacht_id
            ]
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        error_log("Error in client bookings POST request: " . $e->getMessage());
        bookings_json_response([
            'success' => false,
            'message' => 'Error creating booking',
            'error' => 'database_error',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Send JSON response specifically for the bookings endpoint
 * 
 * @param array $data Response data
 * @param int $status HTTP status code
 */
function bookings_json_response($data, $status = 200) {
    // Clear any existing output
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Set headers
    header('Content-Type: application/json');
    http_response_code($status);
    
    // Handle JSON encoding errors
    try {
        // Sanitize data to prevent JSON encoding issues
        $sanitized_data = sanitize_data_for_json($data);
        
        // Encode data with error handling
        $json = json_encode($sanitized_data, JSON_PRETTY_PRINT);
        
        // Check for JSON encoding errors
        if ($json === false) {
            $json_error = json_last_error_msg();
            error_log("JSON encoding error: " . $json_error);
            
            // Provide a sanitized response
            echo json_encode([
                'success' => false,
                'message' => 'Error encoding response',
                'error' => 'json_encode_error',
                'error_message' => $json_error
            ], JSON_PRETTY_PRINT);
        } else {
            // Output successful JSON
            echo $json;
        }
    } catch (Exception $e) {
        // Fallback for any other errors
        error_log("Exception in json_response: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error while generating response',
            'error' => 'response_generation_error'
        ], JSON_PRETTY_PRINT);
    }
    
    // End output buffering
    if (ob_get_level()) {
        ob_end_flush();
    }
    
    exit;
}

/**
 * Recursively sanitize data to ensure it's JSON-encodable
 * 
 * @param mixed $data The data to sanitize
 * @return mixed The sanitized data
 */
function sanitize_data_for_json($data) {
    if (is_array($data)) {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = sanitize_data_for_json($value);
        }
        return $result;
    } elseif (is_object($data)) {
        // Convert objects to arrays for safer JSON encoding
        return sanitize_data_for_json((array)$data);
    } elseif (is_string($data)) {
        // Make sure strings are valid UTF-8
        return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    } elseif (is_resource($data)) {
        // Convert resources to their ID
        return 'resource#' . get_resource_type($data);
    } else {
        // Return scalars and nulls unchanged
        return $data;
    }
}

/**
 * Debug JSON Response
 * 
 * Properly formats and outputs JSON for debug endpoints with proper buffer handling
 * 
 * @param array $data The data to convert to JSON
 * @return void
 */
function debug_json_response($data) {
    // Clear any existing output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start fresh buffer
    ob_start();
    
    // Set appropriate headers - use specific origin if available
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
    header('Content-Type: application/json');
    header("Access-Control-Allow-Origin: $origin");
    
    // If a specific origin was set, allow credentials
    if ($origin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Encode and output the data
    echo json_encode($data, JSON_PRETTY_PRINT);
    
    // Flush buffer and exit
    ob_end_flush();
    exit();
}

// We're using the get_database_connection function from jwt-auth.php
// So we don't need to declare it here 