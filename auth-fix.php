<?php error_reporting(E_ALL); ini_set("display_errors", 1); header("Content-Type: text/plain"); echo "=== AUTHENTICATION FIX TOOL ===

"; try { $host = getenv("DB_HOST"); $dbname = getenv("DB_NAME"); $user = getenv("DB_USER"); $password = getenv("DB_PASSWORD"); echo "Connecting to database...
"; $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); echo "Connected successfully

"; echo "Checking auth_logs table...
"; $stmt = $pdo->query("SHOW TABLES LIKE \"wp_charterhub_auth_logs\""); $logs_table = $stmt->fetchColumn(); if ($logs_table) { echo "Found logs table
"; $pdo->exec("ALTER TABLE wp_charterhub_auth_logs MODIFY action VARCHAR(100)"); echo "✅ Fixed action column size
"; } echo "
Creating views for authentication tables...
"; $pdo->exec("DROP VIEW IF EXISTS charterhub_users"); $pdo->exec("CREATE VIEW charterhub_users AS SELECT * FROM wp_charterhub_users"); echo "✅ Created charterhub_users view
"; echo "
=== FIX COMPLETE ===
"; } catch (Exception $e) { echo "❌ ERROR: " . $e->getMessage() . "
"; } ?>
