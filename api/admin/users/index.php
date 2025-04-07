<?php
/**
 * Admin User Management Endpoint
 * 
 * This endpoint allows admin users to list, search, and filter users.
 */

// Include authentication middleware
require_once '../../auth/validate-token.php';

// Ensure the user is an admin
$admin = require_admin();

// Handle different HTTP methods
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        handleGetUsers();
        break;
    case 'OPTIONS':
        // Options are handled by CORS in config.php
        break;
    default:
        error_response('Method not allowed', 405);
}

/**
 * Handle GET request to list users
 */
function handleGetUsers() {
    // Parse query parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? min(100, max(1, intval($_GET['per_page']))) : 20;
    $role = isset($_GET['role']) && in_array($_GET['role'], ['admin', 'client']) ? $_GET['role'] : null;
    $search = isset($_GET['search']) ? sanitize_input($_GET['search']) : null;
    $sortBy = isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['id', 'email', 'first_name', 'last_name', 'created_at']) ? $_GET['sort_by'] : 'id';
    $sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'DESC' ? 'DESC' : 'ASC';
    
    // Calculate offset
    $offset = ($page - 1) * $perPage;
    
    try {
        global $pdo;
        
        // Build the query
        $sql = '
            SELECT 
                id, email, username, display_name, first_name, last_name, 
                phone_number, company, role, verified, last_login, created_at
            FROM 
                wp_charterhub_users
            WHERE 1=1
        ';
        $countSql = 'SELECT COUNT(*) FROM wp_charterhub_users WHERE 1=1';
        $params = [];
        
        // Add role filter if specified
        if ($role) {
            $sql .= ' AND role = ?';
            $countSql .= ' AND role = ?';
            $params[] = $role;
        }
        
        // Add search filter if specified
        if ($search) {
            $searchTerm = "%$search%";
            $sql .= ' AND (email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR display_name LIKE ?)';
            $countSql .= ' AND (email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR display_name LIKE ?)';
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        // Add sorting
        $sql .= " ORDER BY $sortBy $sortOrder";
        
        // Add pagination
        $sql .= ' LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;
        
        // Execute the main query
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Execute the count query
        $countParams = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalUsers = $countStmt->fetchColumn();
        
        // Calculate pagination metadata
        $totalPages = ceil($totalUsers / $perPage);
        
        // Return users with pagination metadata
        json_response([
            'success' => true,
            'users' => $users,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_users' => $totalUsers,
                'total_pages' => $totalPages
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log('User list error: ' . $e->getMessage());
        error_response('Database error', 500);
    }
} 