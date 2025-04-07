<?php
/**
 * CharterHub Authentication Status Endpoint
 * 
 * This endpoint provides a comprehensive overview of the authentication system,
 * including configuration status, token validation, and user verification status.
 * It can be used to diagnose authentication issues in the system.
 */

// Define CHARTERHUB_LOADED constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Include required files
require_once __DIR__ . '/config.php';

// Function to get allowed origins if not already defined
if (!function_exists('get_allowed_origins')) {
    function get_allowed_origins() {
        global $auth_config;
        return [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://localhost:3002',
            'http://localhost:3003'
        ];
    }
}

// Set JSON content type header
header('Content-Type: application/json; charset=UTF-8');

// Set cache control headers to prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Handle OPTIONS requests for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    set_cors_headers(['GET', 'OPTIONS']);
    exit;
}

// Set CORS headers
set_cors_headers(['GET', 'OPTIONS']);

// Initialize response array
$response = [
    'success' => true,
    'message' => 'CharterHub PHP Authentication API is running',
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => [
        'dev_mode' => defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE === true,
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    ],
    'auth_config' => []
];

// Basic auth configuration check (without exposing secrets)
$response['auth_config'] = [
    'jwt_configured' => isset($auth_config['jwt_secret']) && !empty($auth_config['jwt_secret']),
    'jwt_expiration' => $auth_config['jwt_expiration'] ?? 'Not set',
    'refresh_expiration' => $auth_config['refresh_expiration'] ?? 'Not set',
    'allowed_origins' => get_allowed_origins(),
    'database_configured' => isset($db_config) && !empty($db_config)
];

// Check token if provided
$token_status = [
    'present' => false,
    'valid' => false,
    'user_verified' => false,
    'expired' => false,
    'role' => null,
    'payload' => null,
    'error' => null
];

// Get auth header
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : null;

if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $jwt = $matches[1];
    $token_status['present'] = true;
    $token_status['token'] = substr($jwt, 0, 20) . '...'; // Only show beginning of token for security
    
    try {
        // Try to verify the token
        $payload = verify_jwt_token($jwt, true); // Allow expired for diagnostics
        
        if ($payload) {
            $token_status['valid'] = true;
            $token_status['role'] = $payload->role ?? 'No role in token';
            $token_status['sub'] = $payload->sub ?? 'No subject in token';
            $token_status['expired'] = time() > ($payload->exp ?? 0);
            
            // Remove sensitive data before adding to response
            $filtered_payload = (array)$payload;
            unset($filtered_payload['sub']);
            unset($filtered_payload['jti']);
            $token_status['payload'] = $filtered_payload;
            
            // Check user verification status
            try {
                $pdo = get_db_connection();
                $check_column = $pdo->query("SHOW COLUMNS FROM {$db_config['table_prefix']}users LIKE 'verified'");
                $verified_column_exists = ($check_column && $check_column->rowCount() > 0);
                
                if ($verified_column_exists) {
                    $stmt = $pdo->prepare("SELECT verified FROM {$db_config['table_prefix']}users WHERE ID = ?");
                    $stmt->execute([$payload->sub ?? 0]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result) {
                        $token_status['user_verified'] = (bool)$result['verified'];
                    }
                } else {
                    $token_status['user_verified'] = "Column 'verified' does not exist in users table";
                }
                
                // Check for JWT tokens table
                $check_table = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}jwt_tokens'");
                $token_status['jwt_tokens_table_exists'] = ($check_table && $check_table->rowCount() > 0);
                
            } catch (Exception $e) {
                $token_status['db_error'] = $e->getMessage();
            }
        }
    } catch (Exception $e) {
        $token_status['error'] = $e->getMessage();
    }
}

$response['token_status'] = $token_status;

// Check authentication endpoints
$endpoints = [
    '/auth/me.php' => 'User profile endpoint (requires valid token)',
    '/auth/login.php' => 'Login endpoint (generates JWT token)',
    '/auth/refresh.php' => 'Token refresh endpoint (extends JWT session)',
    '/auth/csrf-token.php' => 'CSRF token endpoint (for form submissions)',
    '/customers/list.php' => 'Customer list endpoint (requires admin or valid token)',
    '/verify-user.php' => 'User verification script',
    '/generate-test-token.php' => 'Test token generator',
    '/test-auth.php' => 'Basic auth test endpoint',
];

$endpoint_status = [];
foreach ($endpoints as $endpoint => $description) {
    $url = $auth_config['api_base_url'] ?? 'http://localhost:8000';
    $endpoint_status[$endpoint] = [
        'description' => $description,
        'url' => $url . $endpoint,
        'exists' => file_exists(dirname(__DIR__) . $endpoint)
    ];
}

