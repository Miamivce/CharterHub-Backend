<?php
/**
 * CharterHub Database Abstraction Layer
 * 
 * This file provides a consistent interface for database operations,
 * abstracting away the specifics of PDO vs MySQLi implementations.
 * 
 * It centralizes all database access and ensures consistent error handling.
 */

// Define a constant to prevent direct access
if (!defined('CHARTERHUB_LOADED')) {
    define('CHARTERHUB_LOADED', true);
}

// Include auth configuration (which now contains database configuration)
// Check for circular dependency by seeing if $db_config is already defined
if (!isset($GLOBALS['db_config'])) {
    require_once dirname(__FILE__) . '/../auth/config.php';
}

/**
 * Get a database connection using the configuration
 * 
 * @return PDO A PDO database connection
 */
function getDbConnection() {
    static $connection = null;
    
    if ($connection === null) {
        try {
            error_log("Creating new database connection");
            $connection = get_db_connection_from_config();
            
            if (!$connection) {
                error_log("get_db_connection_from_config returned null");
                throw new Exception("Failed to get database connection from config");
            }
            
            // Test the connection with a simple query
            try {
                error_log("Testing database connection with SELECT 1");
                $test_result = $connection->query("SELECT 1");
                
                if (!$test_result) {
                    error_log("Test query failed: " . print_r($connection->errorInfo(), true));
                    throw new Exception("Database connection test query failed");
                }
                
                error_log("Database connection created and tested successfully");
            } catch (PDOException $test_e) {
                error_log("PDO Exception in connection test: " . $test_e->getMessage());
                // Log additional info but don't rethrow yet - we'll try to fix it
                error_log("Connection DSN: " . substr(get_connection_dsn(), 0, 20) . "...");  // Don't log the full DSN for security
            }
            
            // Ensure database views exist for cross-prefix compatibility
            ensureDatabaseViewsCompat($connection);
            
        } catch (PDOException $e) {
            error_log("PDO Exception in getDbConnection: " . $e->getMessage());
            error_log("PDO Error Code: " . $e->getCode());
            
            // Try a fallback connection if this was a configuration error
            if ($e->getCode() == 1045 || $e->getCode() == 2002) { // Access denied or Connection refused
                error_log("Attempting fallback database connection...");
                try {
                    // Try with simplified options
                    $connection = get_fallback_connection();
                    error_log("Fallback connection attempt completed");
                    
                    if ($connection) {
                        // Test fallback connection
                        $test = $connection->query("SELECT 1");
                        if ($test) {
                            error_log("Fallback connection successful");
                            return $connection;
                        }
                    }
                } catch (Exception $fallback_e) {
                    error_log("Fallback connection failed: " . $fallback_e->getMessage());
                }
            }
            
            // If we got here, we couldn't connect - rethrow the original exception
            throw new Exception("Database connection error: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Exception in getDbConnection: " . $e->getMessage());
            throw $e;
        }
    }
    
    return $connection;
}

/**
 * Get a fallback database connection with simplified options
 * 
 * @return PDO|null A PDO database connection or null on failure
 */
function get_fallback_connection() {
    try {
        global $db_config;
        
        if (!isset($db_config) || !is_array($db_config)) {
            error_log("No db_config available for fallback connection");
            return null;
        }
        
        // Build a simplified DSN
        $host = $db_config['host'] ?? 'mysql-charterhub-charterhub.c.aivencloud.com';
        $port = $db_config['port'] ?? '19174';
        $dbname = $db_config['dbname'] ?? $db_config['name'] ?? 'defaultdb';
        $username = $db_config['username'] ?? $db_config['user'] ?? 'avnadmin';
        $password = $db_config['password'] ?? $db_config['pass'] ?? 'AVNS_HCZbm5bZJE1L9C8Pz8C';
        
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname}";
        
        error_log("Attempting fallback connection with simplified DSN");
        
        // Simplified options
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5, // Short timeout for quick failure
        ]);
        
        return $pdo;
    } catch (Exception $e) {
        error_log("Failed to create fallback connection: " . $e->getMessage());
        return null;
    }
}

