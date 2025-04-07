<?php
/**
 * CharterHub Client Users Migration
 * Migrates existing verified client users to the new wp_charterhub_users table
 */

define('CHARTERHUB_LOADED', true);
require_once __DIR__ . '/config.php';

// Custom unserialize function for WordPress capabilities
function custom_unserialize($string) {
    $string = preg_replace('/^a:\d+:/', '', $string);
    $string = preg_replace('/;}$/', '}', $string);
    $string = str_replace('";', '":', $string);
    $string = str_replace(';b:', ':', $string);
    $result = json_decode($string, true);
    return $result ?: [];
}

// Set JSON content type header
header('Content-Type: application/json; charset=UTF-8');

$response = [
    'success' => true,
    'message' => 'Client users migration',
    'timestamp' => date('Y-m-d H:i:s'),
    'actions' => [],
    'results' => []
];

try {
    $pdo = get_db_connection();
    
    // Find all client users from WordPress tables
    $find_clients_sql = "
        SELECT 
            u.ID,
            u.user_email,
            m1.meta_value as verified,
            m2.meta_value as capabilities,
            m3.meta_value as refresh_token,
            u.user_registered as created_at
        FROM {$db_config['table_prefix']}users u
        LEFT JOIN {$db_config['table_prefix']}usermeta m1 ON u.ID = m1.user_id AND m1.meta_key = 'verified'
        LEFT JOIN {$db_config['table_prefix']}usermeta m2 ON u.ID = m2.user_id AND m2.meta_key = 'wp_capabilities'
        LEFT JOIN {$db_config['table_prefix']}usermeta m3 ON u.ID = m3.user_id AND m3.meta_key = 'refresh_token'
        WHERE EXISTS (
            SELECT 1 FROM {$db_config['table_prefix']}usermeta
            WHERE user_id = u.ID 
            AND meta_key = 'wp_capabilities'
            AND meta_value LIKE '%charter_client%'
        )
    ";
    
    $stmt = $pdo->query($find_clients_sql);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['results']['found_clients'] = count($clients);
    
    // Prepare insert statement
    $insert_sql = "
        INSERT IGNORE INTO {$db_config['table_prefix']}charterhub_users 
        (wp_user_id, role, verified, refresh_token, created_at) 
        VALUES (:wp_user_id, :role, :verified, :refresh_token, :created_at)
    ";
    $insert_stmt = $pdo->prepare($insert_sql);
    
    $migrated = 0;
    foreach ($clients as $client) {
        // Parse capabilities to confirm client role
        $capabilities = custom_unserialize($client['capabilities']);
        if (empty($capabilities) || !isset($capabilities['charter_client'])) {
            $response['results']['skipped'][] = [
                'user_id' => $client['ID'],
                'reason' => 'Not a charter client',
                'capabilities' => $client['capabilities']
            ];
            continue;
        }
        
        try {
            $insert_stmt->execute([
                'wp_user_id' => $client['ID'],
                'role' => 'charter_client',
                'verified' => !empty($client['verified']) ? 1 : 0,
                'refresh_token' => $client['refresh_token'],
                'created_at' => $client['created_at']
            ]);
            $migrated++;
            
            $response['results']['migrated'][] = [
                'user_id' => $client['ID'],
                'email' => $client['user_email'],
                'verified' => !empty($client['verified']) ? 1 : 0
            ];
        } catch (PDOException $e) {
            // Log the error but continue with other users
            error_log("Error migrating user {$client['ID']}: " . $e->getMessage());
            $response['results']['errors'][] = [
                'user_id' => $client['ID'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    $response['results']['migrated_clients'] = $migrated;
    
    // Verify the migration
    $verify_sql = "
        SELECT COUNT(*) as count, 
        SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified_count
        FROM {$db_config['table_prefix']}charterhub_users
    ";
    $verify_result = $pdo->query($verify_sql)->fetch(PDO::FETCH_ASSOC);
    $response['results']['verification'] = $verify_result;
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    $response['error'] = $e->getMessage();
    http_response_code(500);
    echo json_encode($response, JSON_PRETTY_PRINT);
} 