$response['endpoints'] = $endpoint_status;

// Add database check
try {
    $pdo = get_db_connection();
    
    // Check user table
    $stmt = $pdo->query("SHOW COLUMNS FROM {$db_config['table_prefix']}users");
    $user_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Check for specific columns
    $required_columns = ['ID', 'user_login', 'user_pass', 'user_email', 'user_registered'];
    $auth_columns = ['verified', 'refresh_token'];
    
    $response['database'] = [
        'connected' => true,
        'prefix' => $db_config['table_prefix'],
        'required_columns_present' => count(array_intersect($required_columns, $user_columns)) === count($required_columns),
        'auth_columns_present' => [
            'verified' => in_array('verified', $user_columns),
            'refresh_token' => in_array('refresh_token', $user_columns)
        ],
        'jwt_tokens_table' => false
    ];
    
    // Check jwt_tokens table
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}jwt_tokens'");
        $jwt_table_exists = ($check_table && $check_table->rowCount() > 0);
        
        if ($jwt_table_exists) {
            $stmt = $pdo->query("SHOW COLUMNS FROM {$db_config['table_prefix']}jwt_tokens");
            $jwt_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $response['database']['jwt_tokens_table'] = [
                'exists' => true,
                'columns' => $jwt_columns
            ];
        } else {
            $response['database']['jwt_tokens_table'] = [
                'exists' => false,
                'create_sql' => "CREATE TABLE `{$db_config['table_prefix']}jwt_tokens` (
                    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` bigint(20) UNSIGNED NOT NULL,
                    `token_hash` varchar(255) NOT NULL,
                    `refresh_token_hash` varchar(255) DEFAULT NULL,
                    `expires_at` datetime NOT NULL,
                    `refresh_expires_at` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `revoked` tinyint(1) NOT NULL DEFAULT '0',
                    `last_used_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `user_id` (`user_id`),
                    KEY `token_hash` (`token_hash`),
                    KEY `refresh_token_hash` (`refresh_token_hash`)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
            ];
        }
    } catch (Exception $e) {
        $response['database']['jwt_tokens_table'] = [
            'exists' => false,
            'error' => $e->getMessage()
        ];
    }
    
    // Check if any admin users are verified
    if (in_array('verified', $user_columns)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as admin_count
            FROM {$db_config['table_prefix']}users u
            JOIN {$db_config['table_prefix']}usermeta m ON u.ID = m.user_id
            WHERE m.meta_key = '{$db_config['table_prefix']}capabilities'
            AND m.meta_value LIKE '%administrator%'
            AND u.verified = 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $response['database']['verified_admins'] = $result['admin_count'] ?? 0;
    }
    
} catch (Exception $e) {
    $response['database'] = [
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

// Include authentication flow information
$response['auth_flow'] = [
    'client_flow' => [
        '1. Login Request' => 'POST to /auth/login.php with credentials',
        '2. Token Generation' => 'Server generates JWT & refresh tokens',
        '3. User Access' => 'Client includes JWT in Authorization header',
        '4. Token Refresh' => 'POST to /auth/refresh.php with refresh token when JWT expires',
        '5. Verification' => 'User must have verified=1 in database'
    ],
    'admin_flow' => [
        '1. WordPress Login' => 'Admin logs in through WordPress admin',
        '2. Cookie Auth' => 'WordPress sets authentication cookies',
        '3. Dual Auth' => 'Admin endpoints check for either JWT or WordPress cookie',
        '4. Role Check' => 'Endpoints verify user has administrator role',
    ],
    'common_issues' => [
        'Token Format' => 'JWT must be in correct format (header.payload.signature)',
        'User Verification' => 'Users must have verified=1 in database',
        'CORS Configuration' => 'Origin headers must match allowed origins',
        'Database Structure' => 'Required tables and columns must exist',
        'Token Expiration' => 'Expired tokens must be refreshed'
    ]
];

// For admin requests, provide detailed troubleshooting steps
if ($token_status['present'] && $token_status['role'] === 'administrator') {
    $response['admin_troubleshooting'] = [
        'verify_user_script' => 'Run /verify-user.php to ensure user is verified',
        'test_token' => 'Generate a test token with /generate-test-token.php?user_id=' . ($token_status['sub'] ?? '1'),
        'check_wordpress_auth' => 'Ensure WordPress cookies are valid and not expired',
        'check_database' => 'Verify the user has administrator capabilities in wp_usermeta'
    ];
}

// Output the response as JSON
echo json_encode($response, JSON_PRETTY_PRINT); 