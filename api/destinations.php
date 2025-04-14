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
    $location_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if ($location_id) {
        // Get a specific destination by ID
        $query = "
            SELECT 
                p.ID as id,
                p.post_title as name,
                p.post_content as description,
                (SELECT pm1.meta_value FROM wp_postmeta pm1 WHERE pm1.post_id = p.ID AND pm1.meta_key = 'regions' LIMIT 1) as regions,
                (SELECT pm2.meta_value FROM wp_postmeta pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = 'highlights' LIMIT 1) as highlights,
                (SELECT pm3.meta_value FROM wp_postmeta pm3 WHERE pm3.post_id = p.ID AND pm3.meta_key = 'best_time_to_visit' LIMIT 1) as best_time_to_visit,
                (SELECT pm4.meta_value FROM wp_postmeta pm4 WHERE pm4.post_id = p.ID AND pm4.meta_key = 'climate' LIMIT 1) as climate,
                (SELECT pm5.meta_value FROM wp_postmeta pm5 WHERE pm5.post_id = p.ID AND pm5.meta_key = '_thumbnail_id' LIMIT 1) as featured_image_id,
                (SELECT pm6.meta_value FROM wp_postmeta pm6 WHERE pm6.post_id = p.ID AND pm6.meta_key = 'featured_image_url' LIMIT 1) as direct_featured_image,
                p.post_modified as updated_at
            FROM 
                wp_posts p
            WHERE 
                p.post_type = 'location'
                AND p.post_status = 'publish'
                AND p.ID = ?
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $location_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            json_response([
                'success' => false,
                'message' => 'Destination not found'
            ], 404);
        }
        
        $destination = $result->fetch_assoc();
        
        // Get the featured image URL if available
        if (!empty($destination['featured_image_id'])) {
            $image_query = "
                SELECT guid FROM wp_posts 
                WHERE ID = ? AND post_type = 'attachment'
            ";
            $image_stmt = $conn->prepare($image_query);
            $image_stmt->bind_param("i", $destination['featured_image_id']);
            $image_stmt->execute();
            $image_result = $image_stmt->get_result();
            
            if ($image_result->num_rows > 0) {
                $image = $image_result->fetch_assoc();
                $destination['featured_image'] = $image['guid'];
            } else {
                // Check if we have a direct image URL stored
                $destination['featured_image'] = !empty($destination['direct_featured_image']) ? 
                    $destination['direct_featured_image'] : 
                    get_default_image_for_destination($destination['name']);
            }
            
            $image_stmt->close();
            unset($destination['featured_image_id']);
        } else {
            // Try to use direct featured image URL if available
            $destination['featured_image'] = !empty($destination['direct_featured_image']) ? 
                $destination['direct_featured_image'] : 
                get_default_image_for_destination($destination['name']);
        }
        unset($destination['direct_featured_image']); // Remove this field from the response
        
        // Convert highlights to array if it's serialized
        if (!empty($destination['highlights'])) {
            if (is_serialized($destination['highlights'])) {
                $destination['highlights'] = unserialize($destination['highlights']);
            } else {
                // If not serialized, make sure it's an array
                $destination['highlights'] = [$destination['highlights']];
            }
        } else {
            $destination['highlights'] = [];
        }
        
        // Convert regions to array if it's serialized
        if (!empty($destination['regions'])) {
            if (is_serialized($destination['regions'])) {
                $destination['regions'] = unserialize($destination['regions']);
            } else {
                // If not serialized, make sure it's an array
                $destination['regions'] = [$destination['regions']];
            }
        } else {
            $destination['regions'] = [];
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
        $query = "
            SELECT 
                p.ID as id,
                p.post_title as name,
                p.post_content as description,
                (SELECT pm1.meta_value FROM wp_postmeta pm1 WHERE pm1.post_id = p.ID AND pm1.meta_key = 'regions' LIMIT 1) as regions,
                (SELECT pm2.meta_value FROM wp_postmeta pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = 'highlights' LIMIT 1) as highlights,
                (SELECT pm3.meta_value FROM wp_postmeta pm3 WHERE pm3.post_id = p.ID AND pm3.meta_key = 'best_time_to_visit' LIMIT 1) as best_time_to_visit,
                (SELECT pm4.meta_value FROM wp_postmeta pm4 WHERE pm4.post_id = p.ID AND pm4.meta_key = 'climate' LIMIT 1) as climate,
                (SELECT pm5.meta_value FROM wp_postmeta pm5 WHERE pm5.post_id = p.ID AND pm5.meta_key = '_thumbnail_id' LIMIT 1) as featured_image_id,
                (SELECT pm6.meta_value FROM wp_postmeta pm6 WHERE pm6.post_id = p.ID AND pm6.meta_key = 'featured_image_url' LIMIT 1) as direct_featured_image,
                p.post_modified as updated_at
            FROM 
                wp_posts p
            WHERE 
                p.post_type = 'location'
                AND p.post_status = 'publish'
            ORDER BY 
                p.post_title ASC
            LIMIT 50
        ";
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
                    // Check if we have a direct image URL stored
                    $row['featured_image'] = !empty($row['direct_featured_image']) ? 
                        $row['direct_featured_image'] : 
                        get_default_image_for_destination($row['name']);
                }
                
                unset($row['featured_image_id']);
            } else {
                // Try to use direct featured image URL if available
                $row['featured_image'] = !empty($row['direct_featured_image']) ? 
                    $row['direct_featured_image'] : 
                    get_default_image_for_destination($row['name']);
            }
            unset($row['direct_featured_image']); // Remove this field from the response
            
            // Convert highlights to array if it's serialized
            if (!empty($row['highlights'])) {
                if (is_serialized($row['highlights'])) {
                    $row['highlights'] = unserialize($row['highlights']);
                } else {
                    // If not serialized, make sure it's an array
                    $row['highlights'] = [$row['highlights']];
                }
            } else {
                $row['highlights'] = [];
            }
            
            // Convert regions to array if it's serialized
            if (!empty($row['regions'])) {
                if (is_serialized($row['regions'])) {
                    $row['regions'] = unserialize($row['regions']);
                } else {
                    // If not serialized, make sure it's an array
                    $row['regions'] = [$row['regions']];
                }
            } else {
                $row['regions'] = [];
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

// Helper function to determine if a string is serialized
function is_serialized($data) {
    // If it isn't a string, it isn't serialized
    if (!is_string($data)) {
        return false;
    }
    
    // Check for serialization signature
    $data = trim($data);
    if ('N;' == $data) {
        return true;
    }
    if (!preg_match('/^([adObis]):/', $data, $badions)) {
        return false;
    }
    
    switch ($badions[1]) {
        case 'a':
        case 'O':
        case 's':
            if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                return true;
            }
            break;
        case 'b':
        case 'i':
        case 'd':
            if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                return true;
            }
            break;
    }
    
    return false;
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

