<?php header("Content-Type: text/plain"); echo "=== SIMPLE TABLE FIX ===

"; $host="mysql-charterhub-charterhub.c.aivencloud.com"; $port="19174"; $dbname="defaultdb"; $user="avnadmin"; $pass="AVNS_HCZbm5bZJE1L9C8Pz8C"; try { $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>false]); echo "✅ Connected

"; echo "Examining wp_charterhub_users structure...
"; $columns = $pdo->query("SHOW COLUMNS FROM wp_charterhub_users")->fetchAll(PDO::FETCH_ASSOC); $has_password = false; $password_column = null; foreach($columns as $col) { if($col["Field"] == "password") { $has_password = true; } if(stripos($col["Field"], "pass") !== false) { $password_column = $col["Field"]; } echo "- " . $col["Field"] . " (" . $col["Type"] . ")
"; } if($has_password) { echo "
Table already has password column, no view needed."; } else if($password_column) { echo "
Creating view with password column alias...
"; $pdo->exec("DROP VIEW IF EXISTS charterhub_users"); $view_sql = "CREATE VIEW charterhub_users AS SELECT "; $first = true; foreach($columns as $col) { if(!$first) $view_sql .= ", "; $first = false; if($col["Field"] == $password_column) { $view_sql .= "`" . $col["Field"] . "` AS password"; } else { $view_sql .= "`" . $col["Field"] . "`"; } } $view_sql .= " FROM wp_charterhub_users"; $pdo->exec($view_sql); echo "✅ View created successfully"; } else { echo "
❌ No password column found"; } } catch(PDOException $e) { echo "❌ Error: ".$e->getMessage(); }
