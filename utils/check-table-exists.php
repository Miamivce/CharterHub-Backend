<?php
/**
 * Check if JWT Tokens Table Exists
 * 
 * This script checks if the wp_charterhub_jwt_tokens table exists
 * in the database and displays its structure if it does.
 */

// Include database connection
require_once __DIR__ . '/db-connection.php';

try {
    $db = get_db_connection();
    
    echo "Successfully connected to database.\n";
    
    // Get all tables in the database
    $stmt = $db->query("SHOW TABLES");
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    echo "Tables in database: " . implode(", ", $tables) . "\n\n";
    
    // Check if the wp_charterhub_jwt_tokens table exists
    $table_name = 'wp_charterhub_jwt_tokens';
    if (in_array($table_name, $tables)) {
        echo "Table '{$table_name}' EXISTS.\n";
        
        // Display table structure
        $stmt = $db->query("DESCRIBE {$table_name}");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Table structure:\n";
        echo str_repeat('-', 80) . "\n";
        echo sprintf("%-20s %-20s %-10s %-10s %-20s\n", 'Field', 'Type', 'Null', 'Key', 'Default');
        echo str_repeat('-', 80) . "\n";
        
        foreach ($columns as $column) {
            echo sprintf("%-20s %-20s %-10s %-10s %-20s\n", 
                $column['Field'], 
                $column['Type'], 
                $column['Null'], 
                $column['Key'], 
                $column['Default'] ?? 'NULL'
            );
        }
        
        echo str_repeat('-', 80) . "\n";
        
        // Check if there are any records in the table
        $stmt = $db->query("SELECT COUNT(*) FROM {$table_name}");
        $count = $stmt->fetchColumn();
        echo "The table contains {$count} records.\n";
    } else {
        echo "Table '{$table_name}' DOES NOT EXIST.\n";
        echo "You need to create the table before using the JWT authentication system.\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} 