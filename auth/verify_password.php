<?php
/**
 * Verify Password Diagnostic
 * 
 * This script tests if the password 'Test1234567!!!' verifies correctly against the hash stored for test4@me.com.
 *
 * Usage: php backend/auth/verify_password.php
 */

$password = 'Test1234567!!!';
// Updated hash from print_test_user.php
$hash = '$2y$12$TUP9SBK59piQTk/viR6oMe8R7saaiseoOWFsy7klZ71jqYfB0MrGq';

if (password_verify($password, $hash)) {
    echo "Password verification passed\n";
} else {
    echo "Password verification failed\n";
}
?> 