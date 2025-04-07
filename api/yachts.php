<?php
/**
 * Yachts API Endpoint
 * 
 * This endpoint provides access to yacht data.
 * 
 * Supports:
 * - GET: Retrieve all yachts or a specific yacht by ID
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
 * Handle GET request - List all yachts or a specific yacht
 */
function handle_get_request() {
    $conn = get_database_connection();
    
    // Check if an ID was provided to get a specific yacht
    $yacht_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if ($yacht_id) {
        // Get a specific yacht by ID
        $query = "SELECT * FROM wp_charterhub_yachts WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $yacht_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            json_response([
                'success' => false,
                'message' => 'Yacht not found'
            ], 404);
        }
        
        $yacht = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        json_response([
            'success' => true,
            'message' => 'Yacht retrieved successfully',
            'data' => $yacht
        ]);
    } else {
        // Get all yachts
        $query = "SELECT * FROM wp_charterhub_yachts ORDER BY name ASC";
        $result = $conn->query($query);
        
        if (!$result) {
            error_log("SQL Error in yachts query: " . $conn->error);
            json_response([
                'success' => false,
                'message' => 'Database error retrieving yachts'
            ], 500);
        }
        
        $yachts = [];
        while ($row = $result->fetch_assoc()) {
            $yachts[] = $row;
        }
        
        $conn->close();
        
        json_response([
            'success' => true,
            'message' => 'Yachts retrieved successfully',
            'data' => $yachts
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