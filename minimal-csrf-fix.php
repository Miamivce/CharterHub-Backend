<?php header("Content-Type: text/plain"); echo "=== MINIMAL CSRF FIX ===

"; if(file_exists("auth/csrf-token.php")) { $new_content = "<?php header(\"Content-Type: application/json\"); include_once __DIR__ . \"/global-cors.php\"; session_start(); if(!isset(\$_SESSION[\"csrf_token\"])) { \$_SESSION[\"csrf_token\"] = bin2hex(random_bytes(32)); } echo json_encode([\"success\" => true, \"token\" => \$_SESSION[\"csrf_token\"]]); "; file_put_contents("auth/csrf-token.php", $new_content); echo "✅ CSRF token file updated"; } else { echo "❌ CSRF token file not found"; }
