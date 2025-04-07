<?php
/**
 * CharterHub Client Users Migration
 * Migrates verified charter clients to wp_charterhub_users table
 */

define('CHARTERHUB_LOADED', true);
require_once __DIR__ . '/config.php';

// Set JSON content type header
header('Content-Type: application/json; charset=UTF-8');

$response = [
    'success' => true,
    'message' => 'Client users migration results',
    'timestamp' => date('Y-m-d H:i:s'),
    'results' => [
        'removed_admin' => false,
        'migrated_clients' => [],
        'skipped_clients' => [],
        'errors' => []
    ]
];

try {
    $pdo = get_db_connection();
    
    // 1. Remove admin from wp_charterhub_clients
    $remove_admin_sql = "
        DELETE FROM {$db_config['table_prefix']}charterhub_clients 
        WHERE role = 'administrator'
    ";
    
    $stmt = $pdo->prepare($remove_admin_sql);
    $stmt->execute();
    $response['results']['removed_admin'] = $stmt->rowCount() > 0;
    
    // 2. Get all charter clients from wp_users
    $get_clients_sql = "
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
        AND verified = 1
    ";
    
    $stmt = $pdo->query($get_clients_sql);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Migrate each client
    foreach ($clients as $client) {
        try {
            // Check if client already exists in charterhub_clients
            $check_sql = "
                SELECT id FROM {$db_config['table_prefix']}charterhub_clients 
                WHERE wp_user_id = :wp_user_id
            ";
            $stmt = $pdo->prepare($check_sql);
            $stmt->execute(['wp_user_id' => $client['ID']]);
            
            if ($stmt->rowCount() > 0) {
                $response['results']['skipped_clients'][] = [
                    'user_id' => $client['ID'],
                    'email' => $client['user_email'],
                    'reason' => 'Already exists in charterhub_clients'
                ];
                continue;
            }
            
            // Insert client into charterhub_clients
            $insert_sql = "
                INSERT INTO {$db_config['table_prefix']}charterhub_clients (
                    wp_user_id,
                    role,
                    verified,
                    created_at,
                    updated_at
                ) VALUES (
                    :wp_user_id,
                    'charter_client',
                    :verified,
                    :created_at,
                    :updated_at
                )
            ";
            
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                'wp_user_id' => $client['ID'],
                'verified' => $client['verified'],
                'created_at' => $client['user_registered'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            $response['results']['migrated_clients'][] = [
                'user_id' => $client['ID'],
                'email' => $client['user_email'],
                'charterhub_id' => $pdo->lastInsertId()
            ];
            
        } catch (Exception $e) {
            $response['results']['errors'][] = [
                'user_id' => $client['ID'],
                'email' => $client['user_email'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    // 4. Get final statistics
    $stats_sql = "
        SELECT COUNT(*) as total_clients,
               SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified_clients
        FROM {$db_config['table_prefix']}charterhub_clients
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