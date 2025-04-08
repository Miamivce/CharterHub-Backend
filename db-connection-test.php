<?php header("Content-Type: text/plain"); echo "=== DB CONNECTION TEST ===
"; try { $pdo = new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]); echo "✅ Connected to database
"; $stmt = $pdo->query("SHOW TABLES LIKE \"wp_%\""); $tables = $stmt->fetchAll(PDO::FETCH_COLUMN); echo "Found ".count($tables)." tables with wp_ prefix
"; } catch (Exception $e) { echo "❌ ERROR: ".$e->getMessage()."
"; } ?>
