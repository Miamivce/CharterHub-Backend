<?php
/**
 * Test Booking API Endpoint
 * 
 * This endpoint retrieves bookings for a specific user ID.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if constant is already defined before defining it
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include necessary files with error handling
require_once __DIR__ . '/../auth/global-cors.php';

// Enable CORS
apply_cors_headers("GET,OPTIONS");

// Set JSON content type
header('Content-Type: application/json');

// Initialize response
$response = [
    'success' => false,
    'message' => 'Initializing',
    'data' => []
];

try {
    // Database configuration
    $db_host = 'localhost';
    $db_name = 'charterhub_local';
    $db_user = 'root'; // Using root for direct access
    $db_pass = ''; // Empty password for root

    // Create connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Set user ID (hardcoded for test)
    $user_id = 169;
    
    // Get all bookings where user is either main charterer or guest
    $query = "SELECT 
                b.id,
                b.yacht_id, 
                y.name as yacht_name,
                b.start_date,
                b.end_date,
                b.status,
                b.total_price,
                b.main_charterer_id,
                CASE WHEN b.main_charterer_id = ? THEN 'charterer' ELSE 'guest' END as user_role
              FROM wp_charterhub_bookings b
              LEFT JOIN wp_charterhub_yachts y ON b.yacht_id = y.id
              WHERE (b.main_charterer_id = ? OR 
                    b.id IN (SELECT booking_id FROM wp_charterhub_booking_guests WHERE user_id = ?))";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = [
            'id' => (int)$row['id'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'status' => $row['status'],
            'totalPrice' => (float)$row['total_price'],
            'yacht' => [
                'id' => (int)$row['yacht_id'],
                'name' => $row['yacht_name']
            ],
            'userRole' => $row['user_role'],
            'mainChartererId' => (int)$row['main_charterer_id']
        ];
    }
    $stmt->close();
    
    $conn->close();
    
    $response = [
        'success' => true,
        'message' => 'Bookings retrieved successfully',
        'count' => count($bookings),
        'data' => $bookings
    ];
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ];
}

// Output JSON response
echo json_encode($response, JSON_PRETTY_PRINT); 