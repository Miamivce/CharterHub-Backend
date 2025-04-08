<?php header("Content-Type: text/plain"); echo "=== MINIMAL AUTH CHECK ===

"; $host="mysql-charterhub-charterhub.c.aivencloud.com"; $port="19174"; $dbname="defaultdb"; $user="avnadmin"; $pass="AVNS_HCZbm5bZJE1L9C8Pz8C"; try { $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>false]); echo "✅ Connected
"; $stmt = $pdo->query("SHOW TABLES LIKE \"wp_charterhub_users\""); echo "wp_charterhub_users exists: ".($stmt->rowCount()>0?"YES":"NO")."
"; if($stmt->rowCount()>0) { $columns = $pdo->query("DESCRIBE wp_charterhub_users")->fetchAll(PDO::FETCH_COLUMN); echo "Columns: ".implode(", ", $columns)."
"; } } catch(PDOException $e) { echo "❌ Error: ".$e->getMessage(); }
