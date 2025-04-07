<?php
/**
 * Update Test User Password
 *
 * This script updates the password hash for test4@me.com in the wp_charterhub_users table
 * to ensure it matches the expected value 'password'.
 *
 * Usage: Run via CLI: php backend/auth/update_test_user_password.php
 */

require_once __DIR__ . '/config.php';

$conn = get_db_connection();
if (!$conn) {
    die('Database connection failed');
}

// Set a known password for testing
$password = 'password';
$hash = password_hash($password, PASSWORD_DEFAULT);

// Update the test user's password
$stmt = $conn->prepare("UPDATE wp_charterhub_users SET password = ? WHERE email = ?");
if (!$stmt) {
    die('Failed to prepare query');
}

$stmt->execute([$hash, 'test4@me.com']);

if ($stmt->rowCount() > 0) {
    echo "Password updated successfully for test4@me.com";
} else {
    echo "No user found with email test4@me.com";
} 