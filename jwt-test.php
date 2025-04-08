<?php
/**
 * CharterHub JWT Configuration Test
 * 
 * This script tests JWT token generation and verification
 * to help diagnose authentication issues.
 */

// Display errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>CharterHub JWT Configuration Test</h1>";

// Load environment variables from .env file if exists
if (file_exists(__DIR__ . '/.env')) {
    echo "<p>Loading configuration from .env file</p>";
    $env_lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Get JWT configuration from environment
$jwt_config = [
    'secret' => getenv('JWT_SECRET') ?: '91e8d3d71b31a52fad70af8c55916d18',
    'refresh_secret' => getenv('JWT_REFRESH_SECRET') ?: 'ec112e1b828e96caf5cbb459428a63e8',
    'expiry' => getenv('JWT_EXPIRY') ?: 3600,
    'refresh_expiry' => getenv('JWT_REFRESH_EXPIRY') ?: 604800,
];

// Show JWT configuration (with partial secret display)
echo "<h2>JWT Configuration</h2>";
echo "<ul>";
echo "<li>Secret: " . substr($jwt_config['secret'], 0, 3) . "..." . substr($jwt_config['secret'], -3) . " (" . strlen($jwt_config['secret']) . " chars)</li>";
echo "<li>Refresh Secret: " . substr($jwt_config['refresh_secret'], 0, 3) . "..." . substr($jwt_config['refresh_secret'], -3) . " (" . strlen($jwt_config['refresh_secret']) . " chars)</li>";
echo "<li>Expiry: " . $jwt_config['expiry'] . " seconds (" . round($jwt_config['expiry']/60, 1) . " minutes)</li>";
echo "<li>Refresh Expiry: " . $jwt_config['refresh_expiry'] . " seconds (" . round($jwt_config['refresh_expiry']/3600/24, 1) . " days)</li>";
echo "</ul>";

// Check if Firebase JWT library is available
$jwt_library_available = false;
$composer_autoload = __DIR__ . '/vendor/autoload.php';

if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
    $jwt_library_available = class_exists('Firebase\JWT\JWT');
    echo "<p>Firebase JWT Library: " . ($jwt_library_available ? "✅ Available" : "❌ Not Available") . "</p>";
} else {
    echo "<p>❌ Composer autoload file not found at: $composer_autoload</p>";
}

