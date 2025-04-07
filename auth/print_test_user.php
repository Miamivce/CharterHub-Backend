<?php
/**
 * Print Test User Script
 * 
 * This script queries the wp_charterhub_users table for the user with email test4@me.com
 * and displays their information for debugging purposes.
 */

// Connect to the database
$host = "localhost";
$dbname = "charterhub_local";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get test user details from wp_charterhub_users
    $stmt = $conn->prepare("SELECT * FROM wp_charterhub_users WHERE email = ?");
    $stmt->execute(["test4@me.com"]);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "Test user found:\n";
        print_r($user);
    } else {
        echo "Test user not found\n";
    }
    
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
} 