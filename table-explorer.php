<?php header("Content-Type: text/plain"); echo "=== TABLE EXPLORER ===

"; $host="mysql-charterhub-charterhub.c.aivencloud.com"; $port="19174"; $dbname="defaultdb"; $user="avnadmin"; $pass="AVNS_HCZbm5bZJE1L9C8Pz8C"; try { $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>false]); echo "✅ Connected

"; echo "TABLES:
"; $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN); foreach($tables as $table) { echo "- $table
"; } echo "
USER TABLES DETAILS:
"; $user_tables = ["wp_charterhub_users", "charterhub_users", "wp_users", "users"]; foreach($user_tables as $table) { $stmt = $pdo->query("SHOW TABLES LIKE \"$table\""); if($stmt->rowCount() > 0) { echo "
$table: "; $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); echo "["; foreach($columns as $i => $col) { echo ($i > 0 ? ", " : "") . $col["Field"]; } echo "]
"; } } } catch(PDOException $e) { echo "❌ Error: ".$e->getMessage(); }
