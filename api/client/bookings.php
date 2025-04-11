<?php
/**
 * Client Bookings API Endpoint
 * 
 * This endpoint handles client access to booking data with JWT authentication.
 * 
 * Supports:
 * - GET: Retrieve bookings where the authenticated user is either the main charterer or a guest
 * - POST: Create a new booking (currently empty, to be implemented)
 */

// Prevent any output before headers and ensure proper content type
@ini_set('display_errors', 0);
error_reporting(0); // Temporarily disable error reporting for CORS setup

// Ensure proper JSON content type for all responses
header('Content-Type: application/json');

// Simple debug endpoint that doesn't require authentication
if (isset($_GET['debug']) && $_GET['debug'] === 'connection_test') {
    // Re-enable error display for this debug endpoint
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        // Test basic database connectivity
        require_once __DIR__ . '/../../utils/database.php';
        $conn = getDbConnection();
        
        if (!$conn) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to connect to database',
                'php_version' => PHP_VERSION,
                'server_time' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
        
        // Test if wp_charterhub_bookings table exists
        $tables_result = $conn->query("SHOW TABLES LIKE 'wp_charterhub_bookings'");
        $bookings_table_exists = ($tables_result && $tables_result->rowCount() > 0);
        
        // Get booking table structure if it exists
        $booking_columns = [];
        if ($bookings_table_exists) {
            $describe_result = $conn->query("DESCRIBE wp_charterhub_bookings");
            if ($describe_result) {
                while ($row = $describe_result->fetch(PDO::FETCH_ASSOC)) {
                    $booking_columns[] = $row['Field'];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Database connection test',
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'bookings_table_exists' => $bookings_table_exists,
            'booking_columns' => $booking_columns,
            'charterer_column' => in_array('main_charterer_id', $booking_columns) ? 'main_charterer_id' : 
                                 (in_array('customer_id', $booking_columns) ? 'customer_id' : 'not_found')
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error in database test',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'php_version' => PHP_VERSION
        ]);
        exit;
    }
}

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include necessary files
require_once __DIR__ . '/../../auth/global-cors.php';

// Apply CORS headers BEFORE any other operation
// Include OPTIONS method to support preflight requests
if (!apply_cors_headers(['GET', 'POST', 'OPTIONS'])) {
    exit; // Exit if CORS headers could not be sent
}

// Handle OPTIONS requests immediately for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit; // The apply_cors_headers function already handles this, but just to be sure
}

// Re-enable error reporting now that CORS headers are sent
error_reporting(E_ALL);
@ini_set('display_errors', 1);

// Set log error details
ini_set('log_errors', 1);
error_log("DIAGNOSTICS - PHP Version: " . PHP_VERSION);
error_log("DIAGNOSTICS - Using updated code with column detection (v2)");

// Now include JWT auth after CORS headers are sent
require_once __DIR__ . '/../../auth/jwt-auth.php';

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
        
        // Using the database connection function from jwt-auth.php
        $conn = get_database_connection();
        if (!$conn) {
            error_log("BOOKINGS.PHP - Failed to get database connection");
            throw new Exception("Database connection failed");
        }
        error_log("BOOKINGS.PHP - Database connection established");
        
        // Get user ID from authenticated token
        $user_id = $user['id'];
        
        // Get specific booking if ID is provided
        $booking_id = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        // Determine the correct column name by checking table structure
        $charterer_column = 'main_charterer_id'; // Default column name
        $columns = [];
        
        try {
            // First, check if the bookings table exists
            $tables_query = "SHOW TABLES LIKE 'wp_charterhub_bookings'";
            $tables_result = $conn->query($tables_query);
            
            if (!$tables_result) {
                error_log("BOOKINGS.PHP - Error checking tables: " . $conn->errorInfo()[2]);
                throw new Exception("Error checking database tables");
            }
            
            $table_exists = ($tables_result->rowCount() > 0);
            error_log("BOOKINGS.PHP - Bookings table exists: " . ($table_exists ? "YES" : "NO"));
            
            if (!$table_exists) {
                error_log("BOOKINGS.PHP - wp_charterhub_bookings table not found");
                throw new Exception("Bookings table not found in database");
            }
            
            // Check table columns
            $describe_query = "DESCRIBE wp_charterhub_bookings";
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
        } catch (Exception $e) {
            error_log("BOOKINGS.PHP - Error determining column name: " . $e->getMessage());
            // We'll try to continue with the default column name
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
                  FROM wp_charterhub_bookings b
                  LEFT JOIN wp_charterhub_yachts y ON b.yacht_id = y.id
                  LEFT JOIN wp_charterhub_users u_main ON b.{$charterer_column} = u_main.id
                  WHERE (b.{$charterer_column} = ? OR 
                        b.id IN (SELECT booking_id FROM wp_charterhub_booking_guests WHERE user_id = ?))";
        
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
                                FROM wp_charterhub_booking_guests bg
                                LEFT JOIN wp_charterhub_users u ON bg.user_id = u.id
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
        // Get database connection using the function from jwt-auth.php
        $conn = get_database_connection();
        
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
            $describe_query = "DESCRIBE wp_charterhub_bookings";
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
        $insert_booking_query = "INSERT INTO wp_charterhub_bookings 
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
            $insert_guest_query = "INSERT INTO wp_charterhub_booking_guests (booking_id, user_id, created_at) VALUES (?, ?, NOW())";
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
            'error' => 'database_error'
        ], 500);
    }
}

// We're using the get_database_connection function from jwt-auth.php
// So we don't need to declare it here 