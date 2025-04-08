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
require_once dirname(__FILE__) . '/../auth/config.php';

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
            
            // Test the connection
            $connection->query("SELECT 1");
            error_log("Database connection created successfully");
        } catch (PDOException $e) {
            error_log("PDO Exception in getDbConnection: " . $e->getMessage());
            // Re-throw to be handled by caller
            throw new Exception("Database connection error: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Exception in getDbConnection: " . $e->getMessage());
            throw $e;
        }
    }
    
    return $connection;
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
            error_log("Binding " . count($params) . " parameters");
            foreach ($params as $index => $value) {
                $paramType = PDO::PARAM_STR; // Default type
                if (is_int($value)) {
                    $paramType = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $paramType = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $paramType = PDO::PARAM_NULL;
                }
                
                // PDO parameter positions are 1-based
                $position = is_string($index) ? $index : $index + 1;
                error_log("Binding parameter at position $position with value: " . (is_array($value) ? json_encode($value) : $value));
                $stmt->bindValue($position, $value, $paramType);
            }
        }
        
        // Execute the statement
        error_log("Executing statement...");
        $result = $stmt->execute();
        if (!$result) {
            error_log("Statement execution failed: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Failed to execute query in fetchRow: " . $stmt->errorInfo()[2]);
        }
        
        // Fetch the row
        error_log("Fetching result...");
        $row = $stmt->fetch($fetchStyle);
        
        if ($row === false) {
            error_log("No row found, checking for errors...");
            if ($stmt->errorCode() !== '00000') {
                error_log("Error in fetchRow: " . print_r($stmt->errorInfo(), true));
                throw new Exception("Error fetching row: " . $stmt->errorInfo()[2]);
            }
            error_log("No row found but no error occurred (possibly empty result set)");
        } else {
            error_log("Row found successfully");
        }
        
        return $row;
    } catch (PDOException $e) {
        error_log("PDO Exception in fetchRow: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        error_log("Query was: " . $query);
        error_log("Parameters were: " . print_r($params, true));
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
function fetchAll($query, array $params = [], $fetchStyle = PDO::FETCH_ASSOC) {
    $stmt = executeQuery($query, $params);
    return $stmt->fetchAll($fetchStyle);
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