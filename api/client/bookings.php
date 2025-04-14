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
                
                error_log("BOOKINGS.PHP - Attempting emergency fallback connection");
                $pdo = new PDO($dsn, $username, $password, $options);
                
                // Test the connection with a simple query
                $test = $pdo->query("SELECT 1");
                if (!$test) {
                    throw new Exception("Connection test query failed");
                }
                
                error_log("BOOKINGS.PHP - Fallback connection successful");
                return $pdo;
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
                error_log("BOOKINGS.PHP - No users table found in database");
                throw new Exception("Users table not found in database");
            }
            
            if (in_array('charterhub_booking_guests', $tables)) {
                $guests_table = 'charterhub_booking_guests';
                error_log("BOOKINGS.PHP - Using non-prefixed guests table: {$guests_table}");
            } else if (in_array('wp_charterhub_booking_guests', $tables)) {
                $guests_table = 'wp_charterhub_booking_guests';
                error_log("BOOKINGS.PHP - Using prefixed guests table: {$guests_table}");
            } else {
                error_log("BOOKINGS.PHP - No booking guests table found");
                $guests_table = null; // We'll handle this case
            }
            
            // Find yachts table
            if (in_array('charterhub_yachts', $tables)) {
                $yachts_table = 'charterhub_yachts';
                error_log("BOOKINGS.PHP - Using non-prefixed yachts table: {$yachts_table}");
            } else if (in_array('wp_charterhub_yachts', $tables)) {
                $yachts_table = 'wp_charterhub_yachts';
                error_log("BOOKINGS.PHP - Using prefixed yachts table: {$yachts_table}");
            } else {
                // We'll handle this case by using a simple subquery
                error_log("BOOKINGS.PHP - No yachts table found");
                $yachts_table = null;
            }
            
            // Check the column names in the bookings table to ensure we use the correct one
            $columns = [];
            $describe_query = "DESCRIBE `{$bookings_table}`";
            $describe_result = $conn->query($describe_query);
            
            if (!$describe_result) {
                error_log("BOOKINGS.PHP - Error describing table: " . implode(", ", $conn->errorInfo()));
            } else {
                while ($row = $describe_result->fetch(PDO::FETCH_ASSOC)) {
                    $columns[] = $row['Field'];
                }
                
                error_log("BOOKINGS.PHP - Bookings table columns: " . implode(", ", $columns));
                
                // Determine the correct column name for the customer/charterer ID
                if (in_array('customer_id', $columns)) {
                    $charterer_column = 'customer_id';
                    error_log("BOOKINGS.PHP - Using column: customer_id");
                } else if (in_array('main_charterer_id', $columns)) {
                    $charterer_column = 'main_charterer_id';
                    error_log("BOOKINGS.PHP - Using column: main_charterer_id");
                } else {
                    error_log("BOOKINGS.PHP - Neither customer_id nor main_charterer_id found");
                    throw new Exception("Required charterer column not found in bookings table");
                }
            }
            
        } catch (Exception $e) {
            error_log("BOOKINGS.PHP - Error in table detection: " . $e->getMessage());
            // Use our defaults from debug information
            $bookings_table = 'charterhub_bookings';
            $users_table = 'charterhub_users';
            $guests_table = 'charterhub_booking_guests';
            $yachts_table = 'charterhub_yachts';
            $charterer_column = 'customer_id';  // From debug
            
            error_log("BOOKINGS.PHP - Using default table names after error");
        }
        
        error_log("BOOKINGS.PHP - Final table selections:");
        error_log("BOOKINGS.PHP - Bookings table: {$bookings_table}");
        error_log("BOOKINGS.PHP - Users table: {$users_table}");
        error_log("BOOKINGS.PHP - Guests table: {$guests_table}");
        error_log("BOOKINGS.PHP - Yachts table: {$yachts_table}");
        error_log("BOOKINGS.PHP - Using charterer column: {$charterer_column}");
        
        // Build the query based on available tables and columns
        // Start with base query that doesn't depend on yacht name
        $query = "SELECT 
                    b.id,
                    b.yacht_id,
                    b.start_date,
                    b.end_date,
                    b.status,
                    b.total_price,
                    b.{$charterer_column},
                    u_main.first_name as main_charterer_first_name,
                    u_main.last_name as main_charterer_last_name,
                    u_main.email as main_charterer_email,
                    b.created_at";
                    
        // If yacht table is available, get the yacht name
        if ($yachts_table) {
            $query .= ", y.name as yacht_name";
        } else {
            $query .= ", 'Unknown Yacht' as yacht_name";
        }
        
        $query .= " FROM `{$bookings_table}` b
                   LEFT JOIN `{$users_table}` u_main ON b.{$charterer_column} = u_main.id";
        
        // Add yacht join only if table exists
        if ($yachts_table) {
            $query .= " LEFT JOIN `{$yachts_table}` y ON b.yacht_id = y.id";
        }
        
        // Base WHERE clause
        $query .= " WHERE b.{$charterer_column} = ?";
        $params = [$user_id];
        
        // Only add guest condition if the guests table exists
        if ($guests_table) {
            $query .= " OR b.id IN (SELECT booking_id FROM `{$guests_table}` WHERE user_id = ?)";
            $params[] = $user_id;
        }
        
        // If specific booking ID requested, add that condition
        if ($booking_id) {
            $query .= " AND b.id = ?";
            $params[] = $booking_id;
        }
        
        $query .= " ORDER BY b.created_at DESC";
        
        error_log("BOOKINGS.PHP - Prepared query: " . $query);
        error_log("BOOKINGS.PHP - Query params: " . implode(", ", $params));
        
        // Prepare and execute query using PDO
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("BOOKINGS.PHP - Failed to prepare query: " . implode(", ", $conn->errorInfo()));
            throw new Exception("Failed to prepare booking query: " . $conn->errorInfo()[2]);
        }
        
        // Execute with parameters
        $execute_result = $stmt->execute($params);
        
        if (!$execute_result) {
            error_log("BOOKINGS.PHP - Failed to execute query: " . implode(", ", $stmt->errorInfo()));
            throw new Exception("Failed to execute booking query: " . $stmt->errorInfo()[2]);
        }
        
        // Fetch all results at once
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("BOOKINGS.PHP - Query executed successfully, found " . count($rows) . " bookings");
        
        // Fetch all bookings
        $bookings = [];
        foreach ($rows as $row) {
            // Get booking guests (separate query for each booking) - only if guests table exists
            $booking_id = $row['id'];
            $guests = [];
            
            if ($guests_table) {
                try {
                    $guests_query = "SELECT 
                                        bg.id as booking_guest_id,
                                        bg.user_id,
                                        u.first_name,
                                        u.last_name,
                                        u.email
                                    FROM `{$guests_table}` bg
                                    LEFT JOIN `{$users_table}` u ON bg.user_id = u.id
                                    WHERE bg.booking_id = ?";
                    
                    $guests_stmt = $conn->prepare($guests_query);
                    if (!$guests_stmt) {
                        error_log("BOOKINGS.PHP - Failed to prepare guests query: " . implode(", ", $conn->errorInfo()));
                    } else {
                        $guests_stmt->execute([$booking_id]);
                        $guest_rows = $guests_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($guest_rows as $guest_row) {
                            $guests[] = [
                                'id' => (int)$guest_row['user_id'],
                                'firstName' => $guest_row['first_name'] ?? '',
                                'lastName' => $guest_row['last_name'] ?? '',
                                'email' => $guest_row['email'] ?? ''
                            ];
                        }
                    }
                } catch (Exception $e) {
                    error_log("BOOKINGS.PHP - Error fetching guests for booking ID {$booking_id}: " . $e->getMessage());
                    // Continue with empty guests array
                }
            }
            
            // Format the booking with all related data
            // Make sure the charterer column exists in the result set, fall back to safer values if missing
            $charterer_id = 0;
            try {
                // Check if the column exists in result set
                if (isset($row[$charterer_column])) {
                    $charterer_id = (int)$row[$charterer_column];
                    error_log("BOOKINGS.PHP - Found charterer ID in column {$charterer_column}: {$charterer_id}");
                } else {
                    error_log("BOOKINGS.PHP - Charterer column '{$charterer_column}' not found in result. Available columns: " . implode(", ", array_keys($row)));
                    // Try alternate column names
                    if (isset($row['main_charterer_id'])) {
                        $charterer_id = (int)$row['main_charterer_id'];
                        error_log("BOOKINGS.PHP - Using main_charterer_id as fallback: {$charterer_id}");
                    } elseif (isset($row['customer_id'])) {
                        $charterer_id = (int)$row['customer_id'];
                        error_log("BOOKINGS.PHP - Using customer_id as fallback: {$charterer_id}");
                    } else {
                        // Last resort, try user_id from GET parameter
                        $charterer_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
                        error_log("BOOKINGS.PHP - Using user_id from GET as last resort: {$charterer_id}");
                    }
                }
            } catch (Exception $ce) {
                error_log("BOOKINGS.PHP - Error accessing charterer ID: " . $ce->getMessage());
                $charterer_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            }
            
            $bookings[] = [
                'id' => (int)$row['id'],
                'startDate' => $row['start_date'] ?? '',
                'endDate' => $row['end_date'] ?? '',
                'status' => $row['status'] ?? 'pending',
                'totalPrice' => isset($row['total_price']) ? (float)$row['total_price'] : 0.00,
                'createdAt' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                'yacht' => [
                    'id' => isset($row['yacht_id']) ? (int)$row['yacht_id'] : 0,
                    'name' => $row['yacht_name'] ?? 'Unknown Yacht'
                ],
                'mainCharterer' => [
                    'id' => $charterer_id,
                    'firstName' => $row['main_charterer_first_name'] ?? '',
                    'lastName' => $row['main_charterer_last_name'] ?? '',
                    'email' => $row['main_charterer_email'] ?? ''
                ],
                'guestList' => $guests
            ];
        }
        
        error_log("BOOKINGS.PHP - Successfully processed GET request, returning " . count($bookings) . " bookings");
        
        // Return single booking or list based on request
        if (isset($_GET['id'])) {
            bookings_json_response([
                'success' => true,
                'message' => 'Booking retrieved successfully',
                'data' => !empty($bookings) ? $bookings[0] : null,
                'debug_info' => [
                    'query_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                    'booking_count' => count($bookings),
                    'user_id' => $user_id,
                    'found_data' => !empty($bookings)
                ]
            ]);
        } else {
            // Even if no bookings found, return success with empty array
            bookings_json_response([
                'success' => true,
                'message' => count($bookings) > 0 ? 'Bookings retrieved successfully' : 'No bookings found for this user',
                'data' => $bookings,
                'debug_info' => [
                    'query_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
                    'booking_count' => count($bookings),
                    'user_id' => $user_id,
                    'table_name' => $bookings_table,
                    'charterer_column' => $charterer_column
                ]
            ]);
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
        // Encode data with error handling
        $json = json_encode($data, JSON_PRETTY_PRINT);
        
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

// We're using the get_database_connection function from jwt-auth.php
// So we don't need to declare it here 