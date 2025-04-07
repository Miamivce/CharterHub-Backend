<?php
/**
 * API Index
 * 
 * This is the main entry point for the API.
 */

// Set content type to JSON
header('Content-Type: application/json');

// Return API information
echo json_encode([
    'name' => 'CharterHub API',
    'version' => '1.0.0',
    'description' => 'The CharterHub API provides endpoints for authentication and user management.',
    'endpoints' => [
        'auth' => [
            'login' => '/auth/login',
            'refresh' => '/auth/refresh',
            'register' => '/auth/register',
            'verify' => '/auth/verify',
            'reset-password' => '/auth/reset-password'
        ],
        'users' => [
            'profile' => '/users/profile'
        ],
        'admin' => [
            'users' => '/admin/users',
            'users_create' => '/admin/users/create',
            'user_detail' => '/admin/users/{id}'
        ]
    ],
    'documentation' => 'For more information, please contact the administrator.'
]); 