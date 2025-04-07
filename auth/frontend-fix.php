<?php
/**
 * CharterHub Frontend Authentication Fix Script
 * 
 * This script helps diagnose and fix frontend authentication issues by:
 * 1. Generating a valid JWT token for testing
 * 2. Providing code samples for implementing authentication in the frontend
 * 3. Checking that the necessary CORS headers are in place
 */

// Define CHARTERHUB_LOADED constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

// Include required files
require_once __DIR__ . '/config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set content type
header('Content-Type: application/json; charset=UTF-8');

// Initialize response
$response = [
    'success' => true,
    'message' => 'Frontend Authentication Fix Guide',
    'timestamp' => date('Y-m-d H:i:s'),
];

try {
    // Connect to the database
    $pdo = get_db_connection();
    
    // Check if the JWT tokens table exists
    $check_table = $pdo->query("SHOW TABLES LIKE '{$db_config['table_prefix']}jwt_tokens'");
    $jwt_table_exists = ($check_table && $check_table->rowCount() > 0);
    
    if (!$jwt_table_exists) {
        $response['error'] = 'JWT tokens table does not exist. Please run /create-jwt-table.php first.';
        $response['success'] = false;
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    // Generate an admin token for testing
    $admin_id = null;
    
    // Find an admin user
    $stmt = $pdo->prepare("
        SELECT u.ID, u.user_login, u.user_email 
        FROM {$db_config['table_prefix']}users u
        JOIN {$db_config['table_prefix']}usermeta m ON u.ID = m.user_id
        WHERE m.meta_key = '{$db_config['table_prefix']}capabilities'
        AND m.meta_value LIKE ?
        LIMIT 1
    ");
    $stmt->execute(['%administrator%']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        $admin_id = $admin['ID'];
        
        // Check if the user is verified
        $stmt = $pdo->prepare("SELECT verified FROM {$db_config['table_prefix']}users WHERE ID = ?");
        $stmt->execute([$admin_id]);
        $verified = $stmt->fetchColumn();
        
        if (!$verified) {
            // Update verification status
            $stmt = $pdo->prepare("UPDATE {$db_config['table_prefix']}users SET verified = 1 WHERE ID = ?");
            $stmt->execute([$admin_id]);
            $response['actions'][] = "Admin user {$admin['user_login']} (ID: {$admin_id}) has been verified";
        }
        
        // Generate test token
        $token_data = [
            'user_id' => $admin_id,
            'email' => $admin['user_email'],
            'role' => 'administrator'
        ];
        
        $token_url = "http://localhost:8000/generate-test-token.php?user_id={$admin_id}";
        $response['test_token_url'] = $token_url;
        $response['instructions'][] = "1. Get a test token by visiting: {$token_url}";
    } else {
        $response['warning'] = 'No administrator users found in the database';
    }
    
    // Frontend integration guide
    $response['frontend_integration'] = [
        'login_endpoint' => '/auth/login.php',
        'storage_key' => 'charterhub_auth_token',
        'token_format' => 'Bearer YOUR_TOKEN_HERE',
        'request_headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer YOUR_TOKEN_HERE'
        ],
        'login_code_sample' => "
// Login function
async function login(email, password) {
  try {
    const response = await fetch('/auth/login.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email, password }),
    });
    
    const data = await response.json();
    
    if (data.success && data.token) {
      // Store token in localStorage
      localStorage.setItem('charterhub_auth_token', data.token);
      return true;
    } else {
      console.error('Login failed:', data.message);
      return false;
    }
  } catch (error) {
    console.error('Login error:', error);
    return false;
  }
}",
        'authenticated_request_sample' => "
// Function to make authenticated requests
async function fetchWithAuth(url, options = {}) {
  // Get token from localStorage
  const token = localStorage.getItem('charterhub_auth_token');
  
  if (!token) {
    throw new Error('No authentication token found');
  }
  
  // Create headers with authorization
  const headers = {
    ...options.headers,
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  };
  
  // Make the request
  try {
    const response = await fetch(url, {
      ...options,
      headers,
    });
    
    // Handle 401 Unauthorized errors
    if (response.status === 401) {
      // Token might be expired, redirect to login or try refresh
      localStorage.removeItem('charterhub_auth_token');
      window.location.href = '/login';
      throw new Error('Authentication failed - redirecting to login');
    }
    
    return await response.json();
  } catch (error) {
    console.error('Request failed:', error);
    throw error;
  }
}

// Example usage
async function getCustomers() {
  try {
    const data = await fetchWithAuth('/customers/list.php');
    return data.customers || [];
  } catch (error) {
    console.error('Error fetching customers:', error);
    return [];
  }
}"
    ];
    
    // Common issues and fixes
    $response['common_issues'] = [
        [
            'problem' => '401 Unauthorized when accessing endpoints',
            'possible_causes' => [
                'JWT token missing from request',
                'Token is expired or invalid',
                'User is not verified in the database',
                'JWT tokens table does not exist',
                'Token formatting incorrect (missing "Bearer" prefix)'
            ],
            'solutions' => [
                'Check that the token is being stored correctly in localStorage',
                'Ensure the Authorization header is formatted as "Bearer YOUR_TOKEN"',
                'Verify the JWT tokens table exists by running /create-jwt-table.php',
                'Ensure all users are verified by running /verify-all-users.php?verify=all',
                'Check for CORS issues if requests are cross-origin'
            ]
        ],
        [
            'problem' => 'Client-side token storage issues',
            'possible_causes' => [
                'localStorage access blocked (private browsing)',
                'Token not being saved correctly',
                'Token being overwritten or cleared'
            ],
            'solutions' => [
                'Check browser compatibility with localStorage',
                'Verify the token is being saved with the correct key',
                'Add console.log statements to track token lifecycle'
            ]
        ],
        [
            'problem' => 'Cross-Origin Request issues',
            'possible_causes' => [
                'CORS headers missing',
                'Frontend running on different port than expected',
                'Preflight OPTIONS requests failing'
            ],
            'solutions' => [
                'Ensure CORS headers are set correctly on all authentication endpoints',
                'Update allowed origins to include all development ports (3000-3003)',
                'Check server logs for CORS errors'
            ]
        ]
    ];
    
    // Instructions for frontend testing
    $response['instructions'][] = "2. Copy the token value from the response";
    $response['instructions'][] = "3. In the browser console, run:";
    $response['instructions'][] = "   localStorage.setItem('charterhub_auth_token', 'YOUR_TOKEN_HERE')";
    $response['instructions'][] = "4. Refresh the page to use the new token";
    $response['instructions'][] = "5. If you're still having issues, check the Network tab for detailed error responses";
    
    // Info about frontend ports
    $response['frontend_ports'] = [
        'note' => 'The frontend may be running on different ports',
        'common_ports' => [
            'http://localhost:3000' => 'Default port',
            'http://localhost:3001' => 'Fallback port (if 3000 is already in use)'
        ],
        'current_cors_configuration' => get_allowed_origins()
    ];
    
    // Verification steps
    $response['verification_steps'] = [
        "1. Open browser console and check localStorage: console.log(localStorage.getItem('charterhub_auth_token'))",
        "2. Verify token has not expired by checking the 'exp' claim at https://jwt.io",
        "3. Check Network tab for requests to '/customers/list.php' and verify the Authorization header is present",
        "4. If using React tools, check the application state to ensure auth state is updated after login"
    ];
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    $response['error'] = $e->getMessage();
}

// Output the response as JSON
echo json_encode($response, JSON_PRETTY_PRINT); 