<?php
/**
 * Development Mode Email Verification Endpoint
 * This endpoint allows immediate verification of accounts without email in development mode
 */

// Ensure PHP errors don't output HTML - must be at the very top
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set CORS headers FIRST - before any potential errors can occur
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Define constants and include required files
define('CHARTERHUB_LOADED', true);
define('DEBUG_MODE', true);

// Define frontend URLs - this was missing and causing the error
$frontend_urls = [
    'login_url' => '/login',
    'register_url' => '/register',
    'dashboard_url' => '/dashboard'
];

// Global error handler to ensure JSON output even for PHP errors
function json_error_handler($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    
    error_log("PHP Error in {$file}:{$line} - {$message}");
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'A server error occurred',
        'debug' => DEBUG_MODE ? [
            'type' => 'php_error',
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line
        ] : null
    ]);
    exit;
}
set_error_handler('json_error_handler');

// Exception handler to ensure JSON output for exceptions
function json_exception_handler($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_exception',
        'message' => 'A server error occurred',
        'debug' => DEBUG_MODE ? [
            'type' => 'exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ] : null
    ]);
    exit;
}
set_exception_handler('json_exception_handler');

// Load configuration - wrapped in try/catch for safety
try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/../db-config.php';
} catch (Exception $e) {
    error_log("Failed to load configuration: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'config_error',
        'message' => 'Failed to load server configuration'
    ]);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON request data - wrapped in try/catch for safety
    try {
        $rawInput = file_get_contents('php://input');
        error_log("DEV-VERIFY-ACCOUNT.PHP: Raw input received: " . $rawInput);
        $input = json_decode($rawInput, true);
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("DEV-VERIFY-ACCOUNT.PHP: JSON decode error: " . json_last_error_msg());
        }
        error_log("DEV-VERIFY-ACCOUNT.PHP: Received verification request data: " . json_encode($input));
    } catch (Exception $e) {
        error_log("Failed to parse JSON input: " . $e->getMessage() . ", Raw input: " . substr($rawInput, 0, 100));
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'invalid_json',
            'message' => 'Could not parse JSON input'
        ]);
        exit;
    }
    
    // Check if client ID or email is provided
    $email = null;
    $clientId = null;
    
    if (isset($input['clientId']) && !empty($input['clientId'])) {
        $clientId = intval($input['clientId']);
        error_log("DEV-VERIFY-ACCOUNT.PHP: Found client ID in input: " . $clientId);
    } else if (isset($_POST['clientId']) && !empty($_POST['clientId'])) {
        $clientId = intval($_POST['clientId']);
        error_log("DEV-VERIFY-ACCOUNT.PHP: Found client ID in POST: " . $clientId);
    }
    
    if (isset($input['email']) && !empty($input['email'])) {
        $email = $input['email'];
        error_log("DEV-VERIFY-ACCOUNT.PHP: Found email in input: " . $email);
    } else if (isset($_POST['email']) && !empty($_POST['email'])) {
        $email = $_POST['email'];
        error_log("DEV-VERIFY-ACCOUNT.PHP: Found email in POST: " . $email);
    }
    
    // Prioritize client ID for identification if available
    if (!empty($clientId)) {
        error_log("DEV-VERIFY-ACCOUNT.PHP: Processing verification for client ID: " . $clientId);
    } else if (empty($email)) {
        error_log("DEV-VERIFY-ACCOUNT.PHP: Missing identification (clientId or email) in verification request");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'missing_identification',
            'message' => 'Client ID or email is required'
        ]);
        exit;
    } else {
        $email = strtolower(trim($email));
        error_log("DEV-VERIFY-ACCOUNT.PHP: Processing verification for email: " . $email);
    }
    
    // Get database connection - wrapped in try/catch for safety
    try {
        $pdo = get_db_connection();
        error_log("DEV-VERIFY-ACCOUNT.PHP: Database connection established");
    } catch (Exception $e) {
        error_log("Failed to connect to database: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'db_connection_error',
            'message' => 'Could not connect to database'
        ]);
        exit;
    }
    
    // Find user by client ID if provided, otherwise by email
    try {
        if (!empty($clientId)) {
            $table = $db_config['table_prefix'] . 'charterhub_users';
            error_log("DEV-VERIFY-ACCOUNT.PHP: Searching for user with client ID {$clientId} in table {$table}");
            
            $stmt = $pdo->prepare("
                SELECT * FROM {$db_config['table_prefix']}charterhub_users 
                WHERE id = ?
            ");
            $stmt->execute([$clientId]);
            error_log("DEV-VERIFY-ACCOUNT.PHP: Query executed for client ID: " . $clientId);
        } else {
            $table = $db_config['table_prefix'] . 'charterhub_users';
            error_log("DEV-VERIFY-ACCOUNT.PHP: Searching for user with email {$email} in table {$table}");
            
            $stmt = $pdo->prepare("
                SELECT * FROM {$db_config['table_prefix']}charterhub_users 
                WHERE LOWER(email) = LOWER(?)
            ");
            $stmt->execute([$email]);
            error_log("DEV-VERIFY-ACCOUNT.PHP: Query executed for email: " . $email);
        }
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            error_log("DEV-VERIFY-ACCOUNT.PHP: User found: " . json_encode($user));
        } else {
            error_log("DEV-VERIFY-ACCOUNT.PHP: No user found with the provided identification");
        }
    } catch (PDOException $e) {
        error_log("DEV-VERIFY-ACCOUNT.PHP: Database query error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'database_query_error',
            'message' => 'Error querying the database',
            'debug' => DEBUG_MODE ? [
                'error' => $e->getMessage(),
                'sql_state' => $e->getCode()
            ] : null
        ]);
        exit;
    }
    
    if (!$user) {
        if (!empty($clientId)) {
            error_log("DEV-VERIFY-ACCOUNT.PHP: No user found for client ID: " . $clientId);
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'user_not_found',
                'message' => "No account found with client ID: {$clientId}"
            ]);
        } else {
            error_log("DEV-VERIFY-ACCOUNT.PHP: No user found for email: " . $email);
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'user_not_found',
                'message' => "No account found with email: {$email}"
            ]);
        }
        exit;
    }
    
    if ($user['verified']) {
        error_log("DEV-VERIFY-ACCOUNT.PHP: User already verified: " . $email);
        echo json_encode([
            'success' => true,
            'message' => 'Account is already verified',
            'redirectUrl' => '/login',
            'email' => $user['email']
        ]);
        exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update user verification status
        $stmt = $pdo->prepare("
            UPDATE {$db_config['table_prefix']}charterhub_users 
            SET verified = 1, 
                verification_token = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        
        // Log verification
        $stmt = $pdo->prepare("
            INSERT INTO {$db_config['table_prefix']}charterhub_auth_logs 
            (user_id, action, status, ip_address, user_agent, details) 
            VALUES (?, 'dev_email_verification', 'success', ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? '::1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            json_encode([
                'email' => $user['email'],
                'verification_time' => date('Y-m-d H:i:s'),
                'dev_mode' => true
            ])
        ]);
        
        $pdo->commit();
        error_log("DEV-VERIFY-ACCOUNT.PHP: Successfully verified user: " . $user['email']);
        
        // Return success response with stored email
        echo json_encode([
            'success' => true,
            'message' => 'Account verified successfully',
            'redirectUrl' => '/login',
            'email' => $user['email']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("DEV-VERIFY-ACCOUNT.PHP: Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'database_error',
            'message' => DEBUG_MODE ? $e->getMessage() : 'A database error occurred during verification'
        ]);
    }
    
} catch (Exception $e) {
    error_log("DEV-VERIFY-ACCOUNT.PHP ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => DEBUG_MODE ? $e->getMessage() : 'An error occurred during verification'
    ]);
} 