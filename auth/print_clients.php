<?php
/**
 * Print all entries from the wp_charterhub_clients table.
 */

// Define a constant to allow access
define('CHARTERHUB_LOADED', true);

// Include configuration files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../db-config.php';

// Get a database connection
$conn = get_db_connection();
if (!$conn) {
    die('Database connection failed');
}

// Query the wp_charterhub_clients table
$stmt = $conn->query("SELECT * FROM wp_charterhub_clients");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$clients) {
    echo "No clients found.\n";
} else {
    echo json_encode($clients, JSON_PRETTY_PRINT);
} 