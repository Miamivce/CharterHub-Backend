<?php
/**
 * Bookings API Test Endpoint
 *
 * This file provides a way to test the Bookings API endpoint without authentication.
 * It's designed to help diagnose issues with the API.
 */

// Force content type to be JSON
header('Content-Type: application/json');

// Start output buffering
ob_start();

// Prevent direct display of PHP errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Get the main API endpoint URL
$api_url = isset($_GET['url']) ? $_GET['url'] : 'https://charterhub-api.onrender.com/api/client/bookings.php';

// Add debug parameter if specified
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    $api_url .= (strpos($api_url, '?') === false ? '?' : '&') . 'debug=connection_test';
}

// Response container
$response = [
    'success' => false,
    'message' => 'Initializing API test',
    'timestamp' => date('Y-m-d H:i:s'),
    'target_url' => $api_url,
    'php_version' => PHP_VERSION
];

try {
    // Create a stream context with a reasonable timeout
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Content-Type: application/json',
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    
    // Make request to the API endpoint
    $api_response = @file_get_contents($api_url, false, $context);
    
    // Get response headers
    $response_headers = $http_response_header ?? [];
    $status_code = 0;
    
    // Extract status code from headers
    foreach ($response_headers as $header) {
        if (strpos($header, 'HTTP/') === 0) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches);
            $status_code = $matches[1] ?? 0;
            break;
        }
    }
    
    // Prepare response data
    $response['success'] = true;
    $response['status_code'] = $status_code;
    $response['headers'] = $response_headers;
    
    // Try to decode the API response as JSON
    $decoded_response = json_decode($api_response, true);
    
    if ($decoded_response !== null) {
        // Valid JSON response
        $response['api_response_valid'] = true;
        $response['api_response'] = $decoded_response;
    } else {
        // Invalid JSON response
        $response['api_response_valid'] = false;
        $response['json_error'] = json_last_error_msg();
        
        // Return the first 1000 characters of the response
        $response['api_response_sample'] = substr($api_response, 0, 1000);
        
        // Check for HTML content
        if (strpos($api_response, '<br />') !== false || strpos($api_response, '<html') !== false || strpos($api_response, '<!DOCTYPE') !== false) {
            $response['contains_html'] = true;
            $response['html_detected'] = true;
        }
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

// End output buffering and send response
ob_end_clean();
echo json_encode($response, JSON_PRETTY_PRINT); 