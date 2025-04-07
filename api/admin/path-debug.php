<?php
/**
 * Path Diagnostics File
 * 
 * This file helps identify the correct paths to configuration files.
 * For development use only.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enable CORS for local development
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Initialize response
$response = [
    'server_info' => [
        'php_version' => PHP_VERSION,
        'os' => PHP_OS,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    ],
    'paths' => [
        'current_file' => __FILE__,
        'current_dir' => __DIR__,
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown',
    ],
    'file_checks' => [],
    'directory_checks' => [],
    'possible_db_files' => [],
];

// Check various possible locations for config/db.php
$possible_paths = [
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../../../config/db.php',
    __DIR__ . '/../../../../config/db.php',
    __DIR__ . '/../config/db.php',
    dirname(dirname(dirname(__DIR__))) . '/config/db.php',
    dirname(dirname(__DIR__)) . '/config/db.php',
    // Look for all db.php files (might be in different locations)
    __DIR__ . '/../../db.php',
    __DIR__ . '/../../../db.php',
    __DIR__ . '/../../../../db.php',
    __DIR__ . '/../db.php',
];

// Check if the files exist
foreach ($possible_paths as $path) {
    $response['file_checks'][$path] = file_exists($path);
    if (file_exists($path)) {
        $response['possible_db_files'][] = $path;
    }
}

// Check for wp-config.php (WordPress configuration)
$wp_config_paths = [
    __DIR__ . '/../../wp-config.php',
    __DIR__ . '/../../../wp-config.php',
    __DIR__ . '/../../../../wp-config.php',
    dirname(dirname(dirname(__DIR__))) . '/wp-config.php',
];

foreach ($wp_config_paths as $path) {
    if (file_exists($path)) {
        $response['possible_db_files'][] = $path;
    }
}

// Check common directories
$directories = [
    'api' => __DIR__ . '/..',
    'admin' => __DIR__,
    'backend' => dirname(dirname(__DIR__)),
    'config' => __DIR__ . '/../../config',
    'auth' => __DIR__ . '/../../auth',
    'middleware' => __DIR__ . '/../../middleware',
];

foreach ($directories as $name => $path) {
    $response['directory_checks'][$name] = [
        'path' => $path,
        'exists' => is_dir($path),
        'contents' => is_dir($path) ? scandir($path) : 'Not a directory or inaccessible'
    ];
}

// Return the diagnostic information
echo json_encode($response, JSON_PRETTY_PRINT);
?> 