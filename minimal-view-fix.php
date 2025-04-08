<?php header("Content-Type: text/plain"); echo "=== MINIMAL VIEW FIX ===

"; $host="mysql-charterhub-charterhub.c.aivencloud.com"; $port="19174"; $dbname="defaultdb"; $user="avnadmin"; $pass="AVNS_HCZbm5bZJE1L9C8Pz8C"; try { $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>false]); echo "✅ Connected
"; echo "Creating user table view with password column alias...
"; $pdo->exec("CREATE OR REPLACE VIEW charterhub_users AS SELECT id, email, user_pass AS password, first_name, last_name, display_name, role, phone_number, company, country, address, notes, verified, token_version, created_at, updated_at FROM wp_charterhub_users"); echo "✅ View created with password column alias"; } catch(PDOException $e) { echo "❌ Error: ".$e->getMessage(); }
