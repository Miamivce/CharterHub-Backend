<?php
// Simple script to create views for tables with wp_ prefix
header('Content-Type: text/plain');
echo "=== CHARTERHUB TABLE PREFIX FIX ===\n\n";

try {
    // Connect directly to database
    $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), 
                  getenv('DB_USER'), getenv('DB_PASSWORD'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database\n";
    
    // Get all tables with wp_charterhub_ prefix
    $stmt = $pdo->query("SHOW TABLES LIKE 'wp_charterhub_%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found " . count($tables) . " tables with wp_charterhub_ prefix\n";
    
    // Create views without the prefix
    foreach ($tables as $table) {
        $viewName = str_replace('wp_charterhub_', 'charterhub_', $table);
        
        // Drop view if exists
        $pdo->exec("DROP VIEW IF EXISTS `{$viewName}`");
        
        // Create view
        $pdo->exec("CREATE VIEW `{$viewName}` AS SELECT * FROM `{$table}`");
        
        echo "Created view '$viewName' -> '$table'\n";
    }
    
    echo "\nALL VIEWS CREATED SUCCESSFULLY\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
