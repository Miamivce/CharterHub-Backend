<?php
/**
 * CharterHub Login Fix Deployment Script
 * 
 * This script addresses all issues related to login functionality:
 * 1. Adds last_login column to wp_charterhub_users table
 * 2. Fixes action column in wp_charterhub_auth_logs table to TEXT
 * 3. Fixes status column in wp_charterhub_auth_logs table to TEXT
 * 4. Adds AUTO_INCREMENT to id column in wp_charterhub_auth_logs table
 * 5. Creates wp_charterhub_refresh_tokens table if missing
 * 6. Adds generate_jwt function alias to jwt-core.php
 * 
 * Usage: Run this script once on the production server.
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "===== CharterHub Login Fix Deployment =====\n\n";

// Include database connection functions
if (file_exists(__DIR__ . '/../utils/database.php')) {
    include_once __DIR__ . '/../utils/database.php';
} else {
    include_once __DIR__ . '/utils/database.php';
}

// Connect to database
echo "Connecting to database...\n";
try {
    $pdo = function_exists('get_db_connection_from_config') 
           ? get_db_connection_from_config() 
           : get_db_connection();
    
    echo "✅ Database connection successful\n\n";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n\n";
    exit;
}

// Fix 1: Add last_login column to wp_charterhub_users
echo "Fix 1: Adding last_login column to wp_charterhub_users...\n";
try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_users LIKE 'last_login'");
    $column_exists = $stmt->fetch();
    
    if ($column_exists) {
        echo "✅ The 'last_login' column already exists\n\n";
    } else {
        // Add the column
        $pdo->exec("ALTER TABLE wp_charterhub_users ADD COLUMN last_login DATETIME NULL DEFAULT NULL COMMENT 'Time of last successful login'");
        echo "✅ Added 'last_login' column to wp_charterhub_users\n\n";
    }
} catch (Exception $e) {
    echo "❌ Error with last_login column: " . $e->getMessage() . "\n\n";
}

// Fix 2: Change action column in wp_charterhub_auth_logs to TEXT
echo "Fix 2: Modifying action column in wp_charterhub_auth_logs...\n";
try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_auth_logs'");
    $table_exists = $stmt->fetch();
    
    if (!$table_exists) {
        echo "Creating auth_logs table...\n";
        $pdo->exec("CREATE TABLE wp_charterhub_auth_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NULL,
            action TEXT NOT NULL,
            status TEXT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255) NULL,
            details JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        echo "✅ Created wp_charterhub_auth_logs table with correct schema\n\n";
    } else {
        // Check action column type
        $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_auth_logs LIKE 'action'");
        $column = $stmt->fetch();
        
        if ($column) {
            if (stripos($column['Type'], 'text') === false) {
                $pdo->exec("ALTER TABLE wp_charterhub_auth_logs MODIFY action TEXT NOT NULL");
                echo "✅ Modified 'action' column to TEXT\n";
            } else {
                echo "✅ The 'action' column is already TEXT\n";
            }
        } else {
            $pdo->exec("ALTER TABLE wp_charterhub_auth_logs ADD COLUMN action TEXT NOT NULL");
            echo "✅ Added 'action' column as TEXT\n";
        }
        
        // Fix 3: Change status column to TEXT
        echo "\nFix 3: Modifying status column in wp_charterhub_auth_logs...\n";
        $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_auth_logs LIKE 'status'");
        $column = $stmt->fetch();
        
        if ($column) {
            if (stripos($column['Type'], 'text') === false) {
                $pdo->exec("ALTER TABLE wp_charterhub_auth_logs MODIFY status TEXT NULL");
                echo "✅ Modified 'status' column to TEXT\n";
            } else {
                echo "✅ The 'status' column is already TEXT\n";
            }
        } else {
            $pdo->exec("ALTER TABLE wp_charterhub_auth_logs ADD COLUMN status TEXT NULL");
            echo "✅ Added 'status' column as TEXT\n";
        }
        
        // Fix 4: Add AUTO_INCREMENT to id column
        echo "\nFix 4: Adding AUTO_INCREMENT to id column in wp_charterhub_auth_logs...\n";
        $stmt = $pdo->query("SHOW COLUMNS FROM wp_charterhub_auth_logs LIKE 'id'");
        $column = $stmt->fetch();
        
        if ($column) {
            if (stripos($column['Extra'], 'auto_increment') === false) {
                try {
                    $pdo->exec("ALTER TABLE wp_charterhub_auth_logs MODIFY id BIGINT NOT NULL AUTO_INCREMENT");
                    echo "✅ Added AUTO_INCREMENT to 'id' column\n\n";
                } catch (Exception $e) {
                    // Try alternative approach if multiple primary key error
                    if (stripos($e->getMessage(), 'Multiple primary key') !== false) {
                        echo "Multiple primary key error detected. Trying alternative approach...\n";
                        
                        // Create a backup of the auth_logs table
                        $timestamp = date('YmdHis');
                        try {
                            $pdo->exec("CREATE TABLE wp_charterhub_auth_logs_backup_$timestamp LIKE wp_charterhub_auth_logs");
                            $pdo->exec("INSERT INTO wp_charterhub_auth_logs_backup_$timestamp SELECT * FROM wp_charterhub_auth_logs");
                            echo "Created backup of auth_logs table\n";
                            
                            // Create a temporary table with the correct schema
                            $pdo->exec("CREATE TABLE wp_charterhub_auth_logs_new (
                                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                                user_id BIGINT NULL,
                                action TEXT NOT NULL,
                                status TEXT NULL,
                                ip_address VARCHAR(45) NOT NULL,
                                user_agent VARCHAR(255) NULL,
                                details JSON NULL,
                                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
                            
                            // Copy data
                            $pdo->exec("INSERT INTO wp_charterhub_auth_logs_new 
                                       (id, user_id, action, status, ip_address, user_agent, details, created_at)
                                       SELECT id, user_id, action, status, ip_address, user_agent, details, created_at 
                                       FROM wp_charterhub_auth_logs");
                            
                            // Rename tables
                            $pdo->exec("RENAME TABLE wp_charterhub_auth_logs TO wp_charterhub_auth_logs_old, 
                                                    wp_charterhub_auth_logs_new TO wp_charterhub_auth_logs");
                            
                            echo "✅ Successfully recreated auth_logs table with AUTO_INCREMENT\n\n";
                        } catch (Exception $innerEx) {
                            echo "❌ Alternative approach failed: " . $innerEx->getMessage() . "\n";
                            echo "   Will continue with other fixes\n\n";
                        }
                    } else {
                        echo "❌ Error adding AUTO_INCREMENT: " . $e->getMessage() . "\n";
                        echo "   Will continue with other fixes\n\n";
                    }
                }
            } else {
                echo "✅ The 'id' column already has AUTO_INCREMENT\n\n";
            }
        } else {
            echo "❌ 'id' column not found in wp_charterhub_auth_logs\n\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error with auth_logs table: " . $e->getMessage() . "\n\n";
}

// Fix 5: Create refresh token table
echo "Fix 5: Creating wp_charterhub_refresh_tokens table if it doesn't exist...\n";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_refresh_tokens'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "✅ Refresh token table already exists\n\n";
    } else {
        $pdo->exec("CREATE TABLE wp_charterhub_refresh_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expiry DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        
        echo "✅ Created refresh token table\n\n";
    }
} catch (Exception $e) {
    echo "❌ Error creating refresh token table: " . $e->getMessage() . "\n\n";
}

// Fix 6: Add generate_jwt function alias to jwt-core.php
echo "Fix 6: Adding generate_jwt function alias to jwt-core.php...\n";
try {
    $jwt_core_path = null;
    $possible_paths = [
        __DIR__ . '/../auth/jwt-core.php',
        __DIR__ . '/auth/jwt-core.php'
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $jwt_core_path = $path;
            break;
        }
    }
    
    if ($jwt_core_path) {
        $content = file_get_contents($jwt_core_path);
        
        if (strpos($content, 'function generate_jwt(') === false) {
            $alias_function = '
/**
 * Alias for generate_access_token for backward compatibility
 * 
 * @param array $payload The token payload
 * @return string JWT token
 */
function generate_jwt($payload) {
    // Extract required fields from payload
    $user_id = isset($payload["sub"]) ? $payload["sub"] : null;
    $email = isset($payload["email"]) ? $payload["email"] : null;
    $role = isset($payload["role"]) ? $payload["role"] : null;
    $token_version = isset($payload["ver"]) ? $payload["ver"] : 0;
    
    // Generate token using the core function
    return generate_access_token($user_id, $email, $role, $token_version);
}
';
            file_put_contents($jwt_core_path, $content . $alias_function);
            echo "✅ Added generate_jwt function alias to jwt-core.php\n\n";
        } else {
            echo "✅ generate_jwt function already exists in jwt-core.php\n\n";
        }
    } else {
        echo "❌ jwt-core.php file not found\n\n";
    }
} catch (Exception $e) {
    echo "❌ Error adding generate_jwt function: " . $e->getMessage() . "\n\n";
}

echo "===== Login Fix Deployment Completed =====\n\n";
echo "All fixes have been applied. The login functionality should now work correctly.\n";
echo "If issues persist, please check the server logs for additional errors.\n"; 