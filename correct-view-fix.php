<?php header("Content-Type: text/plain"); echo "=== CORRECT VIEW FIX ===

"; $host="mysql-charterhub-charterhub.c.aivencloud.com"; $port="19174"; $dbname="defaultdb"; $user="avnadmin"; $pass="AVNS_HCZbm5bZJE1L9C8Pz8C"; try { $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>false]); echo "✅ Connected

"; echo "STEP 1: Finding user tables...
"; $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN); $found = false; foreach($tables as $table) { if(stripos($table, "user") !== false) { echo "Found user table: $table
"; $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); $passwd_col = null; foreach($columns as $col) { if(stripos($col, "pass") !== false) { $passwd_col = $col; break; } } if($passwd_col) { echo "Found password column: $passwd_col
"; echo "Creating view with password alias...
"; $pdo->exec("DROP VIEW IF EXISTS charterhub_users"); $view_sql = "CREATE VIEW charterhub_users AS SELECT "; $first = true; foreach($columns as $col) { if(!$first) $view_sql .= ", "; $first = false; if($col == $passwd_col) { $view_sql .= "`$col` AS password"; } else { $view_sql .= "`$col`"; } } $view_sql .= " FROM `$table`"; $pdo->exec($view_sql); echo "✅ View created successfully"; $found = true; break; } } } if(!$found) { echo "No suitable user table found"; } } catch(PDOException $e) { echo "❌ Error: ".$e->getMessage(); }
