<?php
/**
 * Migrate Client User
 *
 * This script migrates a client user from the wp_users table to the wp_charterhub_clients table.
 * It checks if a user with the given email exists in wp_users with role 'charter_client' and if 
 * they don't already exist in wp_charterhub_clients. If not, it inserts the user data into 
 * wp_charterhub_clients, mapping 'user_login' to 'username', 'user_email' to 'email', and 'user_pass' to 'password'.
 *
 * Usage: php backend/auth/migrate_client_user.php test4@me.com
 */

require_once __DIR__ . '/config.php';

if ($argc < 2) {
    echo "Usage: php migrate_client_user.php <email>\n";
    exit;
}

$email = strtolower(trim($argv[1]));

$conn = get_db_connection_from_config();
if (!$conn) {
    die("Database connection failed\n");
}

// Check if the user already exists in wp_charterhub_clients
$stmt = $conn->prepare("SELECT * FROM wp_charterhub_clients WHERE email = ?");
if (!$stmt) {
    die("Failed to prepare query for wp_charterhub_clients\n");
}

$stmt->execute([$email]);
if ($stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "User already exists in wp_charterhub_clients\n";
    exit;
}

// Query the user from wp_users with role 'charter_client'
$stmt = $conn->prepare("SELECT * FROM wp_users WHERE LOWER(user_email) = ? AND role = 'charter_client'");
if (!$stmt) {
    die("Failed to prepare query for wp_users\n");
}

$stmt->execute([$email]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    echo "No client found in wp_users with email $email\n";
    exit;
}

// Insert the client data into wp_charterhub_clients
$insertStmt = $conn->prepare("INSERT INTO wp_charterhub_clients (email, password, first_name, last_name, display_name) VALUES (?, ?, ?, ?, ?)");
if (!$insertStmt) {
    die("Failed to prepare insert query for wp_charterhub_clients\n");
}

$result = $insertStmt->execute([
    strtolower($client['user_email']),
    $client['user_pass'],
    $client['first_name'] ?? '',
    $client['last_name'] ?? '',
    $client['display_name'] ?? ''
]);

if ($result) {
    echo "Client user migrated successfully.\n";
} else {
    echo "Failed to migrate client user.\n";
} 