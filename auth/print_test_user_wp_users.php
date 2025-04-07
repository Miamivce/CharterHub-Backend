<?php
/**
 * Print Test User from wp_users
 *
 * This script queries the wp_users table for the user with email test4@me.com
 * and outputs the result as JSON. Use this to diagnose if the test user exists in the wp_users table.
 *
 * Usage: Run via CLI: php backend/auth/print_test_user_wp_users.php
 */

require_once __DIR__ . '/config.php';

$conn = get_db_connection_from_config();
if (!$conn) {
    die('Database connection failed');
}

$stmt = $conn->prepare("SELECT * FROM wp_users WHERE user_email = ?");
if (!$stmt) {
    die('Failed to prepare query');
}

$stmt->execute(['test4@me.com']);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($userData) {
    echo json_encode($userData, JSON_PRETTY_PRINT);
} else {
    echo "No user found with email test4@me.com in wp_users";
} 