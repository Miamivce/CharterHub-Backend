<?php
/**
 * Store Refresh Token API Endpoint
 * 
 * This endpoint stores a refresh token for a user in the database.
 * Only used in development mode with mock authentication.
 */

// Include configuration
require_once __DIR__ . '/../wp-config.php';

// Set CORS headers
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400'); // 24 hours cache

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['userId']) || !isset($data['refreshToken'])) {
        throw new Exception('Missing required fields: userId and refreshToken');
    }

    // Connect to database
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASSWORD,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    // Update user's refresh token
    $stmt = $pdo->prepare("
        UPDATE wp_charterhub_users 
        SET refresh_token = :refresh_token 
        WHERE wp_user_id = :user_id
    ");

    $stmt->execute([
        ':refresh_token' => $data['refreshToken'],
        ':user_id' => $data['userId']
    ]);

    if ($stmt->rowCount() === 0) {
        // If no rows were updated, try to insert a new record
        $stmt = $pdo->prepare("
            INSERT INTO wp_charterhub_users (wp_user_id, refresh_token, role, verified)
            VALUES (:user_id, :refresh_token, 'administrator', true)
        ");

        $stmt->execute([
            ':user_id' => $data['userId'],
            ':refresh_token' => $data['refreshToken']
        ]);
    }

    // Return success response
    http_response_code(200);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Store refresh token error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to store refresh token',
        'message' => $e->getMessage()
    ]);
} 