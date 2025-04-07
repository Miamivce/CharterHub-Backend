<?php
/**
 * CSRF Token Endpoint
 * 
 * This file provides CSRF tokens for the frontend.
 * As we're transitioning to a JWT-only authentication system,
 * this endpoint currently returns a dummy token to maintain
 * compatibility with the frontend.
 */

// Define the CHARTERHUB_LOADED constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Include the CORS handler
require_once dirname(__FILE__) . '/global-cors.php';

// Apply CORS headers explicitly with allowed methods
apply_global_cors(['GET', 'OPTIONS']);

// Enable detailed logging for debugging
if (DEBUG_MODE) {
    error_log("CSRF-TOKEN.PHP: Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("CSRF-TOKEN.PHP: Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'not set'));
    error_log("CSRF-TOKEN.PHP: Generating CSRF token...");
}

/**
 * Get a CSRF token
 * 
 * In the new authentication system, we're using JWT tokens which
 * already protect against CSRF attacks, so we don't need a separate
 * CSRF token. However, for backward compatibility with the frontend,
 * this function returns a dummy token.
 * 
 * @return string A dummy CSRF token
 */
function get_csrf_token() {
    // Generate a simple dummy token
    // This will be removed once the frontend is updated to use JWT only
    return bin2hex(random_bytes(16));
}

// Generate token
$token = get_csrf_token();

// Use the standardized JSON response function
send_json_response([
    'success' => true,
    'csrf_token' => $token,
    'message' => 'CSRF token generated successfully. Note: We are transitioning to JWT authentication which does not require CSRF tokens.'
]);
