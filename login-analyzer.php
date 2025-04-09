<?php header("Content-Type: text/plain"); echo "=== LOGIN SCRIPT ANALYZER ===

"; if(file_exists("auth/client-login.php")) { echo "Analyzing client-login.php...
"; $content = file_get_contents("auth/client-login.php"); $lines = explode("
", $content); echo "Total lines: " . count($lines) . "

"; // Extract key elements $password_verify = preg_match("/password_verify\(/", $content); $wp_check_password = preg_match("/wp_check_password\(/", $content); echo "Password verification method:
"; echo "- Uses password_verify(): " . ($password_verify ? "YES" : "NO") . "
"; echo "- Uses wp_check_password(): " . ($wp_check_password ? "YES" : "NO") . "

"; $uses_session = preg_match("/session_start\(/", $content); echo "Session handling: " . ($uses_session ? "YES" : "NO") . "

"; echo "Database access method:
"; echo "- PDO: " . (preg_match("/new PDO\(/", $content) ? "YES" : "NO") . "
"; echo "- mysqli: " . (preg_match("/mysqli/", $content) ? "YES" : "NO") . "

"; echo "Key query fragments:
"; preg_match_all("/SELECT.+?FROM.+?WHERE/is", $content, $matches); foreach($matches[0] as $match) { echo "- " . trim(preg_replace("/\s+/", " ", $match)) . "
"; } } else { echo "❌ client-login.php not found"; }
