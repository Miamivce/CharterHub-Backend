<?php
/**
 * CharterHub Input Sanitization Functions
 * 
 * This file contains functions for sanitizing and validating user input
 * to prevent security vulnerabilities such as XSS and SQL injection.
 */

// Define a constant to prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

/**
 * Sanitize a string for output to prevent XSS
 * 
 * @param string $input The input string to sanitize
 * @return string The sanitized string
 */
function sanitize_output($input) {
    if (is_null($input)) {
        return '';
    }
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize an email address
 * 
 * @param string $email The email to sanitize
 * @return string The sanitized email
 */
function sanitize_email($email) {
    $email = trim($email);
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return $email;
}

/**
 * Sanitize a username/login
 * 
 * @param string $username The username to sanitize
 * @return string The sanitized username
 */
function sanitize_username($username) {
    $username = trim($username);
    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', $username);
    return $username;
}

/**
 * Sanitize text input
 * 
 * @param string $text The text to sanitize
 * @return string The sanitized text
 */
function sanitize_text($text) {
    $text = trim($text);
    $text = filter_var($text, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    return $text;
}

/**
 * Validate an email address
 * 
 * @param string $email The email to validate
 * @return bool Whether the email is valid
 */
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Sanitize an integer
 * 
 * @param mixed $input The input to sanitize
 * @return int The sanitized integer
 */
function sanitize_int($input) {
    return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
}

/**
 * Sanitize a float
 * 
 * @param mixed $input The input to sanitize
 * @return float The sanitized float
 */
function sanitize_float($input) {
    return (float) filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

/**
 * Sanitize a URL
 * 
 * @param string $url The URL to sanitize
 * @return string|false The sanitized URL or false if invalid
 */
function sanitize_url($url) {
    if (is_null($url)) {
        return false;
    }
    
    if (!is_string($url)) {
        $url = (string)$url;
    }
    
    // Sanitize URL
    $url = filter_var($url, FILTER_SANITIZE_URL);
    
    // Validate URL format
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    
    return false;
}

/**
 * Sanitize a date string in Y-m-d format
 * 
 * @param string $date The date string to sanitize
 * @return string|false The sanitized date in Y-m-d format or false if invalid
 */
function sanitize_date($date) {
    if (is_null($date)) {
        return false;
    }
    
    if (!is_string($date)) {
        $date = (string)$date;
    }
    
    // First sanitize as a general string
    $date = sanitize_string($date);
    
    // Try to create a DateTime object to validate
    try {
        $datetime = new DateTime($date);
        return $datetime->format('Y-m-d');
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Sanitize input for use in a SQL statement
 * This should be used as a last resort only; prefer prepared statements with placeholders
 * 
 * @param mixed $input The input to sanitize
 * @return string The sanitized input
 */
function sanitize_sql($input) {
    // If the input is null, return an empty string
    if (is_null($input)) {
        return '';
    }
    
    // Convert to string if not already a string
    if (!is_string($input)) {
        $input = (string)$input;
    }
    
    // Use escapeString from database.php which is PDO-compatible
    if (function_exists('escapeString')) {
        return escapeString($input);
    }
    
    // Fallback to basic escaping if escapeString is not available
    $search = array("\\", "\0", "\n", "\r", "\x1a", "'", "\"");
    $replace = array("\\\\", "\\0", "\\n", "\\r", "\Z", "\'", "\\\"");
    return str_replace($search, $replace, $input);
}

/**
 * Sanitize an array recursively
 * 
 * @param array $input The array to sanitize
 * @param string $type The type of sanitization to apply ('string', 'email', 'int', 'float', 'url', 'date')
 * @return array The sanitized array
 */
function sanitize_array($input, $type = 'string') {
    if (!is_array($input)) {
        return [];
    }
    
    $sanitized = [];
    
    foreach ($input as $key => $value) {
        // Recursively sanitize arrays
        if (is_array($value)) {
            $sanitized[$key] = sanitize_array($value, $type);
            continue;
        }
        
        // Apply the specified sanitization function
        switch ($type) {
            case 'email':
                $sanitized[$key] = sanitize_email($value);
                break;
            case 'int':
                $sanitized[$key] = sanitize_int($value);
                break;
            case 'float':
                $sanitized[$key] = sanitize_float($value);
                break;
            case 'url':
                $sanitized[$key] = sanitize_url($value);
                break;
            case 'date':
                $sanitized[$key] = sanitize_date($value);
                break;
            case 'string':
            default:
                $sanitized[$key] = sanitize_string($value);
                break;
        }
    }
    
    return $sanitized;
}

/**
 * Verify that a CSRF token is valid
 * 
 * @param string $token The CSRF token to verify
 * @param string $session_key The key to check in the session
 * @return bool Whether the token is valid
 */
function verify_csrf_token($token, $session_key = 'csrf_token') {
    if (!isset($_SESSION[$session_key]) || empty($token)) {
        return false;
    }
    
    // Use constant-time comparison to prevent timing attacks
    return hash_equals($_SESSION[$session_key], $token);
}

/**
 * Generate a new CSRF token and store it in the session
 * 
 * @param string $session_key The key to store the token under in the session
 * @return string The generated CSRF token
 */
function generate_csrf_token($session_key = 'csrf_token') {
    // Start session if not already started
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate a random token
    $token = bin2hex(random_bytes(32));
    
    // Store in session
    $_SESSION[$session_key] = $token;
    
    return $token;
}

/**
 * Sanitize and validate a phone number
 * 
 * @param string $phone The phone number to sanitize
 * @return string|false The sanitized phone number or false if invalid
 */
function sanitize_phone($phone) {
    if (is_null($phone)) {
        return false;
    }
    
    if (!is_string($phone)) {
        $phone = (string)$phone;
    }
    
    // Remove all non-numeric characters
    $sanitized = preg_replace('/[^0-9+\-\(\) ]/', '', $phone);
    
    // Basic validation - phone number should have at least 10 digits
    if (strlen(preg_replace('/[^0-9]/', '', $sanitized)) < 10) {
        return false;
    }
    
    return $sanitized;
} 