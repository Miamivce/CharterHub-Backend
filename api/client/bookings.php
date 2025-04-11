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

// Include only essential dependencies first
require_once __DIR__ . '/../../auth/global-cors.php';

// Apply CORS headers with proper cleaning
if (ob_get_length()) {
    ob_clean();
}

// Apply CORS headers for all supported methods
if (!apply_cors_headers(['GET', 'POST', 'OPTIONS'])) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'CORS error',
        'error' => 'cors_error'
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
require_once __DIR__ . '/../../utils/database.php';
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
        return getDbConnection();
    } catch (Exception $e) {
        error_log("BOOKINGS.PHP - Primary database connection failed: " . $e->getMessage());
        
        // Try a direct connection from config
        try {
            require_once __DIR__ . '/../../auth/config.php';
            return get_db_connection_from_config();
        } catch (Exception $config_e) {
            error_log("BOOKINGS.PHP - Config connection failed: " . $config_e->getMessage());
            
            // Last resort - try fallback connection from environment variables
            try {
                // Cloud database settings
                $host = 'mysql-charterhub-charterhub.c.aivencloud.com';
                $port = '19174';
                $dbname = 'defaultdb';
                $username = 'avnadmin';
                $password = 'AVNS_HCZbm5bZJE1L9C8Pz8C';
                
                // Build DSN
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname}";
                
                // Enable SSL with verification disabled
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
                ];
                
                error_log("BOOKINGS.PHP - Attempting emergency fallback connection");
                return new PDO($dsn, $username, $password, $options);
            } catch (Exception $fallback_e) {
                error_log("BOOKINGS.PHP - All database connection attempts failed");
                throw new Exception("Failed to establish any database connection");
            }
        }
    }
}

