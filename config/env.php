<?php
/**
 * Environment Variable Loader for CharterHub
 * 
 * This file loads environment variables from .env file using phpdotenv library.
 * It makes them available via PHP's getenv() function.
 */

// Ensure vendor autoloader is included
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// Load environment variables from .env file
if (class_exists('Dotenv\Dotenv')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    try {
        $dotenv->load();
        
        // Define fallbacks for critical environment variables if they don't exist
        $required_vars = [
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'charterhub_local',
            'DB_USER' => 'root',
            'DB_PASSWORD' => '',
            'JWT_SECRET' => 'default-jwt-secret-please-change'
        ];
        
        foreach ($required_vars as $var => $default) {
            if (empty(getenv($var))) {
                putenv("$var=$default");
            }
        }
    } catch (\Exception $e) {
        // Log error but continue - we'll use defaults
        error_log('Error loading .env file: ' . $e->getMessage());
    }
} else {
    // Log warning about missing Dotenv library
    error_log('Warning: Dotenv library not found. Environment variables will not be loaded from .env file.');
} 