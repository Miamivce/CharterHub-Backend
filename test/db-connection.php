<?php
/**
 * Database Connection Test Script
 * 
 * This script tests the database connection and checks the structure of key tables.
 * It can be used to diagnose issues with database connectivity or schema mismatches.
 */

// Include necessary files
require_once dirname(__FILE__) . '/../auth/global-cors.php';
apply_global_cors(['GET']);

// Set JSON content type
header('Content-Type: application/json');

// Security check - simple token validation
$allowed_tokens = ['debug12345', 'charterhub_diag'];
$provided_token = isset($_GET['token']) ? $_GET['token'] : '';

if (!in_array($provided_token, $allowed_tokens)) {
    echo json_encode([
        'success' => false,
        'message' => 'Direct access not allowed',
        'error' => 'authentication_required',
        'help' => 'Add ?token=debug12345 to the URL to access this diagnostic tool'
    ]);
    exit;
}

// Enable error display for diagnostic purposes
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database utilities
require_once dirname(__FILE__) . '/../utils/database.php';

// Initialize result array
$result = [
    'success' => false,
    'message' => 'Initializing database test',
    'php_version' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => []
];

// Test database connection
try {
    $conn = get_database_connection();
    
    if (!$conn) {
        throw new Exception("Failed to get database connection");
    }
    
    $result['tests']['connection'] = [
        'success' => true,
        'message' => 'Database connection successful'
    ];
    
    // Test wp_charterhub_users table
    try {
        $query = "SHOW TABLES LIKE 'wp_charterhub_users'";
        $tables_result = $conn->query($query);
        
        if (!$tables_result) {
            throw new Exception("Error executing query: " . $conn->error);
        }
        
        $table_exists = ($tables_result->num_rows > 0);
        
        if (!$table_exists) {
            throw new Exception("Table not found");
        }
        
        $describe_query = "DESCRIBE wp_charterhub_users";
        $describe_result = $conn->query($describe_query);
        
        if (!$describe_result) {
            throw new Exception("Error describing table: " . $conn->error);
        }
        
        $columns = [];
        while ($row = $describe_result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        $result['tests']['users_table'] = [
            'success' => true,
            'message' => 'Users table exists and is accessible',
            'columns' => $columns
        ];
    } catch (Exception $e) {
        $result['tests']['users_table'] = [
            'success' => false,
            'message' => 'Error checking users table: ' . $e->getMessage()
        ];
    }
    
    // Test wp_charterhub_bookings table
    try {
        $query = "SHOW TABLES LIKE 'wp_charterhub_bookings'";
        $tables_result = $conn->query($query);
        
        if (!$tables_result) {
            throw new Exception("Error executing query: " . $conn->error);
        }
        
        $table_exists = ($tables_result->num_rows > 0);
        
        if (!$table_exists) {
            throw new Exception("Table not found");
        }
        
        $describe_query = "DESCRIBE wp_charterhub_bookings";
        $describe_result = $conn->query($describe_query);
        
        if (!$describe_result) {
            throw new Exception("Error describing table: " . $conn->error);
        }
        
        $columns = [];
        while ($row = $describe_result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        // Check for charterer column
        $charterer_column = null;
        if (in_array('main_charterer_id', $columns)) {
            $charterer_column = 'main_charterer_id';
        } elseif (in_array('customer_id', $columns)) {
            $charterer_column = 'customer_id';
        }
        
        $result['tests']['bookings_table'] = [
            'success' => true,
            'message' => 'Bookings table exists and is accessible',
            'columns' => $columns,
            'charterer_column' => $charterer_column
        ];
    } catch (Exception $e) {
        $result['tests']['bookings_table'] = [
            'success' => false,
            'message' => 'Error checking bookings table: ' . $e->getMessage()
        ];
    }
    
    // Test wp_charterhub_booking_guests table
    try {
        $query = "SHOW TABLES LIKE 'wp_charterhub_booking_guests'";
        $tables_result = $conn->query($query);
        
        if (!$tables_result) {
            throw new Exception("Error executing query: " . $conn->error);
        }
        
        $table_exists = ($tables_result->num_rows > 0);
        
        if (!$table_exists) {
            throw new Exception("Table not found");
        }
        
        $describe_query = "DESCRIBE wp_charterhub_booking_guests";
        $describe_result = $conn->query($describe_query);
        
        if (!$describe_result) {
            throw new Exception("Error describing table: " . $conn->error);
        }
        
        $columns = [];
        while ($row = $describe_result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        $result['tests']['booking_guests_table'] = [
            'success' => true,
            'message' => 'Booking guests table exists and is accessible',
            'columns' => $columns
        ];
    } catch (Exception $e) {
        $result['tests']['booking_guests_table'] = [
            'success' => false,
            'message' => 'Error checking booking guests table: ' . $e->getMessage()
        ];
    }
    
    // Close connection
    $conn->close();
    
    // Set overall success based on test results
    $all_tests_passed = true;
    foreach ($result['tests'] as $test) {
        if (!$test['success']) {
            $all_tests_passed = false;
            break;
        }
    }
    
    $result['success'] = $all_tests_passed;
    $result['message'] = $all_tests_passed ? 'All tests passed' : 'Some tests failed';
    
} catch (Exception $e) {
    $result['success'] = false;
    $result['message'] = 'Error: ' . $e->getMessage();
    $result['error_details'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
}

// Output result as JSON
echo json_encode($result, JSON_PRETTY_PRINT);
exit; 