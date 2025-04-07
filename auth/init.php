<?php
/**
 * Authentication System Initialization
 * 
 * This file manages the loading order of authentication components
 * to prevent function redeclaration issues.
 */

// Set error reporting in development
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Track loaded files to prevent double-loading
$GLOBALS['_AUTH_LOADED_FILES'] = [];

/**
 * Helper function to include a file only once
 */
function auth_require_once($filepath) {
    if (!isset($GLOBALS['_AUTH_LOADED_FILES'][$filepath])) {
        $GLOBALS['_AUTH_LOADED_FILES'][$filepath] = true;
        require_once($filepath);
        return true;
    }
    return false;
}

// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constant to prevent direct access checks
defined('CHARTERHUB_LOADED') || define('CHARTERHUB_LOADED', true);

// Load all required auth files
auth_require_once(__DIR__ . '/../utils/database.php');
if (file_exists(__DIR__ . '/../utils/sanitize.php')) {
    auth_require_once(__DIR__ . '/../utils/sanitize.php');
} else {
    error_log("WARNING: sanitize.php not found. Some functionality may be limited.");
}
auth_require_once(__DIR__ . '/../db-config.php');
auth_require_once(__DIR__ . '/config.php');
auth_require_once(__DIR__ . '/cors.php');
auth_require_once(__DIR__ . '/jwt-core.php');
auth_require_once(__DIR__ . '/token-blacklist.php');

// Email system - check for both possible files
if (file_exists(__DIR__ . '/sendgrid-mailer.php')) {
    auth_require_once(__DIR__ . '/sendgrid-mailer.php');
} else if (file_exists(__DIR__ . '/email.php')) {
    auth_require_once(__DIR__ . '/email.php');
} else {
    error_log("WARNING: No email system file found. Email functionality will be disabled.");
}

// Function to initialize the auth system
function initialize_auth_system() {
    // Perform any necessary setup
    if (function_exists('apply_cors_headers')) {
        apply_cors_headers();
    }
    
    // Log initialization
    error_log("Authentication system initialized successfully");
}

// Initialize the system by default
initialize_auth_system(); 