// Include the auth files if library is available
if ($jwt_library_available) {
    // Define the constant to allow included files to run
    if (!defined('CHARTERHUB_LOADED')) {
        define('CHARTERHUB_LOADED', true);
    }
    
    // Try to include the JWT core file
    $jwt_core_file = __DIR__ . '/auth/jwt-core.php';
    if (file_exists($jwt_core_file)) {
        echo "<p>Loading JWT core file: $jwt_core_file</p>";
        require_once $jwt_core_file;
        
        // Test token generation function
        echo "<h2>JWT Token Generation Test</h2>";
        if (function_exists('generate_access_token')) {
            try {
                $test_token = generate_access_token(99999, 'test@example.com', 'client', 1);
                if ($test_token) {
                    echo "<p style='color:green'>✅ Successfully generated test token!</p>";
                    echo "<p>Token: <small>" . substr($test_token, 0, 20) . "...</small></p>";
                    
                    // Try to decode and verify the token
                    echo "<h3>Token Verification Test</h3>";
                    if (function_exists('validate_token')) {
                        $validation = validate_token($test_token);
                        if (isset($validation['error']) && $validation['error'] === true) {
                            echo "<p style='color:red'>❌ Token validation failed: " . $validation['message'] . "</p>";
                        } else {
                            echo "<p style='color:green'>✅ Token validation successful!</p>";
                            echo "<p>Payload contents:</p>";
                            echo "<pre>" . print_r($validation, true) . "</pre>";
                        }
                    } else {
                        echo "<p style='color:red'>❌ validate_token function not available to test verification</p>";
                    }
                } else {
                    echo "<p style='color:red'>❌ Failed to generate test token</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color:red'>❌ Exception during token generation: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color:red'>❌ generate_access_token function not available</p>";
        }
    } else {
        echo "<p style='color:red'>❌ JWT core file not found at: $jwt_core_file</p>";
    }
} else {
    echo "<h2>JWT Tests Skipped</h2>";
    echo "<p>Firebase JWT Library not available. Install with: <code>composer require firebase/php-jwt</code></p>";
}

// Manual JWT test (minimal implementation)
echo "<h2>Basic JWT Test (Without Dependencies)</h2>";

// Function to encode a base64Url string
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Create a simple manual JWT token
function create_manual_jwt($payload, $secret) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    
    $encodedHeader = base64UrlEncode(json_encode($header));
    $encodedPayload = base64UrlEncode(json_encode($payload));
    
    $signature = hash_hmac('sha256', "$encodedHeader.$encodedPayload", $secret, true);
    $encodedSignature = base64UrlEncode($signature);
    
    return "$encodedHeader.$encodedPayload.$encodedSignature";
}

// Try to create a simple token
$manual_payload = [
    'sub' => 12345,
    'name' => 'Test User',
    'iat' => time(),
    'exp' => time() + 3600
];

$manual_token = create_manual_jwt($manual_payload, $jwt_config['secret']);
echo "<p>Manual Token: <small>$manual_token</small></p>";

// Test the CORS configuration
echo "<h2>CORS Configuration Test</h2>";

$cors_origins = getenv('CORS_ALLOW_ORIGINS') ?: '';
$cors_origins_array = !empty($cors_origins) ? explode(',', $cors_origins) : [];

echo "<p>CORS Allowed Origins from .env:</p>";
if (count($cors_origins_array) > 0) {
    echo "<ul>";
    foreach ($cors_origins_array as $origin) {
        echo "<li>" . htmlspecialchars($origin) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:orange'>⚠️ No CORS origins found with CORS_ALLOW_ORIGINS. Checking CORS_ALLOWED_ORIGINS...</p>";
    
    $alt_origins = getenv('CORS_ALLOWED_ORIGINS') ?: '';
    $alt_origins_array = !empty($alt_origins) ? explode(',', $alt_origins) : [];
    
    if (count($alt_origins_array) > 0) {
        echo "<ul>";
        foreach ($alt_origins_array as $origin) {
            echo "<li>" . htmlspecialchars($origin) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color:red'>❌ No CORS origins found in .env configuration!</p>";
    }
}

// Check frontend production URLs
$production_urls = [
    'https://app.yachtstory.com',
    'https://admin.yachtstory.com',
    'https://app.yachtstory.be',
    'https://admin.yachtstory.be',
    'https://charter-hub.vercel.app'
];

echo "<p>Checking if production URLs are in allowed origins:</p>";
echo "<ul>";
$all_origins = array_merge($cors_origins_array, $alt_origins_array ?? []);
foreach ($production_urls as $url) {
    $is_allowed = in_array($url, $all_origins);
    echo "<li>" . $url . ": " . ($is_allowed ? "✅ Allowed" : "❌ Not Allowed") . "</li>";
}
echo "</ul>";

// Summary
echo "<h2>API Connection Summary</h2>";
echo "<p>Based on the tests above:</p>";
echo "<ol>";
echo "<li>If the database connection test passed, the backend can connect to the database properly.</li>";
echo "<li>If the JWT tests passed, the authentication system should be functional.</li>";
echo "<li>If the CORS configuration includes all production URLs, cross-origin requests should work.</li>";
echo "</ol>";

echo "<p>If all tests passed but you're still having issues:</p>";
echo "<ol>";
echo "<li>Check that the frontend is sending requests to the correct backend URL</li>";
echo "<li>Verify JWT secrets match between environments</li>";
echo "<li>Check browser console for any CORS or network errors</li>";
echo "<li>Try clearing browser cookies and cache</li>";
echo "</ol>";
?> 