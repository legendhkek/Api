<?php
/**
 * Detailed proxy debugging tool
 * Tests a specific proxy with verbose cURL output to diagnose connection issues
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$proxyString = $_GET['proxy'] ?? '46.3.63.7:5433:5K05CT880J2D:VE1MSDRGFDZB';
$testUrl = $_GET['site'] ?? 'https://api.ipify.org?format=json';

echo "<h2>Proxy Debug Tool</h2>";
echo "<p><strong>Testing Proxy:</strong> " . htmlspecialchars($proxyString) . "</p>";
echo "<p><strong>Test URL:</strong> " . htmlspecialchars($testUrl) . "</p>";

// Parse proxy
function parseProxy($proxy) {
    $parts = [];
    // Check for user:pass@host:port format
    if (preg_match('#^(?:([a-z0-9]+)://)?(?:([^:@]+):([^:@]+)@)?([^:]+):(\d+)$#i', $proxy, $m)) {
        $parts['scheme'] = $m[1] ?: 'http';
        $parts['user'] = $m[2] ?: '';
        $parts['pass'] = $m[3] ?: '';
        $parts['ip'] = $m[4];
        $parts['port'] = $m[5];
    }
    // Check for ip:port:user:pass format
    elseif (preg_match('#^([^:]+):(\d+):([^:]+):([^:]+)$#', $proxy, $m)) {
        $parts['scheme'] = 'http';
        $parts['ip'] = $m[1];
        $parts['port'] = $m[2];
        $parts['user'] = $m[3];
        $parts['pass'] = $m[4];
    }
    return $parts;
}

$parsed = parseProxy($proxyString);
if (empty($parsed)) {
    die("<p style='color:red;'>Failed to parse proxy string!</p>");
}

echo "<h3>Parsed Components:</h3>";
echo "<pre>" . print_r($parsed, true) . "</pre>";

// Test with HTTP proxy type
function testProxyWithDebug($ip, $port, $user, $pass, $type, $url) {
    $ch = curl_init($url);
    
    // Basic options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    // Proxy settings
    $proxyAddr = "$ip:$port";
    curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
    
    if ($user !== '' && $pass !== '') {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, "$user:$pass");
    }
    
    // Set proxy type
    switch (strtolower($type)) {
        case 'http':
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            break;
        case 'https':
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            break;
        case 'socks5':
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            break;
        case 'socks5h':
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            break;
        case 'socks4':
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            break;
        case 'socks4a':
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4A);
            break;
    }
    
    // Capture verbose output
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    // Execute
    $start = microtime(true);
    $response = curl_exec($ch);
    $elapsed = microtime(true) - $start;
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    
    // Get verbose output
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);
    
    curl_close($ch);
    
    return [
        'success' => ($httpCode > 0 && $errno === 0),
        'http_code' => $httpCode,
        'error' => $error,
        'errno' => $errno,
        'elapsed' => round($elapsed, 3),
        'response' => substr($response, 0, 500),
        'verbose' => $verboseLog
    ];
}

$types = ['http', 'https', 'socks5', 'socks5h'];

echo "<h3>Testing Each Protocol:</h3>";

foreach ($types as $type) {
    echo "<h4>Testing: " . strtoupper($type) . "</h4>";
    
    $result = testProxyWithDebug(
        $parsed['ip'],
        $parsed['port'],
        $parsed['user'],
        $parsed['pass'],
        $type,
        $testUrl
    );
    
    echo "<p><strong>Success:</strong> " . ($result['success'] ? '✅ YES' : '❌ NO') . "</p>";
    echo "<p><strong>HTTP Code:</strong> " . $result['http_code'] . "</p>";
    echo "<p><strong>Time:</strong> " . $result['elapsed'] . "s</p>";
    
    if ($result['error']) {
        echo "<p style='color:red;'><strong>Error:</strong> " . htmlspecialchars($result['error']) . " (errno: {$result['errno']})</p>";
    }
    
    if ($result['response']) {
        echo "<p><strong>Response:</strong> <code>" . htmlspecialchars($result['response']) . "</code></p>";
    }
    
    echo "<details><summary><strong>Verbose cURL Log (click to expand)</strong></summary>";
    echo "<pre style='background:#f5f5f5;padding:10px;overflow:auto;max-height:400px;'>" . htmlspecialchars($result['verbose']) . "</pre>";
    echo "</details>";
    
    echo "<hr>";
}

echo "<p><em>Debugging complete. Check the logs above for connection details.</em></p>";
?>
