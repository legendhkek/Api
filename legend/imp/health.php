<?php
// Simple environment health check
header('Content-Type: text/plain');

echo "PHP Version: ".PHP_VERSION."\n";

// Check php.ini
$loaded = php_ini_loaded_file();
echo 'Loaded php.ini: '.($loaded ? $loaded : '(none)')."\n";
echo 'Extensions dir: '.ini_get('extension_dir')."\n";

// Check required extensions
$required = ['curl','json'];
foreach ($required as $ext) {
    echo sprintf("Extension %-8s: %s\n", $ext, extension_loaded($ext) ? 'ENABLED' : 'MISSING');
}

// Check file permissions for logs/lists
$files = ['ProxyList.txt','cc_responses.txt','proxy_log.txt','cookie.txt'];
foreach ($files as $f) {
    $status = file_exists($f) ? 'exists' : 'missing';
    $writable = is_writable(dirname(__FILE__)) ? 'dir-writable' : 'dir-not-writable';
    $fw = file_exists($f) ? (is_writable($f) ? 'file-writable' : 'file-readonly') : 'file-na';
    echo sprintf("%-20s: %-7s | %-14s | %-14s\n", $f, $status, $writable, $fw);
}

// Quick cURL probe
if (function_exists('curl_version')) {
    $cv = curl_version();
    echo 'cURL version: '.($cv['version'] ?? 'unknown')."\n";
    echo 'libcurl: '.($cv['ssl_version'] ?? 'unknown')."\n";
}

echo "\nTry: /fetch_proxies.php or /autosh.php?site=https://examplestore.com&cc=4111111111111111|12|2028|123\n";
