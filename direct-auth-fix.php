<?php header("Content-Type: text/plain"); echo "=== DIRECT AUTH FIX ===

"; try { $host = getenv("DB_HOST"); echo "Testing connection to $host
"; $socket = @fsockopen($host, 3306, $errno, $errstr, 5); if (!$socket) { echo "❌ TCP connection failed: $errstr ($errno)
"; } else { echo "✅ TCP connection successful
"; fclose($socket); } $dsn = "mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_NAME").";charset=utf8mb4"; $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]; echo "Connecting to database...
"; $pdo = new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD"), $options); echo "✅ Connected to database
"; $pdo->exec("DROP VIEW IF EXISTS charterhub_users"); $pdo->exec("CREATE VIEW charterhub_users AS SELECT * FROM wp_charterhub_users"); echo "✅ Created charterhub_users view
"; } catch (Exception $e) { echo "❌ ERROR: ".$e->getMessage()."
"; } ?>
