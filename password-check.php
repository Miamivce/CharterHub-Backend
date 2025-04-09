<?php header("Content-Type: text/plain"); echo "=== PASSWORD VERIFICATION TEST ===

"; $host="mysql-charterhub-charterhub.c.aivencloud.com"; $port="19174"; $dbname="defaultdb"; $user="avnadmin"; $pass="AVNS_HCZbm5bZJE1L9C8Pz8C"; try { $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>false]); echo "✅ Connected

"; $testEmail = "admin@example.com"; echo "Testing password verification for: $testEmail
"; $stmt = $pdo->prepare("SELECT id, email, password FROM wp_charterhub_users WHERE email = ?"); $stmt->execute([$testEmail]); $user = $stmt->fetch(PDO::FETCH_ASSOC); if(!$user) { echo "User not found. Checking all emails in system:
"; $all = $pdo->query("SELECT email FROM wp_charterhub_users LIMIT 5")->fetchAll(PDO::FETCH_COLUMN); echo implode(", ", $all) . "
"; } else { echo "User found, Password hash: " . substr($user["password"], 0, 10) . "...
"; $hash_type = ""; if(strpos($user["password"], "$2y$") === 0) { $hash_type = "bcrypt (PHP password_hash)"; } elseif(strpos($user["password"], "$P$") === 0) { $hash_type = "WordPress phpass"; } elseif(strlen($user["password"]) === 32 && ctype_xdigit($user["password"])) { $hash_type = "MD5 (unsalted)"; } elseif(strlen($user["password"]) === 40 && ctype_xdigit($user["password"])) { $hash_type = "SHA1 (unsalted)"; } echo "Hash appears to be: $hash_type
"; echo "
To verify passwords correctly, ensure client-login.php is using:"; if($hash_type === "WordPress phpass") { echo "
- WordPress wp_check_password() or similar"; } else { echo "
- PHP password_verify() function"; } echo "

Test verification with \"password\":"; if($hash_type === "bcrypt (PHP password_hash)") { echo "
- " . (password_verify("password", $user["password"]) ? "✅ Verified" : "❌ Failed"); } } } catch(PDOException $e) { echo "❌ Error: ".$e->getMessage(); }
