<?php
/**
 * CharterHub Users Table Verification
 * Verifies the data in wp_charterhub_users table and its relationships
 */

define('CHARTERHUB_LOADED', true);
require_once __DIR__ . '/config.php';

// Set JSON content type header
header('Content-Type: application/json; charset=UTF-8');

$response = [
    'success' => true,
    'message' => 'CharterHub users table verification',
    'timestamp' => date('Y-m-d H:i:s'),
    'table_info' => [],
    'data' => [],
    'relationships' => []
];

try {
    $pdo = get_db_connection();
    
    // 1. Check table structure
    $columns_sql = "SHOW COLUMNS FROM {$db_config['table_prefix']}charterhub_users";
    $stmt = $pdo->query($columns_sql);
    $response['table_info']['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Get all charter clients with their WordPress user data
    $clients_sql = "
        SELECT 
            c.*,
            u.user_email,
            u.user_registered,
            u.role as wp_role,
            u.verified as wp_verified,
            u.first_name,
            u.last_name,
            u.company
        FROM {$db_config['table_prefix']}charterhub_users c
        JOIN {$db_config['table_prefix']}users u ON c.wp_user_id = u.ID
        WHERE c.role = 'charter_client'
        ORDER BY c.id ASC
    ";
    
    $stmt = $pdo->query($clients_sql);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['data']['clients'] = $clients;
    
    // 3. Check for any orphaned records (charterhub users without wp users)
    $orphaned_sql = "
        SELECT c.*
        FROM {$db_config['table_prefix']}charterhub_users c
        LEFT JOIN {$db_config['table_prefix']}users u ON c.wp_user_id = u.ID
        WHERE u.ID IS NULL
    ";
    
    $stmt = $pdo->query($orphaned_sql);
    $orphaned = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['relationships']['orphaned_records'] = $orphaned;
    
    // 4. Check for any inconsistencies in verification status
    $verification_sql = "
        SELECT 
            c.id,
            c.wp_user_id,
            c.verified as charterhub_verified,
            u.verified as wp_verified
        FROM {$db_config['table_prefix']}charterhub_users c
        JOIN {$db_config['table_prefix']}users u ON c.wp_user_id = u.ID
        WHERE c.verified != u.verified
    ";
    
    $stmt = $pdo->query($verification_sql);
    $verification_mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['relationships']['verification_mismatches'] = $verification_mismatches;
    
    // 5. Get statistics
    $stats_sql = "
        SELECT 
            COUNT(*) as total_clients,
            SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified_clients,
            COUNT(DISTINCT wp_user_id) as unique_wp_users,
            COUNT(DISTINCT role) as role_count,
            MIN(created_at) as oldest_record,
            MAX(updated_at) as latest_update
        FROM {$db_config['table_prefix']}charterhub_users
        WHERE role = 'charter_client'
    ";
    
    $stmt = $pdo->query($stats_sql);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['statistics'] = $stats;
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    $response['error'] = $e->getMessage();
    http_response_code(500);
    echo json_encode($response, JSON_PRETTY_PRINT);
} 