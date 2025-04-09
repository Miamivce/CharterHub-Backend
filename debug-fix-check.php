<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Include the database connection code
require_once __DIR__ . '/library/db.php';

// Include JWT core to check for the function
require_once __DIR__ . '/auth/jwt-core.php';

// Output array
$results = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => []
];

// Connect to the database
try {
    $db = connectToDatabase();
    $results['checks']['database_connection'] = [
        'status' => 'success',
        'message' => 'Connected to database successfully'
    ];
} catch (Exception $e) {
    $results['checks']['database_connection'] = [
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ];
    $results['success'] = false;
}

// Check if refresh_tokens table exists
if ($db) {
    try {
        $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
        $table = $tablePrefix . 'charterhub_refresh_tokens';
        
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $tableExists = $stmt->rowCount() > 0;
        
        $results['checks']['refresh_tokens_table'] = [
            'status' => $tableExists ? 'success' : 'error',
            'message' => $tableExists ? 'Refresh tokens table exists' : 'Refresh tokens table does not exist'
        ];
        
        if (!$tableExists) {
            $results['success'] = false;
        } else {
            // Check table structure
            $stmt = $db->query("DESCRIBE $table");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $results['checks']['refresh_tokens_table']['columns'] = $columns;
        }
    } catch (Exception $e) {
        $results['checks']['refresh_tokens_table'] = [
            'status' => 'error',
            'message' => 'Could not check refresh tokens table: ' . $e->getMessage()
        ];
        $results['success'] = false;
    }
}

// Check if generate_jwt function exists
$results['checks']['generate_jwt_function'] = [
    'status' => function_exists('generate_jwt') ? 'success' : 'error',
    'message' => function_exists('generate_jwt') ? 'generate_jwt function exists' : 'generate_jwt function does not exist'
];

if (!function_exists('generate_jwt')) {
    $results['success'] = false;
    
    // Try to read the jwt-core.php file to check for the alias
    try {
        $jwtCorePath = __DIR__ . '/auth/jwt-core.php';
        if (file_exists($jwtCorePath)) {
            $jwtCoreContent = file_get_contents($jwtCorePath);
            $results['checks']['jwt_core_content'] = [
                'status' => 'info',
                'contains_alias' => strpos($jwtCoreContent, 'function generate_jwt') !== false,
                'contains_access_token_fn' => strpos($jwtCoreContent, 'function generate_access_token') !== false
            ];
        } else {
            $results['checks']['jwt_core_content'] = [
                'status' => 'error',
                'message' => 'jwt-core.php file not found'
            ];
        }
    } catch (Exception $e) {
        $results['checks']['jwt_core_content'] = [
            'status' => 'error',
            'message' => 'Could not read jwt-core.php: ' . $e->getMessage()
        ];
    }
}

// Check for last_login column in users table
if ($db) {
    try {
        $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
        $table = $tablePrefix . 'charterhub_users';
        
        $stmt = $db->query("SHOW COLUMNS FROM $table LIKE 'last_login'");
        $columnExists = $stmt->rowCount() > 0;
        
        $results['checks']['last_login_column'] = [
            'status' => $columnExists ? 'success' : 'error',
            'message' => $columnExists ? 'last_login column exists in users table' : 'last_login column does not exist in users table'
        ];
        
        if (!$columnExists) {
            $results['success'] = false;
        }
    } catch (Exception $e) {
        $results['checks']['last_login_column'] = [
            'status' => 'error',
            'message' => 'Could not check last_login column: ' . $e->getMessage()
        ];
        $results['success'] = false;
    }
}

// Check auth_logs table columns
if ($db) {
    try {
        $tablePrefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
        $table = $tablePrefix . 'charterhub_auth_logs';
        
        // Check if table exists
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $tableExists = $stmt->rowCount() > 0;
        
        if ($tableExists) {
            // Check action column type
            $stmt = $db->query("SHOW COLUMNS FROM $table LIKE 'action'");
            $actionCol = $stmt->fetch(PDO::FETCH_ASSOC);
            $actionType = $actionCol ? $actionCol['Type'] : null;
            
            // Check status column type
            $stmt = $db->query("SHOW COLUMNS FROM $table LIKE 'status'");
            $statusCol = $stmt->fetch(PDO::FETCH_ASSOC);
            $statusType = $statusCol ? $statusCol['Type'] : null;
            
            // Check if id column has AUTO_INCREMENT
            $stmt = $db->query("SHOW COLUMNS FROM $table LIKE 'id'");
            $idCol = $stmt->fetch(PDO::FETCH_ASSOC);
            $hasAutoIncrement = $idCol && strpos($idCol['Extra'], 'auto_increment') !== false;
            
            $results['checks']['auth_logs_table'] = [
                'status' => 'success',
                'exists' => true,
                'action_column_type' => $actionType,
                'status_column_type' => $statusType,
                'id_has_auto_increment' => $hasAutoIncrement,
                'all_fixes_applied' => (
                    (strtolower($actionType) == 'text' || strtolower($actionType) == 'mediumtext' || strtolower($actionType) == 'longtext') &&
                    (strtolower($statusType) == 'text' || strtolower($statusType) == 'mediumtext' || strtolower($statusType) == 'longtext') &&
                    $hasAutoIncrement
                )
            ];
            
            if (!$results['checks']['auth_logs_table']['all_fixes_applied']) {
                $results['success'] = false;
            }
        } else {
            $results['checks']['auth_logs_table'] = [
                'status' => 'error',
                'message' => 'auth_logs table does not exist'
            ];
            $results['success'] = false;
        }
    } catch (Exception $e) {
        $results['checks']['auth_logs_table'] = [
            'status' => 'error',
            'message' => 'Could not check auth_logs table: ' . $e->getMessage()
        ];
        $results['success'] = false;
    }
}

// Output the results
echo json_encode($results, JSON_PRETTY_PRINT); 