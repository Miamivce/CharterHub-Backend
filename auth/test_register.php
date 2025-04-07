<?php
/**
 * Test script for register.php
 * 
 * This script simulates a POST request to register.php to test our changes.
 */

// Define CHARTERHUB_LOADED constant
define('CHARTERHUB_LOADED', true);

// Include configuration
require_once __DIR__ . '/config.php';

// Create test data
$test_data = [
    'email' => 'test_register_script_' . time() . '@example.com',
    'password' => 'password123',
    'firstName' => 'TestRegister',
    'lastName' => 'Script',
    'phoneNumber' => '123-456-7890',
    'company' => 'Test Company'
];

echo "Testing registration with data:\n";
echo json_encode($test_data, JSON_PRETTY_PRINT) . "\n\n";

// Simulate POST request to register.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Test Script';

// Capture output
ob_start();

// Include register.php
$input = json_encode($test_data);
$GLOBALS['HTTP_RAW_POST_DATA'] = $input;

// Override file_get_contents to return our test data
function file_get_contents($input) {
    if ($input === 'php://input') {
        return $GLOBALS['HTTP_RAW_POST_DATA'];
    }
    return \file_get_contents($input);
}

// Override send_json_response to capture the response
function send_json_response($data, $status = 200) {
    echo "Response (Status: $status):\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    exit;
}

// Include register.php
require_once __DIR__ . '/register.php';

// Get output
$output = ob_get_clean();
echo $output; 