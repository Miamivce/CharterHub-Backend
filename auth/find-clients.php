<?php
/**
 * CharterHub Client Users Finder
 * Locates all client users across different tables
 */

define('CHARTERHUB_LOADED', true);
require_once __DIR__ . '/config.php';

// Set JSON content type header
header('Content-Type: application/json; charset=UTF-8');

$response = [
    'success' => true,
    'message' => 'Client users search results',
    'timestamp' => date('Y-m-d H:i:s'),
    'sources' => []
];

try {
    $pdo = get_db_connection();
    
    // 1. Check wp_users table with direct role column
    $direct_role_sql = "
        SELECT 
            ID,
            user_email,
            user_registered,
            verified,
            role,
            first_name,
            last_name,
            company
        FROM {$db_config['table_prefix']}users
        WHERE role = 'charter_client'
        OR role LIKE '%charter_client%'
    ";
    
    $stmt = $pdo->query($direct_role_sql);
    $direct_role_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['sources']['direct_role'] = [
        'count' => count($direct_role_clients),
        'clients' => $direct_role_clients
    ];
    
    // 2. Check wp_usermeta for capabilities
    $capabilities_sql = "
        SELECT 
            u.ID,
            u.user_email,
            u.user_registered,
            u.verified,
            u.role,
            u.first_name,
            u.last_name,
            u.company,
            m.meta_value as capabilities
        FROM {$db_config['table_prefix']}users u
        JOIN {$db_config['table_prefix']}usermeta m ON u.ID = m.user_id
        WHERE m.meta_key = '{$db_config['table_prefix']}capabilities'
        AND m.meta_value LIKE '%charter_client%'
        AND u.ID NOT IN (SELECT ID FROM {$db_config['table_prefix']}users WHERE role = 'charter_client')
    ";
    
    $stmt = $pdo->query($capabilities_sql);
    $capability_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['sources']['capabilities'] = [
        'count' => count($capability_clients),
        'clients' => $capability_clients
    ];
    
    // 3. Check any existing charterhub_users
    $existing_sql = "
        SELECT 
            c.*,
            u.user_email,
            u.first_name,
            u.last_name,
            u.company
        FROM {$db_config['table_prefix']}charterhub_users c
        JOIN {$db_config['table_prefix']}users u ON c.wp_user_id = u.ID
    ";
    
    $stmt = $pdo->query($existing_sql);
    $existing_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['sources']['existing_charterhub'] = [
        'count' => count($existing_clients),
        'clients' => $existing_clients
    ];
    
    // 4. Check self_registered users
    $self_registered_sql = "
        SELECT 
            ID,
            user_email,
            user_registered,
            verified,
            role,
            first_name,
            last_name,
            company
        FROM {$db_config['table_prefix']}users
        WHERE self_registered = 1
        AND ID NOT IN (
            SELECT wp_user_id FROM {$db_config['table_prefix']}charterhub_users
        )
    ";
    
    $stmt = $pdo->query($self_registered_sql);
    $self_registered_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['sources']['self_registered'] = [
        'count' => count($self_registered_clients),
        'clients' => $self_registered_clients
    ];
    
    // Calculate total unique clients
    $all_ids = [];
    foreach ($response['sources'] as $source) {
        foreach ($source['clients'] as $client) {
            $all_ids[] = $client['ID'];
        }
    }
    $unique_ids = array_unique($all_ids);
    $response['total_unique_clients'] = count($unique_ids);
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    $response['error'] = $e->getMessage();
    http_response_code(500);
    echo json_encode($response, JSON_PRETTY_PRINT);
} 