<?php header("Content-Type: text/plain"); echo "Error log location: " . ini_get("error_log") . "
"; $log = ini_get("error_log") ? file_get_contents(ini_get("error_log")) : "No error log configured"; echo substr($log, -2000); ?>
