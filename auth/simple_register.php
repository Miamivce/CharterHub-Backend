<?php
/**
 * Simple Registration Script for Testing
 * 
 * This script directly inserts a new user into the wp_charterhub_users table
 * with minimal complexity for debugging purposes.
 */

// Include database configuration
require_once __DIR__ . '/config.php';

// Set content type to JSON
header('Content-Type: application/json');
error_log("SIMPLE_REGISTER: Script started");

try {
    // Create PDO connection
    $pdo = get_db_connection();
    error_log("SIMPLE_REGISTER: Database connection successful");
    
    // Generate test user data
    $email = "test_simplescript_" . time() . "@example.com";
    $password = password_hash("Password123", PASSWORD_DEFAULT);
    $firstName = "TestSimple";
    $lastName = "User";
    $displayName = $firstName . " " . $lastName;
    $username = strtolower($firstName . $lastName) . rand(1000, 9999);
    $role = "client";
    $verification_token = bin2hex(random_bytes(16));
    
    error_log("SIMPLE_REGISTER: Attempting to insert user with email: " . $email);
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Create direct SQL statement with all table fields
    $sql = "
        INSERT INTO wp_charterhub_users (
            email, 
            username,
            password,
            first_name, 
            last_name, 
            display_name, 
            role, 
            verified, 
            verification_token, 
            created_at, 
            updated_at
        ) VALUES (
            :email,
            :username,
            :password,
            :firstName,
            :lastName,
            :displayName,
            :role,
            0,
            :verification_token,
            NOW(),
            NOW()
        )
    ";
    
    // Log the SQL
    error_log("SIMPLE_REGISTER: SQL: " . $sql);
    
    // Prepare and execute the statement with named parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':password', $password);
    $stmt->bindParam(':firstName', $firstName);
    $stmt->bindParam(':lastName', $lastName);
    $stmt->bindParam(':displayName', $displayName);
    $stmt->bindParam(':role', $role);
    $stmt->bindParam(':verification_token', $verification_token);
    
    $result = $stmt->execute();
    
    if ($result) {
        $userId = $pdo->lastInsertId();
        error_log("SIMPLE_REGISTER: User inserted successfully with ID: " . $userId);
        
        // Commit the transaction
        $pdo->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'User registered successfully for testing',
            'user_id' => $userId,
            'email' => $email
        ]);
    } else {
        // Rollback if insert failed
        $pdo->rollBack();
        error_log("SIMPLE_REGISTER: Insert failed");
        
        echo json_encode([
            'success' => false,
            'error' => 'insert_failed',
            'message' => 'Failed to insert user'
        ]);
    }
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("SIMPLE_REGISTER ERROR: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'database_error',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("SIMPLE_REGISTER ERROR: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ]);
} 