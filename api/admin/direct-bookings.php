<?php
/**
 * Direct Bookings API Endpoint
 * 
 * This endpoint handles admin access to booking data without relying on
 * external JWT libraries or middleware.
 * 
 * Supports:
 * - GET: List all bookings or bookings for specific customer
 * - POST: Create new booking
 * - PUT: Update an existing booking
 * - DELETE: Delete a booking
 * 
 * FOR DEVELOPMENT USE ONLY - NOT FOR PRODUCTION
 */

// Include auth helper
require_once __DIR__ . '/direct-auth-helper.php';

// Start output buffering to prevent headers issues
if (!ob_get_level()) {
    ob_start();
}

// Improved CORS handling - Record incoming request details for better debugging
$incoming_origin = $_SERVER['HTTP_ORIGIN'] ?? 'none';
$incoming_method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
error_log("DIRECT-BOOKINGS.PHP - Request received from origin: {$incoming_origin}, method: {$incoming_method}");

try {
    // Check if this is a direct debug or test request or credentials mode
    if (isset($_GET['debug']) || (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Mozilla') !== false)) {
        // For debug and browser requests with credentials
        if ($incoming_origin !== 'none') {
            header("Access-Control-Allow-Origin: $incoming_origin");
            header("Access-Control-Allow-Credentials: true");
        } else {
            // Only use wildcard when no specific origin is provided
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token, X-Requested-With, Accept, Origin, Cache-Control, Pragma, Expires');
        error_log("DIRECT-BOOKINGS.PHP - Debug/Test mode: Setting CORS headers for origin: {$incoming_origin}");
    } else {
        // Normal API operation with strict CORS
        $cors_result = apply_cors_headers(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
        if (!$cors_result) {
            error_log("DIRECT-BOOKINGS.PHP - CORS check failed for origin: {$incoming_origin}");
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
    error_log("DIRECT-BOOKINGS.PHP - CORS exception: " . $cors_e->getMessage());
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

// Set content type
header('Content-Type: application/json');

// Initialize response
$response = [
    'success' => false,
    'message' => 'Initializing request',
];

try {
    // Ensure admin access
    ensure_admin_access();

    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handle_get_request();
            break;
        case 'POST':
            handle_post_request();
            break;
        case 'PUT':
            handle_put_request();
            break;
        case 'DELETE':
            handle_delete_request();
            break;
        default:
            json_response([
                'success' => false,
                'message' => 'Method not allowed'
            ], 405);
    }
} catch (Exception $e) {
    // Handle any unhandled exceptions
    error_log("Unhandled exception in direct-bookings.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    json_response([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}

/**
 * Handle GET request - List all bookings or bookings for a specific customer
 */
function handle_get_request() {
    $conn = get_database_connection();
    
    // Check if a customer ID was provided to filter bookings
    $customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
    
    // Base query to get all bookings - removed yacht join since table doesn't exist
    $query = "SELECT 
                b.id,
                b.yacht_id,
                b.start_date,
                b.end_date,
                b.status,
                b.total_price,
                b.created_by_admin_id,
                admin.first_name as admin_first_name,
                admin.last_name as admin_last_name,
                admin.email as admin_email,
                b.main_charterer_id,
                u_main.first_name as main_charterer_first_name,
                u_main.last_name as main_charterer_last_name,
                u_main.email as main_charterer_email,
                b.created_at
              FROM wp_charterhub_bookings b
              LEFT JOIN wp_charterhub_users u_main ON b.main_charterer_id = u_main.id
              LEFT JOIN wp_charterhub_users admin ON b.created_by_admin_id = admin.id
              WHERE 1=1";
    
    // Add customer filter if specified
    $params = [];
    $types = "";
    
    if ($customer_id) {
        // Get bookings where this customer is the main charterer or a guest
        $query .= " AND (b.main_charterer_id = ? OR 
                         b.id IN (SELECT booking_id FROM wp_charterhub_booking_guests WHERE user_id = ?))";
        $params[] = $customer_id;
        $params[] = $customer_id;
        $types .= "ii";
    }
    
    $query .= " ORDER BY b.created_at DESC";
    
    // Prepare and execute query
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt = $conn->prepare($query);
    }
    
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
        
        // Format the booking with all related data - use a placeholder for yacht name
        $bookings[] = [
            'id' => (int)$row['id'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'status' => $row['status'],
            'totalPrice' => (float)$row['total_price'],
            'createdAt' => $row['created_at'],
            'created_by_admin_id' => $row['created_by_admin_id'] ? (int)$row['created_by_admin_id'] : null,
            'created_by_admin' => $row['created_by_admin_id'] ? ($row['admin_first_name'] . ' ' . $row['admin_last_name']) : 'Administrator',
            'admin' => $row['created_by_admin_id'] ? [
                'id' => (int)$row['created_by_admin_id'],
                'name' => $row['admin_first_name'] . ' ' . $row['admin_last_name'],
                'email' => $row['admin_email']
            ] : null,
            'yacht' => [
                'id' => (int)$row['yacht_id'],
                'name' => "Yacht #" . $row['yacht_id']  // Use placeholder since we don't have yacht table
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
    
    // Return bookings with success message
    json_response([
        'success' => true,
        'message' => 'Bookings retrieved successfully',
        'data' => $bookings
    ]);
}

/**
 * Handle POST request - Create a new booking
 */
function handle_post_request() {
    try {
        // Get database connection
        $conn = get_database_connection();
        
        // Get admin user info from token
        $admin_info = ensure_admin_access();
        $admin_id = $admin_info['user_id'];
        
        // Parse request body
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            throw new Exception('Invalid request data');
        }
        
        // Debug log to trace the incoming data
        error_log("Admin booking creation request data: " . json_encode($data));
        
        // Validate required fields
        $required_fields = ['yachtId', 'startDate', 'endDate', 'mainCharterer'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        // Extract main data
        $yacht_id = intval($data['yachtId']);
        $start_date = date('Y-m-d', strtotime($data['startDate']));
        $end_date = date('Y-m-d', strtotime($data['endDate']));
        $status = isset($data['status']) ? $data['status'] : 'pending';
        $total_price = isset($data['totalPrice']) ? floatval($data['totalPrice']) : 0;
        
        // Extract main charterer data
        $main_charterer = $data['mainCharterer'];
        $main_charterer_id = null;
        
        // Begin transaction
        $conn->begin_transaction();
        
        // Check if main_charterer_id exists in wp_charterhub_users table
        $main_charterer_id = intval($data['mainCharterer']['id']);
        if ($main_charterer_id > 0) {
            // Check in wp_charterhub_users table
            $check_sql = "SELECT id FROM wp_charterhub_users WHERE id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $main_charterer_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Main charterer ID does not exist in wp_charterhub_users table");
            }
            $check_stmt->close();
        }
        
        // Get the table structure to determine correct columns
        try {
            $describe_query = "DESCRIBE wp_charterhub_bookings";
            $describe_stmt = $conn->prepare($describe_query);
            $describe_stmt->execute();
            $result = $describe_stmt->get_result();
            
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            $describe_stmt->close();
            
            error_log("Booking table columns: " . implode(", ", $columns));
            
            // Check if main_charterer_id or customer_id is used
            $charterer_column = in_array('main_charterer_id', $columns) ? 'main_charterer_id' : 'customer_id';
            
            // Insert booking record using the correct column name
            $insert_booking_query = "INSERT INTO wp_charterhub_bookings 
                                    (yacht_id, start_date, end_date, status, total_price, created_by_admin_id, $charterer_column, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        } catch (Exception $e) {
            // If describe fails, try to use the most common column name
            error_log("Error describing table: " . $e->getMessage());
            $charterer_column = 'main_charterer_id'; // Default to this column
            
            // Insert booking record with our best guess at the column name
            $insert_booking_query = "INSERT INTO wp_charterhub_bookings 
                                    (yacht_id, start_date, end_date, status, total_price, created_by_admin_id, $charterer_column, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        }
        
        // Prepare and execute the insert
        $stmt = $conn->prepare($insert_booking_query);
        $stmt->bind_param("isssiii", $yacht_id, $start_date, $end_date, $status, $total_price, $admin_id, $main_charterer_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create booking: " . $stmt->error . " (Query: $insert_booking_query)");
        }
        
        // Get the new booking ID
        $booking_id = $conn->insert_id;
        $stmt->close();
        
        // Process guests if provided
        $guest_ids = [];
        if (isset($data['guests']) && is_array($data['guests']) && !empty($data['guests'])) {
            $guests = $data['guests'];
            
            foreach ($guests as $guest) {
                $guest_id = null;
                
                // If guest is new (no ID), create a new customer first
                if (!isset($guest['id']) || empty($guest['id'])) {
                    // Validate required guest fields
                    $guest_fields = ['firstName', 'lastName', 'email'];
                    foreach ($guest_fields as $field) {
                        if (!isset($guest[$field]) || empty($guest[$field])) {
                            throw new Exception("Missing required guest field: {$field}");
                        }
                    }
                    
                    // Check if guest with this email already exists in wp_charterhub_users
                    $check_sql = "SELECT id FROM wp_charterhub_users WHERE email = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $guest['email']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        // Guest exists in wp_charterhub_users, use existing ID
                        $guest_row = $check_result->fetch_assoc();
                        $guest_id = $guest_row['id'];
                        $check_stmt->close();
                    } else {
                        // Create new guest in wp_charterhub_users
                        $check_stmt->close();
                        
                        $first_name = $guest['firstName'];
                        $last_name = $guest['lastName'];
                        $email = $guest['email'];
                        $display_name = $first_name . ' ' . $last_name;
                        $user_login = strtolower(str_replace(' ', '', $first_name)) . '.' . strtolower(str_replace(' ', '', $last_name));
                        $phone = isset($guest['phoneNumber']) ? $guest['phoneNumber'] : '';
                        $notes = isset($guest['notes']) ? $guest['notes'] : '';
                        
                        // Generate a secure random password for the new user
                        $random_password = bin2hex(random_bytes(8));
                        $hashed_password = password_hash($random_password, PASSWORD_DEFAULT);
                        
                        // Insert into wp_charterhub_users table
                        $insert_sql = "INSERT INTO wp_charterhub_users (
                            email, password, first_name, last_name, display_name, 
                            role, phone_number, notes, verified, token_version, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, 'client', ?, ?, 1, 0, NOW(), NOW())";
                        
                        $insert_stmt = $conn->prepare($insert_sql);
                        $insert_stmt->bind_param("sssssss", $email, $hashed_password, $first_name, $last_name, $display_name, $phone, $notes);
                        
                        if (!$insert_stmt->execute()) {
                            throw new Exception("Failed to create guest in wp_charterhub_users: " . $insert_stmt->error);
                        }
                        
                        $guest_id = $conn->insert_id;
                        $insert_stmt->close();
                    }
                } else {
                    // Use existing guest ID
                    $guest_id = intval($guest['id']);
                    
                    // Verify the ID exists in wp_charterhub_users table
                    $check_sql = "SELECT id FROM wp_charterhub_users WHERE id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("i", $guest_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows === 0) {
                        throw new Exception("Guest ID does not exist in wp_charterhub_users table");
                    }
                    $check_stmt->close();
                }
                
                // Add guest to booking
                if ($guest_id) {
                    $guest_ids[] = $guest_id;
                    
                    $insert_guest_query = "INSERT INTO wp_charterhub_booking_guests (booking_id, user_id, created_at) VALUES (?, ?, NOW())";
                    $guest_stmt = $conn->prepare($insert_guest_query);
                    $guest_stmt->bind_param("ii", $booking_id, $guest_id);
                    
                    if (!$guest_stmt->execute()) {
                        throw new Exception("Failed to add guest to booking: " . $guest_stmt->error);
                    }
                    
                    $guest_stmt->close();
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Get full booking details for response
        $booking_details = [
            'id' => $booking_id,
            'yacht' => [
                'id' => $yacht_id,
                // Add yacht name if needed
            ],
            'startDate' => $start_date,
            'endDate' => $end_date,
            'status' => $status,
            'totalPrice' => $total_price,
            'created_by_admin_id' => $admin_id,
            'created_by_admin' => $admin_info['email'],
            'admin' => [
                'id' => $admin_id,
                'name' => $admin_info['email'], // Using email as name since we don't have full name in admin_info
                'email' => $admin_info['email']
            ],
            'mainCharterer' => [
                'id' => $main_charterer_id,
                'firstName' => $main_charterer['firstName'] ?? '',
                'lastName' => $main_charterer['lastName'] ?? '',
                'email' => $main_charterer['email'] ?? ''
            ],
            'guestList' => []
        ];
        
        // Add guests to response
        if (!empty($guest_ids)) {
            // Query for guest details
            $guest_details_query = "SELECT id, display_name, email FROM wp_charterhub_users WHERE id IN (" . implode(',', array_fill(0, count($guest_ids), '?')) . ")";
            $guest_details_stmt = $conn->prepare($guest_details_query);
            
            // Bind all guest IDs
            $types = str_repeat('i', count($guest_ids));
            $guest_details_stmt->bind_param($types, ...$guest_ids);
            $guest_details_stmt->execute();
            $guest_details_result = $guest_details_stmt->get_result();
            
            while ($guest_row = $guest_details_result->fetch_assoc()) {
                // Split display_name into first and last name
                $name_parts = explode(' ', $guest_row['display_name'], 2);
                $first_name = $name_parts[0];
                $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
                
                $booking_details['guestList'][] = [
                    'id' => (int)$guest_row['id'],
                    'firstName' => $first_name,
                    'lastName' => $last_name,
                    'email' => $guest_row['email']
                ];
            }
            
            $guest_details_stmt->close();
        }
        
        // Return success response with booking details
        json_response([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking_details
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error - avoid using ping() which is deprecated
        if (isset($conn)) {
            try {
                $conn->rollback();
            } catch (Exception $rollback_e) {
                error_log("Rollback error: " . $rollback_e->getMessage());
            }
        }
        
        // Log error details
        error_log("Admin booking creation error: " . $e->getMessage());
        
        // Return error response
        json_response([
            'success' => false,
            'message' => 'Failed to create booking: ' . $e->getMessage()
        ], 400);
    }
}

/**
 * Handle PUT request - Update an existing booking
 */
function handle_put_request() {
    try {
        // Get database connection
        $conn = get_database_connection();
        
        // Get admin user info from token
        $admin_info = ensure_admin_access();
        $admin_id = $admin_info['user_id'];
        
        // Get booking ID from query parameter
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        if (!$id) {
            throw new Exception('Booking ID is required for update');
        }
        
        // Parse request body
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            throw new Exception('Invalid request data');
        }
        
        // Debug log to trace the incoming data
        error_log("Admin booking update request data: " . json_encode($data));
        
        // First check if booking exists
        $check_query = "SELECT id FROM wp_charterhub_bookings WHERE id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('Booking not found');
        }
        $check_stmt->close();
        
        // Begin transaction
        $conn->begin_transaction();
        
        // Build update query based on provided fields
        $update_fields = [];
        $update_params = [];
        $update_types = "";
        
        // Handle yacht ID
        if (isset($data['yachtId'])) {
            $yacht_id = intval($data['yachtId']);
            $update_fields[] = "yacht_id = ?";
            $update_params[] = $yacht_id;
            $update_types .= "i";
        }
        
        // Handle dates
        if (isset($data['startDate'])) {
            $start_date = date('Y-m-d', strtotime($data['startDate']));
            $update_fields[] = "start_date = ?";
            $update_params[] = $start_date;
            $update_types .= "s";
        }
        
        if (isset($data['endDate'])) {
            $end_date = date('Y-m-d', strtotime($data['endDate']));
            $update_fields[] = "end_date = ?";
            $update_params[] = $end_date;
            $update_types .= "s";
        }
        
        // Handle status
        if (isset($data['status'])) {
            $status = $data['status'];
            $update_fields[] = "status = ?";
            $update_params[] = $status;
            $update_types .= "s";
        }
        
        // Handle total price
        if (isset($data['totalPrice'])) {
            $total_price = floatval($data['totalPrice']);
            $update_fields[] = "total_price = ?";
            $update_params[] = $total_price;
            $update_types .= "d";
        }
        
        // Handle special requests
        if (isset($data['specialRequests'])) {
            $special_requests = $data['specialRequests'];
            $update_fields[] = "special_requests = ?";
            $update_params[] = $special_requests;
            $update_types .= "s";
        }
        
        // Handle main charterer
        if (isset($data['mainCharterer']) && isset($data['mainCharterer']['id'])) {
            $main_charterer_id = intval($data['mainCharterer']['id']);
            $update_fields[] = "main_charterer_id = ?";
            $update_params[] = $main_charterer_id;
            $update_types .= "i";
            
            // Update main charterer notes if provided
            if (isset($data['mainCharterer']['notes'])) {
                $notes = $data['mainCharterer']['notes'];
                $update_notes_query = "UPDATE wp_charterhub_users SET notes = ? WHERE id = ?";
                $update_notes_stmt = $conn->prepare($update_notes_query);
                $update_notes_stmt->bind_param("si", $notes, $main_charterer_id);
                $update_notes_stmt->execute();
                $update_notes_stmt->close();
            }
        }
        
        // Update the booking if we have fields to update
        if (!empty($update_fields)) {
            $update_fields[] = "updated_at = NOW()";
            
            $update_query = "UPDATE wp_charterhub_bookings SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $update_params[] = $id;
            $update_types .= "i";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param($update_types, ...$update_params);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update booking: " . $update_stmt->error);
            }
            $update_stmt->close();
        }
        
        // Handle guests if provided
        if (isset($data['guests']) && is_array($data['guests'])) {
            // First, delete all existing guests
            $delete_guests_query = "DELETE FROM wp_charterhub_booking_guests WHERE booking_id = ?";
            $delete_guests_stmt = $conn->prepare($delete_guests_query);
            $delete_guests_stmt->bind_param("i", $id);
            $delete_guests_stmt->execute();
            $delete_guests_stmt->close();
            
            // Then add new guests
            foreach ($data['guests'] as $guest) {
                if (isset($guest['id'])) {
                    $guest_id = intval($guest['id']);
                    
                    // Add guest to booking
                    $add_guest_query = "INSERT INTO wp_charterhub_booking_guests (booking_id, user_id, created_at) VALUES (?, ?, NOW())";
                    $add_guest_stmt = $conn->prepare($add_guest_query);
                    $add_guest_stmt->bind_param("ii", $id, $guest_id);
                    $add_guest_stmt->execute();
                    $add_guest_stmt->close();
                    
                    // Update guest notes if provided
                    if (isset($guest['notes'])) {
                        $notes = $guest['notes'];
                        $update_notes_query = "UPDATE wp_charterhub_users SET notes = ? WHERE id = ?";
                        $update_notes_stmt = $conn->prepare($update_notes_query);
                        $update_notes_stmt->bind_param("si", $notes, $guest_id);
                        $update_notes_stmt->execute();
                        $update_notes_stmt->close();
                    }
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Fetch the updated booking
        $select_query = "SELECT 
                            b.id,
                            b.yacht_id,
                            b.start_date,
                            b.end_date,
                            b.status,
                            b.total_price,
                            b.special_requests,
                            b.created_by_admin_id,
                            admin.first_name as admin_first_name,
                            admin.last_name as admin_last_name,
                            admin.email as admin_email,
                            b.main_charterer_id,
                            u_main.first_name as main_charterer_first_name,
                            u_main.last_name as main_charterer_last_name,
                            u_main.email as main_charterer_email,
                            b.created_at
                          FROM wp_charterhub_bookings b
                          LEFT JOIN wp_charterhub_users u_main ON b.main_charterer_id = u_main.id
                          LEFT JOIN wp_charterhub_users admin ON b.created_by_admin_id = admin.id
                          WHERE b.id = ?";
        
        $select_stmt = $conn->prepare($select_query);
        $select_stmt->bind_param("i", $id);
        $select_stmt->execute();
        $result = $select_stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Failed to retrieve updated booking');
        }
        
        $row = $result->fetch_assoc();
        $select_stmt->close();
        
        // Get booking guests
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
        $guests_stmt->bind_param("i", $id);
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
        $booking = [
            'id' => (int)$row['id'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'status' => $row['status'],
            'totalPrice' => (float)$row['total_price'],
            'specialRequests' => $row['special_requests'] ?? '',
            'createdAt' => $row['created_at'],
            'created_by_admin_id' => $row['created_by_admin_id'] ? (int)$row['created_by_admin_id'] : null,
            'created_by_admin' => $row['created_by_admin_id'] ? ($row['admin_first_name'] . ' ' . $row['admin_last_name']) : 'Administrator',
            'admin' => $row['created_by_admin_id'] ? [
                'id' => (int)$row['created_by_admin_id'],
                'name' => $row['admin_first_name'] . ' ' . $row['admin_last_name'],
                'email' => $row['admin_email']
            ] : null,
            'yacht' => [
                'id' => (int)$row['yacht_id'],
                'name' => "Yacht #" . $row['yacht_id']  // Use placeholder since we don't have yacht table
            ],
            'mainCharterer' => [
                'id' => (int)$row['main_charterer_id'],
                'firstName' => $row['main_charterer_first_name'],
                'lastName' => $row['main_charterer_last_name'],
                'email' => $row['main_charterer_email']
            ],
            'guestList' => $guests
        ];
        
        $conn->close();
        
        // Return success response with updated booking
        json_response([
            'success' => true,
            'message' => 'Booking updated successfully',
            'data' => $booking
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($conn) && $conn->ping()) {
            try {
                $conn->rollback();
            } catch (Exception $rollback_e) {
                error_log("Rollback error: " . $rollback_e->getMessage());
            }
        }
        
        // Log error details
        error_log("Admin booking update error: " . $e->getMessage());
        
        // Return error response
        json_response([
            'success' => false,
            'message' => 'Failed to update booking: ' . $e->getMessage()
        ], 400);
    }
}

/**
 * Handle DELETE request - Delete a booking and its related records
 */
function handle_delete_request() {
    try {
        // Get database connection
        $conn = get_database_connection();
        
        // Get booking ID from query parameter
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        if (!$id) {
            throw new Exception('Booking ID is required for deletion');
        }
        
        // Start transaction for safe deletion
        $conn->begin_transaction();
        
        // First check if booking exists
        $check_query = "SELECT id FROM wp_charterhub_bookings WHERE id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('Booking not found');
        }
        $check_stmt->close();
        
        // Delete booking guests first (foreign key constraint)
        $delete_guests_query = "DELETE FROM wp_charterhub_booking_guests WHERE booking_id = ?";
        $delete_guests_stmt = $conn->prepare($delete_guests_query);
        $delete_guests_stmt->bind_param("i", $id);
        
        if (!$delete_guests_stmt->execute()) {
            throw new Exception("Failed to delete booking guests: " . $delete_guests_stmt->error);
        }
        $delete_guests_stmt->close();
        
        // Now delete the booking
        $delete_booking_query = "DELETE FROM wp_charterhub_bookings WHERE id = ?";
        $delete_booking_stmt = $conn->prepare($delete_booking_query);
        $delete_booking_stmt->bind_param("i", $id);
        
        if (!$delete_booking_stmt->execute()) {
            throw new Exception("Failed to delete booking: " . $delete_booking_stmt->error);
        }
        $delete_booking_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Return success response
        json_response([
            'success' => true,
            'message' => 'Booking deleted successfully',
            'id' => $id
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($conn)) {
            try {
                $conn->rollback();
            } catch (Exception $rollback_e) {
                error_log("Rollback error: " . $rollback_e->getMessage());
            }
        }
        
        // Log error details
        error_log("Admin booking deletion error: " . $e->getMessage());
        
        // Return error response
        json_response([
            'success' => false,
            'message' => 'Failed to delete booking: ' . $e->getMessage()
        ], 400);
    }
} 