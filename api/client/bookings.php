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

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include necessary files
require_once __DIR__ . '/../../auth/jwt-auth.php';
require_once __DIR__ . '/../../auth/global-cors.php';

// Enable CORS - Must be called before any output
// Include OPTIONS method to support preflight requests
apply_cors_headers(['GET', 'POST', 'OPTIONS']);

// Handle OPTIONS requests immediately for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // The apply_cors_headers function already sets appropriate headers and exits for OPTIONS
    exit;
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

/**
 * Handle GET request - Retrieve bookings for the authenticated user
 * 
 * @param array $user Authenticated user data
 */
function handle_get_request($user) {
    // Using the database connection function from jwt-auth.php
    $conn = get_database_connection();
    
    // Get user ID from authenticated token
    $user_id = $user['id'];
    
    // Get specific booking if ID is provided
    $booking_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    // Base query to get bookings where user is main charterer or guest
    $query = "SELECT 
                b.id,
                b.yacht_id,
                y.name as yacht_name,
                b.start_date,
                b.end_date,
                b.status,
                b.total_price,
                b.main_charterer_id,
                u_main.first_name as main_charterer_first_name,
                u_main.last_name as main_charterer_last_name,
                u_main.email as main_charterer_email,
                b.created_at
              FROM wp_charterhub_bookings b
              LEFT JOIN wp_charterhub_yachts y ON b.yacht_id = y.id
              LEFT JOIN wp_charterhub_users u_main ON b.main_charterer_id = u_main.id
              WHERE (b.main_charterer_id = ? OR 
                    b.id IN (SELECT booking_id FROM wp_charterhub_booking_guests WHERE user_id = ?))";
    
    $params = [$user_id, $user_id];
    $types = "ii";
    
    // If specific booking ID requested, add that condition
    if ($booking_id) {
        $query .= " AND b.id = ?";
        $params[] = $booking_id;
        $types .= "i";
    }
    
    $query .= " ORDER BY b.created_at DESC";
    
    // Prepare and execute query
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Check for errors
    if (!$result) {
        error_log("SQL Error in bookings query: " . $conn->error);
        json_response([
            'success' => false,
            'message' => 'Database error retrieving bookings'
        ], 500);
    }
    
    // Fetch all bookings
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        // Get booking guests (separate query for each booking)
        $booking_id = $row['id'];
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
        $guests_stmt->bind_param("i", $booking_id);
        $guests_stmt->execute();
        $guests_result = $guests_stmt->get_result();
        
        $guests = [];
        while ($guest_row = $guests_result->fetch_assoc()) {
            $guests[] = [
                'id' => (int)$guest_row['user_id'],
                'firstName' => $guest_row['first_name'],
                'lastName' => $guest_row['last_name'],
                'email' => $guest_row['email']
            ];
        }
        $guests_stmt->close();
        
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
                'id' => (int)$row['main_charterer_id'],
                'firstName' => $row['main_charterer_first_name'],
                'lastName' => $row['main_charterer_last_name'],
                'email' => $row['main_charterer_email']
            ],
            'guestList' => $guests
        ];
    }
    
    $stmt->close();
    $conn->close();
    
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
        $conn->begin_transaction();
        
        // Insert booking record
        $insert_booking_query = "INSERT INTO wp_charterhub_bookings 
                                (yacht_id, start_date, end_date, status, total_price, main_charterer_id, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($insert_booking_query);
        $stmt->bind_param("isssdis", $yacht_id, $start_date, $end_date, $status, $total_price, $main_charterer_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create booking: " . $stmt->error);
        }
        
        // Get the new booking ID
        $booking_id = $conn->insert_id;
        $stmt->close();
        
        // Add guests if provided
        if (!empty($guests)) {
            $insert_guest_query = "INSERT INTO wp_charterhub_booking_guests (booking_id, user_id, created_at) VALUES (?, ?, NOW())";
            $guest_stmt = $conn->prepare($insert_guest_query);
            
            foreach ($guests as $guest) {
                if (isset($guest['id']) && !empty($guest['id'])) {
                    $guest_id = intval($guest['id']);
                    $guest_stmt->bind_param("ii", $booking_id, $guest_id);
                    if (!$guest_stmt->execute()) {
                        throw new Exception("Failed to add guest: " . $guest_stmt->error);
                    }
                }
            }
            $guest_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        // Return success response with new booking ID
        json_response([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => [
                'id' => $booking_id,
                'yachtId' => $yacht_id,
                'startDate' => $start_date,
                'endDate' => $end_date,
                'status' => $status,
                'totalPrice' => $total_price,
                'mainChartererId' => $main_charterer_id,
                'createdAt' => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        
        // Log error details
        error_log("Booking creation error: " . $e->getMessage());
        
        // Return error response
        json_response([
            'success' => false,
            'message' => 'Failed to create booking: ' . $e->getMessage()
        ], 400);
    }
}

// We're using the get_database_connection function from jwt-auth.php
// So we don't need to declare it here 