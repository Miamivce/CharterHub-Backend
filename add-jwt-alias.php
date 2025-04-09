<?php
// Ultra minimal script that just adds the generate_jwt function to jwt-core.php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Production path on Render
$jwtCorePath = '/var/www/auth/jwt-core.php';

echo "Attempting to fix JWT token generation...<br>";

if (file_exists($jwtCorePath)) {
    echo "Found JWT core file at: $jwtCorePath<br>";
    
    // Add function without reading the file first (to minimize potential issues)
    $aliasFunction = "\n\n/**\n * Alias for generate_access_token for backward compatibility\n */\nfunction generate_jwt(\$user_id, \$role, \$email, \$token_version = 1) {\n    return generate_access_token(\$user_id, \$role, \$email, \$token_version);\n}\n";
    
    // Append to file
    $result = file_put_contents($jwtCorePath, $aliasFunction, FILE_APPEND);
    
    if ($result !== false) {
        echo "SUCCESS: Added generate_jwt function to jwt-core.php<br>";
        echo "You should now be able to log in successfully.<br>";
    } else {
        echo "ERROR: Failed to write to jwt-core.php. Check file permissions.<br>";
    }
} else {
    echo "ERROR: JWT core file not found at: $jwtCorePath<br>";
}
?> 