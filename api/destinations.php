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
        // Check for debug mode first
        if (isset($_GET['debug']) && $_GET['debug'] === 'image_data') {
            debug_image_data();
        } else {
            handle_get_request();
        }
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
        
        // If a featured image was found, use it
        if (!empty($destination['featured_image_id'])) {
            $image_query = "
                SELECT p.guid, p.post_title 
                FROM wp_posts p
                WHERE p.ID = ? AND p.post_type = 'attachment'
            ";
            $image_stmt = $conn->prepare($image_query);
            $image_stmt->bind_param("i", $destination['featured_image_id']);
            $image_stmt->execute();
            $image_result = $image_stmt->get_result();
            
            if ($image_result && $image_result->num_rows > 0) {
                $image = $image_result->fetch_assoc();
                $destination['featured_image'] = $image['guid'];
                
                // If the guid doesn't start with http, it might be a relative URL
                if (!empty($destination['featured_image']) && substr($destination['featured_image'], 0, 4) !== 'http') {
                    // Try to construct a full URL
                    $site_url = get_site_url();
                    $destination['featured_image'] = rtrim($site_url, '/') . '/' . ltrim($destination['featured_image'], '/');
                }
            } else {
                // Try to get image URL from wp_postmeta
                $meta_query = "
                    SELECT meta_value 
                    FROM wp_postmeta 
                    WHERE post_id = ? AND meta_key = '_wp_attached_file'
                ";
                $meta_stmt = $conn->prepare($meta_query);
                $meta_stmt->bind_param("i", $destination['featured_image_id']);
                $meta_stmt->execute();
                $meta_result = $meta_stmt->get_result();
                
                if ($meta_result && $meta_result->num_rows > 0) {
                    $meta = $meta_result->fetch_assoc();
                    $upload_dir = wp_upload_dir();
                    $destination['featured_image'] = $upload_dir['baseurl'] . '/' . $meta['meta_value'];
                } else {
                    $destination['featured_image'] = '';
                }
                $meta_stmt->close();
            }
            
            $image_stmt->close();
            unset($destination['featured_image_id']);
        } else if (!empty($destination['direct_featured_image'])) {
            // Use direct image URL if available
            $destination['featured_image'] = $destination['direct_featured_image'];
        } else {
            // If no featured image was found, use our fallback function
            $destination['featured_image'] = get_default_image_for_destination($destination['name']);
        }
        unset($destination['direct_featured_image']); // Remove this field from the response
        
        // If no featured image was found, try to get the banner image from ACF field
        if (empty($destination['featured_image'])) {
            $banner_query = "
                SELECT meta_value 
                FROM wp_postmeta 
                WHERE post_id = ? AND meta_key = 'destination_detail__banner_image'
                LIMIT 1
            ";
            $banner_stmt = $conn->prepare($banner_query);
            $banner_stmt->bind_param("i", $location_id);
            $banner_stmt->execute();
            $banner_result = $banner_stmt->get_result();
            
            if ($banner_result && $banner_result->num_rows > 0) {
                $banner = $banner_result->fetch_assoc();
                $banner_id = $banner['meta_value'];
                
                if (!empty($banner_id)) {
                    // First try to get the image URL from wp_posts
                    $banner_image_query = "
                        SELECT guid 
                        FROM wp_posts 
                        WHERE ID = ? AND post_type = 'attachment'
                        LIMIT 1
                    ";
                    $banner_image_stmt = $conn->prepare($banner_image_query);
                    $banner_image_stmt->bind_param("i", $banner_id);
                    $banner_image_stmt->execute();
                    $banner_image_result = $banner_image_stmt->get_result();
                    
                    if ($banner_image_result && $banner_image_result->num_rows > 0) {
                        $banner_image = $banner_image_result->fetch_assoc();
                        $destination['featured_image'] = $banner_image['guid'];
                        
                        // If the guid doesn't start with http, it might be a relative URL
                        if (!empty($destination['featured_image']) && substr($destination['featured_image'], 0, 4) !== 'http') {
                            $site_url = get_site_url();
                            $destination['featured_image'] = rtrim($site_url, '/') . '/' . ltrim($destination['featured_image'], '/');
                        }
                    } else {
                        // Try to get image URL from wp_postmeta
                        $banner_meta_query = "
                            SELECT meta_value 
                            FROM wp_postmeta 
                            WHERE post_id = ? AND meta_key = '_wp_attached_file'
                            LIMIT 1
                        ";
                        $banner_meta_stmt = $conn->prepare($banner_meta_query);
                        $banner_meta_stmt->bind_param("i", $banner_id);
                        $banner_meta_stmt->execute();
                        $banner_meta_result = $banner_meta_stmt->get_result();
                        
                        if ($banner_meta_result && $banner_meta_result->num_rows > 0) {
                            $banner_meta = $banner_meta_result->fetch_assoc();
                            $upload_dir = wp_upload_dir();
                            $destination['featured_image'] = $upload_dir['baseurl'] . '/' . $banner_meta['meta_value'];
                        }
                        
                        $banner_meta_stmt->close();
                    }
                    
                    $banner_image_stmt->close();
                }
            }
            
            $banner_stmt->close();
        }
        
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
            // If a featured image was found, use it
            if (!empty($row['featured_image_id'])) {
                $image_query = "
                    SELECT p.guid, p.post_title 
                    FROM wp_posts p 
                    WHERE p.ID = {$row['featured_image_id']} AND p.post_type = 'attachment'
                ";
                $image_result = $conn->query($image_query);
                
                if ($image_result && $image_result->num_rows > 0) {
                    $image = $image_result->fetch_assoc();
                    $row['featured_image'] = $image['guid'];
                    
                    // If the guid doesn't start with http, it might be a relative URL
                    if (!empty($row['featured_image']) && substr($row['featured_image'], 0, 4) !== 'http') {
                        // Try to construct a full URL
                        $site_url = get_site_url();
                        $row['featured_image'] = rtrim($site_url, '/') . '/' . ltrim($row['featured_image'], '/');
                    }
                } else {
                    // Try to get image URL from wp_postmeta
                    $meta_query = "
                        SELECT meta_value 
                        FROM wp_postmeta 
                        WHERE post_id = {$row['featured_image_id']} AND meta_key = '_wp_attached_file'
                    ";
                    $meta_result = $conn->query($meta_query);
                    
                    if ($meta_result && $meta_result->num_rows > 0) {
                        $meta = $meta_result->fetch_assoc();
                        $upload_dir = wp_upload_dir();
                        $row['featured_image'] = $upload_dir['baseurl'] . '/' . $meta['meta_value'];
                    } else {
                        $row['featured_image'] = '';
                    }
                }
                
                unset($row['featured_image_id']);
            } else if (!empty($row['direct_featured_image'])) {
                // Use direct image URL if available
                $row['featured_image'] = $row['direct_featured_image'];
            } else {
                // If no featured image was found, use our fallback function
                $row['featured_image'] = get_default_image_for_destination($row['name']);
            }
            unset($row['direct_featured_image']); // Remove this field from the response
            
            // If no featured image was found, try to get the banner image from ACF field
            if (empty($row['featured_image'])) {
                $banner_query = "
                    SELECT meta_value 
                    FROM wp_postmeta 
                    WHERE post_id = {$row['id']} AND meta_key = 'destination_detail__banner_image'
                    LIMIT 1
                ";
                $banner_result = $conn->query($banner_query);
                
                if ($banner_result && $banner_result->num_rows > 0) {
                    $banner = $banner_result->fetch_assoc();
                    $banner_id = $banner['meta_value'];
                    
                    if (!empty($banner_id)) {
                        // First try to get the image URL from wp_posts
                        $banner_image_query = "
                            SELECT guid 
                            FROM wp_posts 
                            WHERE ID = {$banner_id} AND post_type = 'attachment'
                            LIMIT 1
                        ";
                        $banner_image_result = $conn->query($banner_image_query);
                        
                        if ($banner_image_result && $banner_image_result->num_rows > 0) {
                            $banner_image = $banner_image_result->fetch_assoc();
                            $row['featured_image'] = $banner_image['guid'];
                            
                            // If the guid doesn't start with http, it might be a relative URL
                            if (!empty($row['featured_image']) && substr($row['featured_image'], 0, 4) !== 'http') {
                                $site_url = get_site_url();
                                $row['featured_image'] = rtrim($site_url, '/') . '/' . ltrim($row['featured_image'], '/');
                            }
                        } else {
                            // Try to get image URL from wp_postmeta
                            $banner_meta_query = "
                                SELECT meta_value 
                                FROM wp_postmeta 
                                WHERE post_id = {$banner_id} AND meta_key = '_wp_attached_file'
                                LIMIT 1
                            ";
                            $banner_meta_result = $conn->query($banner_meta_query);
                            
                            if ($banner_meta_result && $banner_meta_result->num_rows > 0) {
                                $banner_meta = $banner_meta_result->fetch_assoc();
                                $upload_dir = wp_upload_dir();
                                $row['featured_image'] = $upload_dir['baseurl'] . '/' . $banner_meta['meta_value'];
                            }
                        }
                    }
                }
            }
            
            // No fallback images - we only use what's in the database
            
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

