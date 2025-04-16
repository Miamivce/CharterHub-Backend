<?php
/**
 * Direct Customers API Endpoint
 * 
 * Handles customer data operations for CharterHub admin users.
 * Supports: GET, POST, DELETE methods
 */

// Prevent direct access
define('CHARTERHUB_LOADED', true);

// Include authentication helper that also includes CORS helper
require_once 'direct-auth-helper.php';

// Process the request using consistent error handling and CORS
handle_admin_request(function($admin) {
    // Initialize response data
    $response = [];
    
    // Process based on request method
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // Get customers logic
            $db = getDbConnection();
            
            // Fetch customers data
            $stmt = $db->prepare("SELECT * FROM customers ORDER BY created_at DESC");
            $stmt->execute();
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = $customers;
            error_log("Retrieved " . count($customers) . " customers");
            break;
            
        case 'POST':
            // Get JSON input
            $inputJSON = file_get_contents('php://input');
            $input = json_decode($inputJSON, true);
            
            if (!$input) {
                throw new Exception('Invalid JSON data', 400);
            }
            
            // Basic validation
            if (empty($input['name']) || empty($input['email'])) {
                throw new Exception('Name and email are required', 400);
            }
            
            // Create customer logic
            $db = getDbConnection();
            
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
            $stmt->execute([$input['email']]);
            if ($stmt->fetchColumn()) {
                throw new Exception('Customer with this email already exists', 400);
            }
            
            // Insert new customer
            $stmt = $db->prepare("
                INSERT INTO customers (name, email, phone, company, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                sanitizeInput($input['name']),
                sanitizeInput($input['email']),
                sanitizeInput($input['phone'] ?? ''),
                sanitizeInput($input['company'] ?? ''),
                sanitizeInput($input['notes'] ?? ''),
                $admin['user_id'] ?? $admin['id'] ?? 0
            ]);
            
            $customerId = $db->lastInsertId();
            
            $response = ['id' => $customerId];
            error_log("Customer created successfully with ID: " . $customerId);
            break;
            
        case 'DELETE':
            // Get customer ID from URL query parameter
            $customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if (!$customerId) {
                throw new Exception('Customer ID is required', 400);
            }
            
            // Delete customer logic
            $db = getDbConnection();
            
            // Check if customer exists
            $stmt = $db->prepare("SELECT id FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Customer not found', 404);
            }
            
            // Delete the customer
            $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            
            $response = ['deleted' => true, 'id' => $customerId];
            error_log("Customer deleted successfully: ID " . $customerId);
            break;
            
        default:
            throw new Exception('Method not allowed', 405);
    }
    
    return $response;
}); 