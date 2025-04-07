<?php
/**
 * Debug Authentication Endpoint
 * 
 * This endpoint is for debugging auth token issues only.
 * It returns information about the request headers and token processing.
 * DO NOT USE IN PRODUCTION.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enable CORS for local development
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize response array
$response = [
    'success' => true,
    'message' => 'Auth debug information',
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'headers' => [],
    ],
    'auth' => [
        'token_received' => false,
        'token_valid' => false,
        'token_details' => null,
        'error' => null,
    ],
    'user' => null,
    'paths' => [
        'current_file' => __FILE__,
        'current_dir' => __DIR__,
    ],
    'error_messages' => [],
];

// Database connection settings (hardcoded for development only)
$db_host = 'localhost';
$db_name = 'charterhub_local';
$db_user = 'root';
$db_pass = '';
$conn = null;

// Try to connect to the database
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($conn->connect_error) {
        $response['database'] = [
            'connected' => false,
            'error' => $conn->connect_error,
        ];
        $response['error_messages'][] = "Database connection failed: " . $conn->connect_error;
    } else {
        $response['database'] = [
            'connected' => true,
            'server_info' => $conn->server_info,
        ];
    }
} catch (Exception $e) {
    $response['database'] = [
        'connected' => false,
        'error' => $e->getMessage(),
    ];
    $response['error_messages'][] = "Database exception: " . $e->getMessage();
}

// Get all request headers
$headers = getallheaders();
foreach ($headers as $name => $value) {
    // Redact sensitive information but keep the format
    if (strtolower($name) === 'authorization') {
        if (strpos($value, 'Bearer ') === 0) {
            $token = substr($value, 7);
            $response['request']['headers']['authorization'] = 'Bearer ' . substr($token, 0, 10) . '...[REDACTED]';
            $response['auth']['token_received'] = true;
            
            // Basic token validation (just structure check)
            $token_parts = explode('.', $token);
            if (count($token_parts) === 3) {
                try {
                    // Decode header and payload
                    $header = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[0])));
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $token_parts[1])));
                    
                    if ($payload) {
                        $response['auth']['token_valid'] = true;
                        $response['auth']['token_details'] = [
                            'alg' => $header->alg ?? 'unknown',
                            'typ' => $header->typ ?? 'unknown',
                            'user_id' => $payload->sub ?? null,
                            'email' => $payload->email ?? null,
                            'role' => $payload->role ?? null,
                            'exp' => $payload->exp ?? null,
                            'iat' => $payload->iat ?? null,
                            'expires_in' => isset($payload->exp) ? ($payload->exp - time()) : null,
                            'is_expired' => isset($payload->exp) ? ($payload->exp < time()) : null,
                        ];
                        
                        // If database is connected and token has user_id, try to get user
                        if ($conn && isset($payload->sub)) {
                            $user_id = $payload->sub;
                            $stmt = $conn->prepare("SELECT id, email, username, first_name, last_name, role FROM wp_charterhub_users WHERE id = ?");
                            
                            if ($stmt) {
                                $stmt->bind_param("i", $user_id);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                
                                if ($row = $result->fetch_assoc()) {
                                    $response['user'] = [
                                        'id' => $row['id'],
                                        'email' => $row['email'],
                                        'username' => $row['username'],
                                        'first_name' => $row['first_name'],
                                        'last_name' => $row['last_name'],
                                        'role' => $row['role'],
                                        'has_admin_role' => $row['role'] === 'admin',
                                    ];
                                } else {
                                    $response['auth']['error'] = 'User from token not found in database';
                                }
                                $stmt->close();
                            } else {
                                $response['auth']['error'] = 'Database query preparation failed: ' . $conn->error;
                            }
                        }
                    } else {
                        $response['auth']['error'] = 'Could not decode token payload';
                    }
                } catch (Exception $e) {
                    $response['auth']['error'] = 'Token parsing error: ' . $e->getMessage();
                }
            } else {
                $response['auth']['error'] = 'Invalid token format (expected 3 parts)';
            }
        } else {
            $response['request']['headers']['authorization'] = '[MALFORMED]';
            $response['auth']['error'] = 'Authorization header does not use Bearer format';
        }
    } else {
        $response['request']['headers'][strtolower($name)] = $value;
    }
}

// Check for JWT libraries
$response['system'] = [
    'php_version' => PHP_VERSION,
    'jwt_library' => class_exists('Firebase\JWT\JWT') ? 'Available' : 'Missing',
];

// Close database connection if open
if ($conn) {
    $conn->close();
}

// Return the debug information
echo json_encode($response, JSON_PRETTY_PRINT);
?> 