// Simple debug endpoint that doesn't require authentication
if (isset($_GET['debug']) && $_GET['debug'] === 'connection_test') {
    // Re-enable error display for this debug endpoint
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        // Test basic database connectivity using the new connection function
        $conn = get_bookings_db_connection();
        
        if (!$conn) {
            json_response([
                'success' => false,
                'message' => 'Failed to connect to database',
                'php_version' => PHP_VERSION,
                'server_time' => date('Y-m-d H:i:s')
            ], 500);
        }
        
        // Look for tables with or without prefix
        $tables = [];
        $tables_result = $conn->query("SHOW TABLES");
        
        if ($tables_result) {
            while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
        }
        
        // Find bookings table
        $bookings_table = null;
        foreach ($tables as $table) {
            if (stripos($table, 'charterhub_bookings') !== false) {
                $bookings_table = $table;
                break;
            }
        }
        
        if (!$bookings_table) {
            json_response([
                'success' => false,
                'message' => 'Bookings table not found',
                'tables_found' => $tables,
                'php_version' => PHP_VERSION,
                'server_time' => date('Y-m-d H:i:s')
            ], 404);
        }
        
        // Get booking table structure
        $booking_columns = [];
        $describe_result = $conn->query("DESCRIBE " . $bookings_table);
        
        if ($describe_result) {
            while ($row = $describe_result->fetch(PDO::FETCH_ASSOC)) {
                $booking_columns[] = $row['Field'];
            }
        }
        
        // Determine charterer column
        $charterer_column = in_array('main_charterer_id', $booking_columns) ? 'main_charterer_id' : 
                          (in_array('customer_id', $booking_columns) ? 'customer_id' : 'not_found');
        
        json_response([
            'success' => true,
            'message' => 'Database connection test',
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'tables_found' => $tables,
            'bookings_table' => $bookings_table,
            'booking_columns' => $booking_columns,
            'charterer_column' => $charterer_column,
            'connection_type' => 'Direct PDO connection with fallback',
            'buffer_level' => ob_get_level()
        ]);
    } catch (Exception $e) {
        json_response([
            'success' => false,
            'message' => 'Error in database test',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'php_version' => PHP_VERSION,
            'trace' => $e->getTraceAsString()
        ], 500);
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
        json_response([
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
            json_response([
                'success' => false,
                'message' => 'Method not allowed'
            ], 405);
    }
} catch (Exception $e) {
    // Catch any unexpected errors and return proper JSON response
    error_log("BOOKINGS.PHP - Unexpected error: " . $e->getMessage());
    json_response([
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
        
        // Get specific booking if ID is provided
        $booking_id = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        // Determine the correct column name by checking table structure
        $charterer_column = 'main_charterer_id'; // Default column name
        $columns = [];
        
        try {
            // First, check if the bookings table exists
            $tables_query = "SHOW TABLES";
            $tables_result = $conn->query($tables_query);
            
            if (!$tables_result) {
                error_log("BOOKINGS.PHP - Error checking tables: " . $conn->errorInfo()[2]);
                throw new Exception("Error checking database tables");
            }
            
            // Look for bookings table with or without prefix
            $bookings_table = 'wp_charterhub_bookings'; // Default with prefix
            $table_exists = false;
            
            while ($row = $tables_result->fetch(PDO::FETCH_NUM)) {
                $table_name = $row[0];
                error_log("BOOKINGS.PHP - Found table: " . $table_name);
                
                if ($table_name === 'wp_charterhub_bookings' || $table_name === 'charterhub_bookings') {
                    $bookings_table = $table_name;
                    $table_exists = true;
                    error_log("BOOKINGS.PHP - Found bookings table: " . $bookings_table);
                    break;
                }
            }
            
            if (!$table_exists) {
                error_log("BOOKINGS.PHP - No bookings table found in database");
                throw new Exception("Bookings table not found in database");
            }
            
            // Check table columns
            $describe_query = "DESCRIBE " . $bookings_table;
            $describe_result = $conn->query($describe_query);
            
            if (!$describe_result) {
                error_log("BOOKINGS.PHP - Error describing table: " . $conn->errorInfo()[2]);
                throw new Exception("Failed to get table structure");
            }
            
            while ($row = $describe_result->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            
            error_log("BOOKINGS.PHP - Found columns: " . implode(", ", $columns));
            
            // Check if main_charterer_id or customer_id is used
            if (in_array('main_charterer_id', $columns)) {
                $charterer_column = 'main_charterer_id';
                error_log("BOOKINGS.PHP - Using column: main_charterer_id");
            } elseif (in_array('customer_id', $columns)) {
                $charterer_column = 'customer_id';
                error_log("BOOKINGS.PHP - Using column: customer_id");
            } else {
                error_log("BOOKINGS.PHP - Neither main_charterer_id nor customer_id found, columns available: " . implode(", ", $columns));
                throw new Exception("Required column not found in bookings table");
            }
            
            // Also determine proper yachts table name
            $yachts_table = 'wp_charterhub_yachts'; // Default
            $yachts_query = "SHOW TABLES LIKE '%charterhub_yachts'";
            $yachts_result = $conn->query($yachts_query);
            
            if ($yachts_result && $yachts_result->rowCount() > 0) {
                $yacht_row = $yachts_result->fetch(PDO::FETCH_NUM);
                $yachts_table = $yacht_row[0];
                error_log("BOOKINGS.PHP - Found yachts table: " . $yachts_table);
            }
            
            // Determine proper users table name
            $users_table = 'wp_charterhub_users'; // Default
            $users_query = "SHOW TABLES LIKE '%charterhub_users'";
            $users_result = $conn->query($users_query);
            
            if ($users_result && $users_result->rowCount() > 0) {
                $user_row = $users_result->fetch(PDO::FETCH_NUM);
                $users_table = $user_row[0];
                error_log("BOOKINGS.PHP - Found users table: " . $users_table);
            }
            
            // Determine proper booking_guests table name
            $guests_table = 'wp_charterhub_booking_guests'; // Default
            $guests_query = "SHOW TABLES LIKE '%charterhub_booking_guests'";
            $guests_result = $conn->query($guests_query);
            
            if ($guests_result && $guests_result->rowCount() > 0) {
                $guest_row = $guests_result->fetch(PDO::FETCH_NUM);
                $guests_table = $guest_row[0];
                error_log("BOOKINGS.PHP - Found booking guests table: " . $guests_table);
            }
        } catch (Exception $e) {
            error_log("BOOKINGS.PHP - Error determining table structure: " . $e->getMessage());
            // We'll try to continue with default table names
            $bookings_table = 'wp_charterhub_bookings';
            $yachts_table = 'wp_charterhub_yachts';
            $users_table = 'wp_charterhub_users';
            $guests_table = 'wp_charterhub_booking_guests';
        }
        
        error_log("BOOKINGS.PHP - Using charterer column: " . $charterer_column);
        
        // Base query to get bookings where user is main charterer or guest
        $query = "SELECT 
                    b.id,
                    b.yacht_id,
                    y.name as yacht_name,
                    b.start_date,
                    b.end_date,
                    b.status,
                    b.total_price,
                    b.{$charterer_column},
                    u_main.first_name as main_charterer_first_name,
                    u_main.last_name as main_charterer_last_name,
                    u_main.email as main_charterer_email,
                    b.created_at
                  FROM " . $bookings_table . " b
                  LEFT JOIN " . $yachts_table . " y ON b.yacht_id = y.id
                  LEFT JOIN " . $users_table . " u_main ON b.{$charterer_column} = u_main.id
                  WHERE (b.{$charterer_column} = ? OR 
                        b.id IN (SELECT booking_id FROM " . $guests_table . " WHERE user_id = ?))";
        
        $params = [$user_id, $user_id];
        
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
            error_log("BOOKINGS.PHP - Failed to prepare query: " . $conn->errorInfo()[2]);
            throw new Exception("Failed to prepare booking query");
        }
        
        // Execute with parameters
        $execute_result = $stmt->execute($params);
        
        if (!$execute_result) {
            error_log("BOOKINGS.PHP - Failed to execute query: " . $stmt->errorInfo()[2]);
            throw new Exception("Failed to execute booking query");
        }
        
        // Fetch all results at once
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("BOOKINGS.PHP - Query executed successfully, found " . count($rows) . " bookings");
        
        // Fetch all bookings
        $bookings = [];
        foreach ($rows as $row) {
            // Get booking guests (separate query for each booking)
            $booking_id = $row['id'];
            
            // Try to get guests or continue without them if there's an error
            $guests = [];
            try {
                $guests_query = "SELECT 
                                    bg.id as booking_guest_id,
                                    bg.user_id,
                                    u.first_name,
                                    u.last_name,
                                    u.email
                                FROM " . $guests_table . " bg
                                LEFT JOIN " . $users_table . " u ON bg.user_id = u.id
                                WHERE bg.booking_id = ?";
                
                $guests_stmt = $conn->prepare($guests_query);
                if (!$guests_stmt) {
                    error_log("BOOKINGS.PHP - Failed to prepare guests query: " . $conn->errorInfo()[2]);
                } else {
                    $guests_stmt->execute([$booking_id]);
                    $guest_rows = $guests_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($guest_rows as $guest_row) {
                        $guests[] = [
                            'id' => (int)$guest_row['user_id'],
                            'firstName' => $guest_row['first_name'],
                            'lastName' => $guest_row['last_name'],
                            'email' => $guest_row['email']
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("BOOKINGS.PHP - Error fetching guests for booking ID " . $booking_id . ": " . $e->getMessage());
                // Continue with empty guests array
            }
            
            // Format the booking with all related data
            $bookings[] = [
                'id' => (int)$row['id'],
                'startDate' => $row['start_date'],
                'endDate' => $row['end_date'],
                'status' => $row['status'],
                'totalPrice' => (float)$row['total_price'],
                'createdAt' => $row['created_at'],
                'yacht' => [
                    'id' => (int)$row['yacht_id'],
                    'name' => $row['yacht_name']
                ],
                'mainCharterer' => [
                    'id' => (int)$row[$charterer_column],
                    'firstName' => $row['main_charterer_first_name'],
                    'lastName' => $row['main_charterer_last_name'],
                    'email' => $row['main_charterer_email']
                ],
                'guestList' => $guests
            ];
        }
        
        error_log("BOOKINGS.PHP - Successfully processed GET request, returning " . count($bookings) . " bookings");
        
        // Return single booking or list based on request
        if (isset($_GET['id'])) {
            json_response([
                'success' => true,
                'message' => 'Booking retrieved successfully',
                'data' => !empty($bookings) ? $bookings[0] : null
            ]);
        } else {
            json_response([
                'success' => true,
                'message' => 'Bookings retrieved successfully',
                'data' => $bookings
            ]);
        }
    } catch (Exception $e) {
        error_log("BOOKINGS.PHP - Error in GET request: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("BOOKINGS.PHP - Stack trace: " . $e->getTraceAsString());
        
        // Return a more detailed error response for troubleshooting
        json_response([
            'success' => false,
            'message' => 'Error retrieving booking data',
            'error' => 'database_error',
            'details' => $e->getMessage(),
            'diagnostics' => [
                'php_version' => PHP_VERSION,
                'column_detection' => true,
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
        json_response([
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
        json_response([
            'success' => false,
            'message' => 'Error creating booking',
            'error' => 'database_error',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Send JSON response
 * 
 * @param array $data Response data
 * @param int $status HTTP status code
 */
function json_response($data, $status = 200) {
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