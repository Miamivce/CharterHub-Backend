<?php
// ULTRA SIMPLE SCRIPT - just adds the JWT function

// The JWT function we need to add
$function = "\n\nfunction generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n    return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n}\n";

// Try both possible locations
$path = '/var/www/auth/jwt-core.php';
file_put_contents($path, $function, FILE_APPEND);

echo "Function added to $path";
?> 