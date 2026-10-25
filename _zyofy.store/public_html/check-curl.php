<?php
header('Content-Type: text/plain');

echo 'PHP Version: ' . PHP_VERSION . PHP_EOL;
echo 'cURL extension: ' .
    (extension_loaded('curl') ? 'ENABLED' : 'DISABLED') . PHP_EOL;
echo 'curl_init(): ' .
    (function_exists('curl_init') ? 'AVAILABLE' : 'NOT AVAILABLE') . PHP_EOL;