/**
 * Debug function to check image data being retrieved
 */
function debug_image_data() {
    $conn = get_database_connection();
    $data = [];
    
    // Get site URL information
    $data['site_url'] = get_site_url();
    
    // Get upload directory information
    $data['upload_dir'] = wp_upload_dir();
    
    // Get a sample of destinations with their image data
    $query = "
        SELECT 
            p.ID as id,
            p.post_title as name,
            (SELECT pm.meta_value FROM wp_postmeta pm WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' LIMIT 1) as featured_image_id
        FROM 
            wp_posts p
        WHERE 
            p.post_type = 'location'
            AND p.post_status = 'publish'
        LIMIT 5
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        $data['error'] = "SQL Error: " . $conn->error;
    } else {
        $data['destinations'] = [];
        
        while ($destination = $result->fetch_assoc()) {
            $dest_data = [
                'id' => $destination['id'],
                'name' => $destination['name'],
                'featured_image_id' => $destination['featured_image_id'],
                'image_data' => []
            ];
            
            // If there's a featured image ID, get full data
            if (!empty($destination['featured_image_id'])) {
                // Get guid from wp_posts
                $image_query = "
                    SELECT p.ID, p.guid, p.post_title, p.post_type, p.post_mime_type
                    FROM wp_posts p
                    WHERE p.ID = ?
                ";
                
                $image_stmt = $conn->prepare($image_query);
                $image_stmt->bind_param("i", $destination['featured_image_id']);
                $image_stmt->execute();
                $image_result = $image_stmt->get_result();
                
                if ($image_result && $image_result->num_rows > 0) {
                    $dest_data['image_data']['wp_posts'] = $image_result->fetch_assoc();
                } else {
                    $dest_data['image_data']['wp_posts'] = 'Not found';
                }
                
                $image_stmt->close();
                
                // Get metadata from wp_postmeta
                $meta_query = "
                    SELECT meta_key, meta_value
                    FROM wp_postmeta
                    WHERE post_id = ?
                    AND (
                        meta_key = '_wp_attached_file' 
                        OR meta_key LIKE '%thumbnail%'
                        OR meta_key LIKE '%image%'
                    )
                ";
                
                $meta_stmt = $conn->prepare($meta_query);
                $meta_stmt->bind_param("i", $destination['featured_image_id']);
                $meta_stmt->execute();
                $meta_result = $meta_stmt->get_result();
                
                if ($meta_result && $meta_result->num_rows > 0) {
                    $dest_data['image_data']['wp_postmeta'] = [];
                    
                    while ($meta = $meta_result->fetch_assoc()) {
                        $dest_data['image_data']['wp_postmeta'][] = $meta;
                    }
                    
                    // Generate complete URL examples
                    if (count($dest_data['image_data']['wp_postmeta']) > 0) {
                        $dest_data['image_data']['url_examples'] = [];
                        
                        foreach ($dest_data['image_data']['wp_postmeta'] as $meta) {
                            if ($meta['meta_key'] === '_wp_attached_file') {
                                $dest_data['image_data']['url_examples'][] = [
                                    'description' => 'Standard WP URL',
                                    'url' => $data['upload_dir']['baseurl'] . '/' . $meta['meta_value']
                                ];
                            }
                        }
                        
                        // Add the guid as an example
                        if (isset($dest_data['image_data']['wp_posts']['guid'])) {
                            $guid = $dest_data['image_data']['wp_posts']['guid'];
                            
                            $dest_data['image_data']['url_examples'][] = [
                                'description' => 'Using GUID directly',
                                'url' => $guid
                            ];
                            
                            // If GUID doesn't start with http
                            if (substr($guid, 0, 4) !== 'http') {
                                $dest_data['image_data']['url_examples'][] = [
                                    'description' => 'GUID with site URL prepended',
                                    'url' => rtrim($data['site_url'], '/') . '/' . ltrim($guid, '/')
                                ];
                            }
                        }
                    }
                } else {
                    $dest_data['image_data']['wp_postmeta'] = 'No relevant metadata found';
                }
                
                $meta_stmt->close();
            }
            
            $data['destinations'][] = $dest_data;
        }
    }
    
    $conn->close();
    
    // Include DB connection info
    $data['db_info'] = [
        'host' => getenv('DB_HOST') ?: 'mysql-charterhub-charterhub.c.aivencloud.com',
        'port' => getenv('DB_PORT') ?: '19174',
        'name' => getenv('DB_NAME') ?: 'defaultdb'
    ];
    
    json_response([
        'success' => true,
        'message' => 'Debug data for image retrieval',
        'data' => $data
    ]);
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
    // Map of destination names to local image paths
    $destination_images = [
        'BAHAMAS' => '/public/images/destinations/bahamas.jpg',
        'BALEARIC ISLANDS' => '/public/images/destinations/balearic.jpg',
        'CARIBBEAN' => '/public/images/destinations/caribbean.jpg',
        'CORSICA & SARDINIA' => '/public/images/destinations/corsica.jpg',
        'CROATIA & MONTENEGRO' => '/public/images/destinations/croatia.jpg',
        'FRENCH POLYNESIA' => '/public/images/destinations/french-polynesia.jpg',
        'FRENCH RIVIERA' => '/public/images/destinations/french-riviera.jpg',
        'GALAPAGOS' => '/public/images/destinations/galapagos.jpg',
        'GREEK ISLANDS' => '/public/images/destinations/greek-islands.jpg',
        'INDONESIA' => '/public/images/destinations/indonesia.jpg',
        'ITALIAN RIVIERA' => '/public/images/destinations/italian-riviera.jpg',
        'MALAYSIA' => '/public/images/destinations/malaysia.jpg',
        'MALDIVES' => '/public/images/destinations/maldives.jpg',
        'RED SEA' => '/public/images/destinations/red-sea.jpg',
        'SEYCHELLES' => '/public/images/destinations/seychelles.jpg',
        'SICILY & AEOLIAN ISLANDS' => '/public/images/destinations/sicily.jpg',
        'SOUTH PACIFIC' => '/public/images/destinations/south-pacific.jpg',
        'THAILAND' => '/public/images/destinations/thailand.jpg',
        'TURKISH RIVIERA' => '/public/images/destinations/turkish-riviera.jpg',
        'UK' => '/public/images/destinations/uk.jpg',
    ];
    
    // Clean the destination name for comparison
    $cleaned_name = strtoupper(trim($destination_name));
    
    // Return the mapped image or a default one
    if (isset($destination_images[$cleaned_name])) {
        // Get site URL to make absolute path
        $site_url = get_site_url();
        return rtrim($site_url, '/') . $destination_images[$cleaned_name];
    }
    
    // Default generic destination image
    $site_url = get_site_url();
    return rtrim($site_url, '/') . '/public/images/destinations/default.jpg';
}

// Get the site URL
function get_site_url() {
    // Try to get site URL from wp_options
    $conn = get_database_connection();
    $query = "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $siteurl = $row['option_value'];
        $conn->close();
        return $siteurl;
    }
    
    $conn->close();
    
    // Default URL if not found in database
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host;
}

// Get WordPress upload directory information
function wp_upload_dir() {
    // Get the site URL
    $siteurl = get_site_url();
    
    // Get the upload directory from wp_options
    $conn = get_database_connection();
    $query = "SELECT option_value FROM wp_options WHERE option_name = 'upload_path' LIMIT 1";
    $result = $conn->query($query);
    
    $upload_path = '';
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $upload_path = $row['option_value'];
    }
    
    $conn->close();
    
    // Default WordPress uploads structure
    if (empty($upload_path)) {
        $upload_path = 'wp-content/uploads';
    }
    
    return [
        'basedir' => $upload_path,
        'baseurl' => rtrim($siteurl, '/') . '/' . $upload_path
    ];
} 