/**
 * Get a default image URL for a destination based on its name
 * 
 * @param string $destination_name The name of the destination
 * @return string The URL to a default image
 */
function get_default_image_for_destination($destination_name) {
    // Use a static map of destination images rather than dynamic generation
    // The goal is to prevent external API calls that could cause server issues
    $destination_map = [
        'BAHAMAS' => 'https://yachtstory.com/wp-content/uploads/2024/02/bahamas.jpg',
        'BALEARIC ISLANDS' => 'https://yachtstory.com/wp-content/uploads/2024/02/balearic.jpg',
        'CARIBBEAN' => 'https://yachtstory.com/wp-content/uploads/2024/02/caribbean.jpg',
        'CORSICA & SARDINIA' => 'https://yachtstory.com/wp-content/uploads/2024/02/corsica.jpg',
        'CROATIA & MONTENEGRO' => 'https://yachtstory.com/wp-content/uploads/2024/02/croatia.jpg',
        'FRENCH POLYNESIA' => 'https://yachtstory.com/wp-content/uploads/2024/02/polynesia.jpg',
        'FRENCH RIVIERA' => 'https://yachtstory.com/wp-content/uploads/2024/02/french-riviera.jpg',
        'GALAPAGOS' => 'https://yachtstory.com/wp-content/uploads/2024/02/galapagos.jpg',
        'GREEK ISLANDS' => 'https://yachtstory.com/wp-content/uploads/2024/02/greek.jpg',
        'INDONESIA' => 'https://yachtstory.com/wp-content/uploads/2024/02/indonesia.jpg',
        'ITALIAN RIVIERA' => 'https://yachtstory.com/wp-content/uploads/2024/02/italian-riviera.jpg',
        'MALAYSIA' => 'https://yachtstory.com/wp-content/uploads/2024/02/malaysia.jpg',
        'MALDIVES' => 'https://yachtstory.com/wp-content/uploads/2024/02/maldives.jpg',
        'RED SEA' => 'https://yachtstory.com/wp-content/uploads/2024/02/red-sea.jpg',
        'SEYCHELLES' => 'https://yachtstory.com/wp-content/uploads/2024/02/seychelles.jpg',
        'SICILY & AEOLIAN ISLANDS' => 'https://yachtstory.com/wp-content/uploads/2024/02/sicily.jpg',
        'SOUTH PACIFIC' => 'https://yachtstory.com/wp-content/uploads/2024/02/south-pacific.jpg',
        'THAILAND' => 'https://yachtstory.com/wp-content/uploads/2024/02/thailand.jpg',
        'TURKISH RIVIERA' => 'https://yachtstory.com/wp-content/uploads/2024/02/turkish-riviera.jpg',
        'UK' => 'https://yachtstory.com/wp-content/uploads/2024/02/uk.jpg'
    ];
    
    // Default fallback image for any destination not in the map
    $default_image = 'https://yachtstory.com/wp-content/uploads/2024/02/yacht-destination.jpg';
    
    // Return the mapped image or default
    return isset($destination_map[$destination_name]) ? $destination_map[$destination_name] : $default_image;
} 