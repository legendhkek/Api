<?php
/**
 * Test Proxy Connection
 * Tests if a proxy is working properly
 */

header('Content-Type: application/json');

$proxy = $_GET['proxy'] ?? '';

if (empty($proxy)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Proxy parameter required. Format: IP:PORT:USER:PASS or http://IP:PORT'
    ]);
    exit;
}

// Parse proxy
$parts = explode(':', str_replace(['http://', 'https://', 'socks5://', 'socks4://'], '', $proxy));

$ip = $parts[0] ?? '';
$port = $parts[1] ?? '';
$user = $parts[2] ?? '';
$pass = $parts[3] ?? '';

if (empty($ip) || empty($port)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid proxy format'
    ]);
    exit;
}

echo json_encode([
    'status' => 'info',
    'testing' => [
        'ip' => $ip,
        'port' => $port,
        'user' => !empty($user) ? 'YES' : 'NO',
        'pass' => !empty($pass) ? 'YES' : 'NO'
    ]
]) . "\n\n";
flush();

// Test 1: Basic connectivity
echo "Testing proxy connectivity...\n";
flush();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://ip-api.com/json');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_PROXY, "$ip:$port");
curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);

if (!empty($user) && !empty($pass)) {
    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
}

curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$startTime = microtime(true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
$elapsed = round(microtime(true) - $startTime, 2);
curl_close($ch);

if ($response !== false && $httpCode == 200) {
    $data = json_decode($response, true);
    echo json_encode([
        'status' => 'success',
        'message' => 'Proxy is working!',
        'response_time' => $elapsed . 's',
        'http_code' => $httpCode,
        'proxy_ip' => $data['query'] ?? 'unknown',
        'country' => $data['country'] ?? 'unknown',
        'city' => $data['city'] ?? 'unknown',
        'isp' => $data['isp'] ?? 'unknown',
        'timezone' => $data['timezone'] ?? 'unknown'
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Proxy connection failed',
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'curl_errno' => $curlErrno,
        'response_time' => $elapsed . 's',
        'debug' => [
            'proxy_string' => "$ip:$port",
            'auth' => !empty($user) ? 'enabled' : 'disabled'
        ]
    ], JSON_PRETTY_PRINT);
}
