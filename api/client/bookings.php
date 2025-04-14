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
 * Version: 1.1.3 - Fixed strict content-type and HTML prevention
 */

// Force JSON response type - even before anything else
header('Content-Type: application/json');

// Prevent any PHP errors from being displayed directly
@ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering
ob_start();

// Define the CHARTERHUB_LOADED constant
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include essential dependencies for debugging
require_once __DIR__ . '/../../utils/database.php';

// Simple debug endpoint that doesn't require authentication and bypasses CORS
if (isset($_GET['debug']) && $_GET['debug'] === 'connection_test') {
    // Re-enable error display for this debug endpoint
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        // Skip authentication for this endpoint
        error_log("BOOKINGS.PHP - Running connection test debug endpoint");
        
        // Test basic database connectivity using the new connection function
        $conn = get_bookings_db_connection();
        
        if (!$conn) {
            // Special response for debug endpoint - no CORS needed
            echo json_encode([
                'success' => false,
                'message' => 'Failed to connect to database',
                'php_version' => PHP_VERSION,
                'server_time' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
        
        // Get complete list of tables
        $tables = [];
        $tables_result = $conn->query("SHOW TABLES");
        
        if ($tables_result) {
            while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
        }
        
        // Find potential booking tables
        $booking_tables = [];
        foreach ($tables as $table) {
            if (strpos(strtolower($table), 'booking') !== false) {
                $booking_tables[] = $table;
            }
        }
        
        // Find potential user tables
        $user_tables = [];
        foreach ($tables as $table) {
            if (strpos(strtolower($table), 'user') !== false) {
                $user_tables[] = $table;
            }
        }
        
        // Find the best bookings table
        $bookings_table = null;
        foreach ($booking_tables as $table) {
            if (strpos($table, 'charterhub_bookings') !== false) {
                $bookings_table = $table;
                break;
            }
        }
        
        if (!$bookings_table && !empty($booking_tables)) {
            $bookings_table = $booking_tables[0];
        }
        
        // Get booking table structure if found
        $booking_columns = [];
        if ($bookings_table) {
            $describe_result = $conn->query("DESCRIBE `{$bookings_table}`");
            
            if ($describe_result) {
                while ($row = $describe_result->fetch(PDO::FETCH_ASSOC)) {
                    $booking_columns[] = $row['Field'];
                }
            }
        }
        
        // Determine charterer column
        $charterer_column = 'unknown';
        if (in_array('main_charterer_id', $booking_columns)) {
            $charterer_column = 'main_charterer_id';
        } elseif (in_array('customer_id', $booking_columns)) {
            $charterer_column = 'customer_id';
        }
        
        // Check if sample query would work
        $query_check = [
            'would_work' => false,
            'error' => null
        ];
        
        try {
            if ($bookings_table) {
                // Try to prepare the booking query with a sample user
                $test_user_id = 1;
                
                $test_query = "SELECT id FROM `{$bookings_table}` WHERE {$charterer_column} = ? LIMIT 1";
                $stmt = $conn->prepare($test_query);
                
                if ($stmt) {
                    $stmt->execute([$test_user_id]);
                    $query_check['would_work'] = true;
                }
            }
        } catch (Exception $qe) {
            $query_check['error'] = $qe->getMessage();
        }
        
        // Add bookings diagnosis for current user if provided
        $user_bookings_check = null;
        if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            $test_user_id = (int)$_GET['user_id'];
            try {
                $user_query = "SELECT * FROM `{$bookings_table}` WHERE {$charterer_column} = ? LIMIT 5";
                $user_stmt = $conn->prepare($user_query);
                
                if ($user_stmt) {
                    $user_stmt->execute([$test_user_id]);
                    $rows = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $user_bookings_check = [
                        'user_id' => $test_user_id,
                        'found_bookings' => count($rows),
                        'sample_rows' => $rows
                    ];
                }
            } catch (Exception $ue) {
                $user_bookings_check = [
                    'user_id' => $test_user_id,
                    'error' => $ue->getMessage()
                ];
            }
        }
        
        // Database connection information
        $db_info = [
            'driver' => $conn->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_version' => $conn->getAttribute(PDO::ATTR_SERVER_VERSION),
            'client_version' => $conn->getAttribute(PDO::ATTR_CLIENT_VERSION),
            'connection_status' => $conn->getAttribute(PDO::ATTR_CONNECTION_STATUS)
        ];
        
        // Server environment
        $server_env = [
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
        
        // Direct output for debug endpoint - no CORS needed
        echo json_encode([
            'success' => true,
            'message' => 'Database connection test complete',
            'db_connection' => 'Connected successfully',
            'tables_count' => count($tables),
            'booking_tables_found' => $booking_tables,
            'user_tables_found' => $user_tables,
            'best_bookings_table' => $bookings_table,
            'booking_columns' => $booking_columns,
            'charterer_column' => $charterer_column,
            'query_test' => $query_check,
            'user_bookings_check' => $user_bookings_check,
            'database_info' => $db_info,
            'server_environment' => $server_env
        ]);
        exit;
    } catch (Exception $e) {
        // Direct output for debug endpoint - no CORS needed
        echo json_encode([
            'success' => false,
            'message' => 'Error in database test',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'php_version' => PHP_VERSION,
            'trace' => $e->getTraceAsString()
        ]);
        exit;
    }
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

// Apply CORS headers with proper cleaning
if (ob_get_length()) {
    ob_clean();
}

// Record incoming request details for better debugging
$incoming_origin = $_SERVER['HTTP_ORIGIN'] ?? 'none';
$incoming_method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
error_log("BOOKINGS.PHP - Request received from origin: {$incoming_origin}, method: {$incoming_method}");

// Apply CORS headers for all supported methods - with better error handling
try {
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
            if (in_array('charterhub_bookings', $tables)) {
                $bookings_table = 'charterhub_bookings';
                error_log("BOOKINGS.PHP - Using non-prefixed bookings table: {$bookings_table}");
            } else if (in_array('wp_charterhub_bookings', $tables)) {
                $bookings_table = 'wp_charterhub_bookings';
                error_log("BOOKINGS.PHP - Using prefixed bookings table: {$bookings_table}");
            } else {
                error_log("BOOKINGS.PHP - No bookings table found in: " . implode(", ", $tables));
                throw new Exception("Bookings table not found in database");
            }
            
            if (in_array('charterhub_users', $tables)) {
                $users_table = 'charterhub_users';
                error_log("BOOKINGS.PHP - Using non-prefixed users table: {$users_table}");
            } else if (in_array('wp_charterhub_users', $tables)) {
                $users_table = 'wp_charterhub_users';
                error_log("BOOKINGS.PHP - Using prefixed users table: {$users_table}");
            } else {
                error_log("BOOKINGS.PHP - No users table found");
                // Non-fatal, continue without users table
            }
            
            // For yacht details if available
            if (in_array('charterhub_yachts', $tables)) {
                $yachts_table = 'charterhub_yachts';
            } else if (in_array('wp_charterhub_yachts', $tables)) {
                $yachts_table = 'wp_charterhub_yachts';
            }
            
            // For guest information if available
            if (in_array('charterhub_booking_guests', $tables)) {
                $guests_table = 'charterhub_booking_guests';
            } else if (in_array('wp_charterhub_booking_guests', $tables)) {
                $guests_table = 'wp_charterhub_booking_guests';
            }
            
            // Determine the correct column name for the main charterer
            try {
                $describe_query = "DESCRIBE " . $bookings_table;
                $describe_stmt = $conn->prepare($describe_query);
                $describe_stmt->execute();
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
                $query = "SELECT b.*, 
                         DATE_FORMAT(b.start_date, '%Y-%m-%d') as formatted_start_date,
                         DATE_FORMAT(b.end_date, '%Y-%m-%d') as formatted_end_date";
                
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
                $query = "SELECT b.*, 
                         DATE_FORMAT(b.start_date, '%Y-%m-%d') as formatted_start_date,
                         DATE_FORMAT(b.end_date, '%Y-%m-%d') as formatted_end_date";
                
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
            'message' => 'Error retrieving booking data',
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

// We're using the get_database_connection function from jwt-auth.php
// So we don't need to declare it here 