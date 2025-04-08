<?php
error_reporting(E_ALL); ini_set("display_errors", 1);
header("Content-Type: text/plain");
echo "=== SUPER BASIC TEST ===
";
echo "PHP is working if you can see this
";
echo "Time: " . date("Y-m-d H:i:s") . "
";
echo "PHP Version: " . phpversion() . "
";
file_put_contents("debug-output.log", "Basic test executed at " . date("Y-m-d H:i:s"));
?>
