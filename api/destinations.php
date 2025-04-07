<?php
/**
 * Destinations API Endpoint
 * 
 * This endpoint provides access to destination data.
 * 
 * Supports:
 * - GET: Retrieve all destinations or a specific destination by ID
 */

// Define CHARTERHUB_LOADED constant before including files
define('CHARTERHUB_LOADED', true);

// Include necessary files
require_once __DIR__ . '/../auth/global-cors.php';

// Apply global CORS headers
apply_cors_headers();

// Initialize response
$response = [
    'success' => false,
    'message' => 'Initializing request',
];

// Handle different HTTP methods
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        handle_get_request();
        break;
    default:
        json_response([
            'success' => false,
            'message' => 'Method not allowed'
        ], 405);
}

/**
 * Handle GET request - List all destinations or a specific destination
 */
function handle_get_request() {
    $conn = get_database_connection();
    
    // Check if an ID was provided to get a specific destination
    $destination_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if ($destination_id) {
        // Get a specific destination by ID
        $query = "SELECT * FROM wp_charterhub_destinations WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $destination_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            json_response([
                'success' => false,
                'message' => 'Destination not found'
            ], 404);
        }
        
        $destination = $result->fetch_assoc();
        
        // Parse JSON fields
        if ($destination['regions']) {
            $destination['regions'] = json_decode($destination['regions']);
        }
        if ($destination['highlights']) {
            $destination['highlights'] = json_decode($destination['highlights']);
        }
        
        $stmt->close();
        $conn->close();
        
        json_response([
            'success' => true,
            'message' => 'Destination retrieved successfully',
            'data' => $destination
        ]);
    } else {
        // Get all destinations
        $query = "SELECT * FROM wp_charterhub_destinations ORDER BY name ASC";
        $result = $conn->query($query);
        
        if (!$result) {
            error_log("SQL Error in destinations query: " . $conn->error);
            json_response([
                'success' => false,
                'message' => 'Database error retrieving destinations'
            ], 500);
        }
        
        $destinations = [];
        while ($row = $result->fetch_assoc()) {
            // Parse JSON fields
            if ($row['regions']) {
                $row['regions'] = json_decode($row['regions']);
            }
            if ($row['highlights']) {
                $row['highlights'] = json_decode($row['highlights']);
            }
            
            $destinations[] = $row;
        }
        
        $conn->close();
        
        json_response([
            'success' => true,
            'message' => 'Destinations retrieved successfully',
            'data' => $destinations
        ]);
    }
}

// Helper function to get database connection
function get_database_connection() {
    // Import database configuration
    require_once __DIR__ . '/../db-config.php';
    
    // Create connection
    $conn = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['dbname']);
    
    // Check connection
    if ($conn->connect_error) {
        error_log("Connection failed: " . $conn->connect_error);
        json_response([
            'success' => false,
            'message' => 'Database connection error'
        ], 500);
        exit;
    }
    
    return $conn;
}

// Helper function for json responses
function json_response($data, $status_code = 200) {
    header('Content-Type: application/json');
    http_response_code($status_code);
    echo json_encode($data);
    exit;
} 