/**
 * Get the DSN string for connection (without credentials)
 * 
 * @return string DSN connection string 
 */
function get_connection_dsn() {
    global $db_config;
    
    if (!isset($db_config) || !is_array($db_config)) {
        return "mysql:host=unknown;dbname=unknown";
    }
    
    return sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db_config['host'] ?? 'unknown',
        $db_config['port'] ?? 'unknown',
        $db_config['dbname'] ?? $db_config['name'] ?? 'unknown',
        $db_config['charset'] ?? 'utf8mb4'
    );
}

/**
 * Ensure database views exist for compatibility between prefixed and non-prefixed tables
 *
 * @param PDO $connection The database connection
 * @return bool True if successful, false otherwise
 */
function ensureDatabaseViewsCompat($connection) {
    try {
        // First check if the ensure-views.php file exists and include it
        $viewsFile = dirname(__FILE__) . '/ensure-views.php';
        if (file_exists($viewsFile)) {
            require_once $viewsFile;
            if (function_exists('ensureDatabaseViews')) {
                return ensureDatabaseViews($connection);
            }
        }
        
        // Fallback implementation if ensure-views.php doesn't exist or function not available
        error_log("Using fallback view creation");
        global $db_config;
        $prefix = isset($db_config['table_prefix']) ? $db_config['table_prefix'] : 'wp_';
        
        // Core tables to ensure views for
        $tables = [
            $prefix . 'charterhub_users' => 'charterhub_users'
        ];
        
        foreach ($tables as $source => $view) {
            if ($source === $view) continue; // Skip if they're the same
            
            // Check if source table exists
            $stmt = $connection->query("SHOW TABLES LIKE '$source'");
            if ($stmt && $stmt->rowCount() > 0) {
                // Create view to the source table
                $connection->exec("CREATE OR REPLACE VIEW `$view` AS SELECT * FROM `$source`");
                error_log("Created view $view -> $source");
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error ensuring database views: " . $e->getMessage());
        // Don't throw here, just return false as this is a convenience function
        return false;
    }
}

/**
 * Execute a parameterized query and return the statement
 * 
 * @param string $query The SQL query with placeholders
 * @param array $params An array of parameters to bind
 * @return PDOStatement The executed statement
 * @throws Exception If the query execution fails
 */
function executeQuery($query, array $params = []) {
    try {
        $conn = getDbConnection();
        if (!$conn) {
            error_log("Database connection failed in executeQuery");
            throw new Exception("Database connection error");
        }
        
        // Log the query and parameters for debugging
        error_log("Executing query: " . $query);
        error_log("With parameters: " . print_r($params, true));
        
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            error_log("Failed to prepare statement: " . print_r($conn->errorInfo(), true));
            throw new Exception("Failed to prepare query: " . $conn->errorInfo()[2]);
        }
        
        // Execute with parameters
        $result = $stmt->execute($params);
        
        if (!$result) {
            error_log("Statement execution failed: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Failed to execute query: " . $stmt->errorInfo()[2]);
        }
        
        return $stmt;
    } catch (PDOException $e) {
        error_log("PDO Exception in executeQuery: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("Query was: " . $query);
        error_log("Parameters were: " . print_r($params, true));
        throw new Exception("Database query error: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("Exception in executeQuery: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Fetch a single row from the database
 * 
 * @param string $query The SQL query with placeholders
 * @param array $params An array of parameters to bind
 * @param int $fetchStyle The PDO fetch style
 * @return array|false The fetched row or false if no rows
 * @throws Exception If the query execution fails
 */
function fetchRow($query, array $params = [], $fetchStyle = PDO::FETCH_ASSOC) {
    try {
        error_log("fetchRow called with query: " . $query);
        error_log("Parameters: " . print_r($params, true));
        
        // Get a database connection
        $conn = getDbConnection();
        if (!$conn) {
            error_log("Database connection failed in fetchRow");
            throw new Exception("Database connection error in fetchRow");
        }
        
        // Prepare the statement
        error_log("Preparing statement...");
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Failed to prepare statement: " . print_r($conn->errorInfo(), true));
            throw new Exception("Failed to prepare query in fetchRow");
        }
        
        // Bind parameters individually, with better type handling
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                // Determine parameter type
                $type = PDO::PARAM_STR;
                if (is_int($value)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $type = PDO::PARAM_NULL;
                }
                
                // Handle both positional (numeric keys) and named parameters
                $param_name = is_numeric($key) ? $key + 1 : $key;
                $stmt->bindValue($param_name, $value, $type);
            }
        }
        
        // Execute the statement
        error_log("Executing statement...");
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("Statement execution failed: " . print_r($stmt->errorInfo(), true));
            
            // Try a simple fallback if this query failed
            if (strpos($query, 'DESCRIBE') !== false || strpos($query, 'SHOW TABLES') !== false) {
                error_log("Attempting fallback for simple query: " . $query);
                
                try {
                    // For table information queries, try direct query for more compatibility
                    $direct_stmt = $conn->query($query);
                    if ($direct_stmt) {
                        $rows = [];
                        while ($row = $direct_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $rows[] = $row;
                        }
                        
                        error_log("Direct query returned " . count($rows) . " rows");
                        return !empty($rows) ? $rows[0] : false;
                    }
                } catch (Exception $fallback_e) {
                    error_log("Fallback query failed: " . $fallback_e->getMessage());
                }
            }
            
            throw new Exception("Failed to execute query in fetchRow: " . print_r($stmt->errorInfo(), true));
        }
        
        // Fetch the row
        error_log("Fetching result...");
        $row = $stmt->fetch($fetchStyle);
        
        error_log("fetchRow result: " . ($row ? "Found data" : "No data found"));
        return $row;
    } catch (PDOException $e) {
        error_log("PDO Exception in fetchRow: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("Query was: " . $query);
        error_log("Parameters were: " . print_r($params, true));
        
        // Try a fallback for some common query types that might be failing due to compatibility
        if (strpos($query, 'DESCRIBE') !== false || strpos($query, 'SHOW TABLES') !== false) {
            try {
                error_log("Trying emergency fallback query");
                
                // Get a fresh connection
                $conn = get_fallback_connection();
                if ($conn) {
                    // For schema queries, try a direct query without parameters
                    $direct_result = $conn->query($query);
                    if ($direct_result) {
                        return $direct_result->fetch(PDO::FETCH_ASSOC);
                    }
                }
            } catch (Exception $inner_e) {
                error_log("Emergency fallback also failed: " . $inner_e->getMessage());
            }
        }
        
        throw new Exception("Database error in fetchRow: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("Exception in fetchRow: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Fetch multiple rows from the database
 * 
 * @param string $query The SQL query with placeholders
 * @param array $params An array of parameters to bind
 * @param int $fetchStyle The PDO fetch style
 * @return array The fetched rows
 * @throws Exception If the query execution fails
 */
function fetchRows($query, array $params = [], $fetchStyle = PDO::FETCH_ASSOC) {
    try {
        error_log("fetchRows called with query: " . $query);
        error_log("Parameters: " . print_r($params, true));
        
        $conn = getDbConnection();
        if (!$conn) {
            error_log("Database connection failed in fetchRows");
            throw new Exception("Database connection error in fetchRows");
        }
        
        // Prepare and execute
        error_log("Preparing statement...");
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            error_log("Failed to prepare statement: " . print_r($conn->errorInfo(), true));
            throw new Exception("Failed to prepare query in fetchRows");
        }
        
        // Bind parameters individually, with better type handling
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                // Determine parameter type
                $type = PDO::PARAM_STR;
                if (is_int($value)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $type = PDO::PARAM_NULL;
                }
                
                // Handle both positional (numeric keys) and named parameters
                $param_name = is_numeric($key) ? $key + 1 : $key;
                $stmt->bindValue($param_name, $value, $type);
            }
        }
        
        // Execute the statement
        error_log("Executing statement...");
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("Statement execution failed: " . print_r($stmt->errorInfo(), true));
            
            // Try a simple fallback for schema information queries
            if (strpos($query, 'DESCRIBE') !== false || strpos($query, 'SHOW TABLES') !== false) {
                error_log("Attempting fallback for simple query: " . $query);
                
                try {
                    // For table information queries, try direct query for more compatibility
                    $direct_stmt = $conn->query($query);
                    if ($direct_stmt) {
                        $rows = [];
                        while ($row = $direct_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $rows[] = $row;
                        }
                        
                        error_log("Direct query returned " . count($rows) . " rows");
                        return $rows;
                    }
                } catch (Exception $fallback_e) {
                    error_log("Fallback query failed: " . $fallback_e->getMessage());
                }
            }
            
            throw new Exception("Failed to execute query: " . print_r($stmt->errorInfo(), true));
        }
        
        // Fetch all rows
        $rows = $stmt->fetchAll($fetchStyle);
        error_log("fetchRows returned " . count($rows) . " rows");
        
        return $rows;
    } catch (PDOException $e) {
        error_log("PDO Exception in fetchRows: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("Query was: " . $query);
        error_log("Parameters were: " . print_r($params, true));
        
        // Try a fallback for schema information queries
        if (strpos($query, 'DESCRIBE') !== false || strpos($query, 'SHOW TABLES') !== false) {
            try {
                error_log("Trying emergency fallback query for fetchRows");
                
                // Get a fresh connection
                $conn = get_fallback_connection();
                if ($conn) {
                    // For schema queries, try a direct query without parameters
                    $direct_result = $conn->query($query);
                    if ($direct_result) {
                        return $direct_result->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            } catch (Exception $inner_e) {
                error_log("Emergency fetchRows fallback also failed: " . $inner_e->getMessage());
            }
        }
        
        throw new Exception("Database error in fetchRows: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("Exception in fetchRows: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Execute an update query and return the number of affected rows
 * 
 * @param string $query The SQL query with placeholders
 * @param array $params An array of parameters to bind
 * @return int The number of affected rows
 * @throws Exception If the query execution fails
 */
if (!function_exists('executeUpdate')) {
    function executeUpdate($query, array $params = []) {
        $stmt = executeQuery($query, $params);
        return $stmt->rowCount();
    }
}

/**
 * Begin a transaction
 * 
 * @return bool Whether the transaction was successfully started
 */
if (!function_exists('beginTransaction')) {
    function beginTransaction() {
        $conn = getDbConnection();
        return $conn->beginTransaction();
    }
}

/**
 * Commit the current transaction
 * 
 * @return bool Whether the commit was successful
 */
if (!function_exists('commitTransaction')) {
    function commitTransaction() {
        $conn = getDbConnection();
        return $conn->commit();
    }
}

/**
 * Roll back the current transaction
 * 
 * @return bool Whether the rollback was successful
 */
if (!function_exists('rollbackTransaction')) {
    function rollbackTransaction() {
        $conn = getDbConnection();
        return $conn->rollBack();
    }
}

/**
 * Get the ID of the last inserted row
 * 
 * @return string The last insert ID
 */
function lastInsertId() {
    $conn = getDbConnection();
    return $conn->lastInsertId();
}

/**
 * Check if a table exists in the database
 * 
 * @param string $tableName The name of the table to check
 * @return bool Whether the table exists
 */
if (!function_exists('tableExists')) {
    function tableExists($tableName) {
        try {
            $conn = getDbConnection();
            // MySQL doesn't properly support parameter binding for SHOW TABLES LIKE
            // So we need to escape the table name properly and use direct concatenation
            $escapedTableName = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tableName);
            $query = "SHOW TABLES LIKE '" . $escapedTableName . "'";
            $result = $conn->query($query);
            
            return $result && $result->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Error checking if table exists: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Escape a string for use in a query
 * 
 * @param string $string The string to escape
 * @return string The escaped string
 */
function escapeString($string) {
    if (is_numeric($string)) {
        return $string;
    }
    
    $conn = getDbConnection();
    // PDO doesn't have a direct string escaping method, so we use quote() and strip the quotes
    $quoted = $conn->quote($string);
    return substr($quoted, 1, -1);
} 