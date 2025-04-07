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
        $query = "
            SELECT 
                p.ID as id,
                p.post_title as name,
                p.post_content as description,
                (SELECT pm1.meta_value FROM wp_postmeta pm1 WHERE pm1.post_id = p.ID AND pm1.meta_key = 'capacity' LIMIT 1) as capacity,
                (SELECT pm2.meta_value FROM wp_postmeta pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = 'length' LIMIT 1) as length,
                (SELECT pm3.meta_value FROM wp_postmeta pm3 WHERE pm3.post_id = p.ID AND pm3.meta_key = 'crew' LIMIT 1) as crew,
                (SELECT pm4.meta_value FROM wp_postmeta pm4 WHERE pm4.post_id = p.ID AND pm4.meta_key = 'base_price' LIMIT 1) as base_price,
                (SELECT pm5.meta_value FROM wp_postmeta pm5 WHERE pm5.post_id = p.ID AND pm5.meta_key = '_thumbnail_id' LIMIT 1) as featured_image_id,
                p.post_modified as updated_at
            FROM 
                wp_posts p
            WHERE 
                p.post_type = 'yacht'
                AND p.post_status = 'publish'
                AND p.ID = ?
        ";
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
        
        // Get the featured image URL if available
        if (!empty($yacht['featured_image_id'])) {
            $image_query = "
                SELECT guid FROM wp_posts 
                WHERE ID = ? AND post_type = 'attachment'
            ";
            $image_stmt = $conn->prepare($image_query);
            $image_stmt->bind_param("i", $yacht['featured_image_id']);
            $image_stmt->execute();
            $image_result = $image_stmt->get_result();
            
            if ($image_result->num_rows > 0) {
                $image = $image_result->fetch_assoc();
                $yacht['featured_image'] = $image['guid'];
            } else {
                $yacht['featured_image'] = '';
            }
            
            $image_stmt->close();
            unset($yacht['featured_image_id']);
        } else {
            $yacht['featured_image'] = '';
            unset($yacht['featured_image_id']);
        }
        
        $stmt->close();
        $conn->close();
        
        json_response([
            'success' => true,
            'message' => 'Yacht retrieved successfully',
            'data' => $yacht
        ]);
    } else {
        // Get all yachts
        $query = "
            SELECT 
                p.ID as id,
                p.post_title as name,
                p.post_content as description,
                (SELECT pm1.meta_value FROM wp_postmeta pm1 WHERE pm1.post_id = p.ID AND pm1.meta_key = 'capacity' LIMIT 1) as capacity,
                (SELECT pm2.meta_value FROM wp_postmeta pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = 'length' LIMIT 1) as length,
                (SELECT pm3.meta_value FROM wp_postmeta pm3 WHERE pm3.post_id = p.ID AND pm3.meta_key = 'crew' LIMIT 1) as crew,
                (SELECT pm4.meta_value FROM wp_postmeta pm4 WHERE pm4.post_id = p.ID AND pm4.meta_key = 'base_price' LIMIT 1) as base_price,
                (SELECT pm5.meta_value FROM wp_postmeta pm5 WHERE pm5.post_id = p.ID AND pm5.meta_key = '_thumbnail_id' LIMIT 1) as featured_image_id,
                p.post_modified as updated_at
            FROM 
                wp_posts p
            WHERE 
                p.post_type = 'yacht'
                AND p.post_status = 'publish'
            ORDER BY 
                p.post_title ASC
            LIMIT 100
        ";
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
            // Get the featured image URL if available
            if (!empty($row['featured_image_id'])) {
                $image_query = "
                    SELECT guid FROM wp_posts 
                    WHERE ID = {$row['featured_image_id']} AND post_type = 'attachment'
                ";
                $image_result = $conn->query($image_query);
                
                if ($image_result && $image_result->num_rows > 0) {
                    $image = $image_result->fetch_assoc();
                    $row['featured_image'] = $image['guid'];
                } else {
                    $row['featured_image'] = '';
                }
                
                unset($row['featured_image_id']);
            } else {
                $row['featured_image'] = '';
                unset($row['featured_image_id']);
            }
            
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
    // Get database configuration from environment variables
    $db_host = getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com';
    $db_port = getenv('DB_PORT') ?: '19174';
    $db_name = getenv('DB_NAME') ?: 'defaultdb';
    $db_user = getenv('DB_USER') ?: 'avnadmin';
    $db_pass = getenv('DB_PASSWORD') ?: 'AVNS_HCZbm5bZJE1L9C8Pz8C';
    
    // Create connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    
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