<?php

require_once __DIR__ . '/config.php';

// Get database connection
$conn = get_db_connection();
if (!$conn) {
    die('Database connection failed');
}

// Update the user's verified status
$stmt = $conn->prepare("UPDATE wp_charterhub_users SET verified = 1 WHERE email = ?");
if (!$stmt) {
    die('Failed to prepare query');
}

$email = isset($argv[1]) ? $argv[1] : 'test4@me.com';
$stmt->execute([$email]);

if ($stmt->rowCount() > 0) {
    echo "User $email has been verified successfully.\n";
} else {
    echo "User $email not found or already verified.\n";
}

?> 