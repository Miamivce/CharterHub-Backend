<?php
/**
 * Response Helper Functions
 * 
 * This file contains common functions for sending HTTP responses.
 */

// Send a success response
function success_response($data = [], $message = 'Success', $status_code = 200) {
    http_response_code($status_code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Send an error response - only define if not already defined in auth-functions.php
if (!function_exists('error_response')) {
    function error_response($message, $status_code = 400, $error_code = 'error') {
        http_response_code($status_code);
        echo json_encode([
            'success' => false,
            'error' => $error_code,
            'message' => $message
        ]);
        exit;
    }
}

// Send a validation error response
function validation_error($field, $message, $status_code = 400) {
    http_response_code($status_code);
    echo json_encode([
        'success' => false,
        'error' => 'validation_error',
        'field' => $field,
        'message' => $message
    ]);
    exit;
}

// Log debug information to error log (safer than exposing in response)
function log_debug_info($data, $prefix = 'DEBUG') {
    $debugInfo = is_array($data) || is_object($data) ? json_encode($data) : $data;
    error_log("[$prefix] " . $debugInfo);
}
?> 