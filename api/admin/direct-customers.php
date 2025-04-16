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
    
    try {
        error_log("DIRECT-CUSTOMERS: Processing " . $_SERVER['REQUEST_METHOD'] . " request");
        
        // Process based on request method
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                // Get customers logic
                $db = getDbConnection();
                error_log("DIRECT-CUSTOMERS: Connected to database for GET request");
                
                // Fetch customers data - note: customers are users with role='client'
                $stmt = $db->prepare("SELECT id, username, email, first_name, last_name, role, status, created_at, updated_at 
                                     FROM wp_charterhub_users 
                                     WHERE role = 'client' 
                                     ORDER BY created_at DESC");
                $stmt->execute();
                error_log("DIRECT-CUSTOMERS: Executed SELECT query on wp_charterhub_users for clients");
                
                $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response = $customers;
                error_log("DIRECT-CUSTOMERS: Retrieved " . count($customers) . " customers");
                break;
                
            case 'POST':
                // Get JSON input
                $inputJSON = file_get_contents('php://input');
                $input = json_decode($inputJSON, true);
                
                if (!$input) {
                    throw new Exception('Invalid JSON data', 400);
                }
                
                // Basic validation
                if (empty($input['email'])) {
                    throw new Exception('Email is required', 400);
                }
                
                // Create customer logic
                $db = getDbConnection();
                error_log("DIRECT-CUSTOMERS: Connected to database for POST request");
                
                // Check if email already exists
                $stmt = $db->prepare("SELECT id FROM wp_charterhub_users WHERE email = ?");
                $stmt->execute([$input['email']]);
                error_log("DIRECT-CUSTOMERS: Checked if email exists: " . $input['email']);
                
                if ($stmt->fetchColumn()) {
                    throw new Exception('Customer with this email already exists', 400);
                }
                
                // Generate username if not provided
                $username = $input['username'] ?? explode('@', $input['email'])[0] . '_' . substr(md5(time()), 0, 6);
                
                // Insert new customer as a user with role='client'
                $stmt = $db->prepare("
                    INSERT INTO wp_charterhub_users (username, email, first_name, last_name, role, status, created_by)
                    VALUES (?, ?, ?, ?, 'client', 'active', ?)
                ");
                
                $admin_id = $admin['user_id'] ?? $admin['id'] ?? 0;
                error_log("DIRECT-CUSTOMERS: Inserting new customer with admin ID: " . $admin_id);
                
                $stmt->execute([
                    sanitizeInput($username),
                    sanitizeInput($input['email']),
                    sanitizeInput($input['first_name'] ?? ''),
                    sanitizeInput($input['last_name'] ?? ''),
                    $admin_id
                ]);
                
                $customerId = $db->lastInsertId();
                
                $response = ['id' => $customerId];
                error_log("DIRECT-CUSTOMERS: Customer created successfully with ID: " . $customerId);
                break;
                
            case 'DELETE':
                // Get customer ID from URL query parameter
                $customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
                
                if (!$customerId) {
                    throw new Exception('Customer ID is required', 400);
                }
                
                // Delete customer logic
                $db = getDbConnection();
                error_log("DIRECT-CUSTOMERS: Connected to database for DELETE request, customer ID: " . $customerId);
                
                // Check if customer exists and is a client
                $stmt = $db->prepare("SELECT id FROM wp_charterhub_users WHERE id = ? AND role = 'client'");
                $stmt->execute([$customerId]);
                
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Customer not found or not a client', 404);
                }
                
                // Delete the customer (or set status to 'deleted' if you prefer not to delete records)
                $stmt = $db->prepare("UPDATE wp_charterhub_users SET status = 'deleted' WHERE id = ? AND role = 'client'");
                $stmt->execute([$customerId]);
                
                $response = ['deleted' => true, 'id' => $customerId];
                error_log("DIRECT-CUSTOMERS: Customer marked as deleted successfully: ID " . $customerId);
                break;
                
            default:
                throw new Exception('Method not allowed', 405);
        }
        
        return $response;
    } catch (Exception $e) {
        error_log("DIRECT-CUSTOMERS ERROR: " . $e->getMessage() . " - Code: " . $e->getCode());
        throw $e; // Rethrow to let handle_admin_request handle the error response
    }
}); 