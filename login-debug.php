<?php
/**
 * CharterHub Login Diagnostics Tool
 * 
 * This file helps diagnose issues with the login process
 * by testing each component separately.
 */

// Enable error display for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Define a constant to prevent direct access to included files
define('CHARTERHUB_LOADED', true);

header('Content-Type: application/json');

// Track progress and errors
$results = [
    'status' => 'running',
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => [],
    'overall_result' => null
];

function addTestResult($name, $status, $message = null, $details = null) {
    global $results;
    $results['tests'][$name] = [
        'status' => $status,
        'message' => $message,
        'details' => $details
    ];
    
    // Output in real-time for easier debugging
    echo "TEST [{$name}]: {$status}" . ($message ? " - {$message}" : "") . "\n";
    flush();
}

try {
    // Test 1: Basic PHP functioning
    addTestResult('php_basic', 'success', 'PHP is working');
    
    // Test 2: Include database utilities
    try {
        require_once __DIR__ . '/utils/database.php';
        addTestResult('include_database', 'success', 'Database utilities loaded');
    } catch (Exception $e) {
        addTestResult('include_database', 'error', 'Failed to load database utilities', $e->getMessage());
        throw $e;
    }
    
    // Test 3: Include authentication config
    try {
        require_once __DIR__ . '/auth/config.php';
        addTestResult('include_config', 'success', 'Config file loaded');
    } catch (Exception $e) {
        addTestResult('include_config', 'error', 'Failed to load config file', $e->getMessage());
        throw $e;
    }
    
    // Test 4: Check database configuration
    try {
        global $db_config;
        if (!isset($db_config) || !is_array($db_config)) {
            throw new Exception('Database configuration not found or invalid');
        }
        
        // Don't include sensitive info in the output
        $safe_config = $db_config;
        if (isset($safe_config['password'])) {
            $safe_config['password'] = '******';
        }
        
        addTestResult('db_config', 'success', 'Database configuration found', $safe_config);
    } catch (Exception $e) {
        addTestResult('db_config', 'error', 'Database configuration issue', $e->getMessage());
        throw $e;
    }
    
    // Test 5: Database connection
    try {
        $pdo = get_db_connection_from_config();
        addTestResult('db_connection', 'success', 'Database connection established');
        
        // Test a simple query
        $stmt = $pdo->query('SELECT 1 as test');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && isset($result['test']) && $result['test'] == 1) {
            addTestResult('db_query', 'success', 'Database query successful');
        } else {
            throw new Exception('Failed to execute test query');
        }
    } catch (Exception $e) {
        addTestResult('db_connection', 'error', 'Database connection failed', $e->getMessage());
        throw $e;
    }
    
    // Test 6: Include JWT core file
    try {
        require_once __DIR__ . '/auth/jwt-core.php';
        addTestResult('include_jwt', 'success', 'JWT core file loaded');
    } catch (Exception $e) {
        addTestResult('include_jwt', 'error', 'Failed to load JWT core file', $e->getMessage());
        throw $e;
    }
    
    // Test 7: Check if Firebase JWT classes exist
    try {
        if (!class_exists('\\Firebase\\JWT\\JWT')) {
            throw new Exception('Firebase JWT class not found');
        }
        addTestResult('firebase_jwt', 'success', 'Firebase JWT library loaded');
    } catch (Exception $e) {
        addTestResult('firebase_jwt', 'error', 'Firebase JWT library issue', $e->getMessage());
        throw $e;
    }
    
    // Test 8: Try to generate a token
    try {
        $test_token = generate_access_token(
            999, // Test user ID
            'test@example.com', // Test email
            'client', // Test role
            1 // Test token version
        );
        
        if (!$test_token) {
            throw new Exception('Failed to generate access token');
        }
        
        addTestResult('token_generation', 'success', 'Token generation successful');
    } catch (Exception $e) {
        addTestResult('token_generation', 'error', 'Token generation failed', $e->getMessage());
        throw $e;
    }
    
    // Test 9: Check blacklist functionality
    try {
        require_once __DIR__ . '/auth/token-blacklist.php';
        if (!function_exists('is_token_blacklisted')) {
            throw new Exception('Blacklist function not found');
        }
        addTestResult('blacklist', 'success', 'Token blacklist functionality loaded');
    } catch (Exception $e) {
        addTestResult('blacklist', 'error', 'Token blacklist issue', $e->getMessage());
        throw $e;
    }
    
    // Test 10: Check client-login.php exists and is readable
    try {
        $loginFile = __DIR__ . '/auth/client-login.php';
        if (!file_exists($loginFile)) {
            throw new Exception('client-login.php file not found');
        }
        if (!is_readable($loginFile)) {
            throw new Exception('client-login.php file not readable');
        }
        addTestResult('login_file', 'success', 'Client login file exists and is readable');
    } catch (Exception $e) {
        addTestResult('login_file', 'error', 'Client login file issue', $e->getMessage());
        throw $e;
    }
    
    // All tests passed!
    $results['status'] = 'completed';
    $results['overall_result'] = 'success';
    $results['message'] = 'All diagnostic tests passed successfully';
    
} catch (Exception $e) {
    // Handle any uncaught exceptions
    $results['status'] = 'completed';
    $results['overall_result'] = 'error';
    $results['message'] = 'Diagnostic tests failed: ' . $e->getMessage();
}

// Output full results as JSON
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
?>
