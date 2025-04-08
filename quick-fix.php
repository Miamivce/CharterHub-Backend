<?php header("Content-Type: text/plain"); echo "=== QUICK FIX ===
"; try { $pdo = new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); echo "Connected to database
"; $stmt = $pdo->query("SHOW TABLES LIKE \"wp_charterhub_users\""); $table = $stmt->fetchColumn(); if ($table) { $pdo->exec("DROP VIEW IF EXISTS charterhub_users"); $pdo->exec("CREATE VIEW charterhub_users AS SELECT * FROM wp_charterhub_users"); echo "✅ Created view: charterhub_users
"; } else { echo "❌ Table wp_charterhub_users not found
"; } } catch (Exception $e) { echo "❌ ERROR: ".$e->getMessage()."
"; } ?>
