<?php
/**
 * Quick Proxy Tester
 * Tests a proxy with multiple protocols to see which one works
 * 
 * Usage: test_my_proxy.php?proxy=ip:port:user:pass
 */

header('Content-Type: application/json');

if (!isset($_GET['proxy']) || empty($_GET['proxy'])) {
    echo json_encode([
        'error' => 'Missing proxy parameter',
        'usage' => 'test_my_proxy.php?proxy=ip:port:user:pass',
        'examples' => [
            'With auth: test_my_proxy.php?proxy=46.3.63.7:5433:username:password',
            'No auth: test_my_proxy.php?proxy=1.2.3.4:8080',
            'With scheme: test_my_proxy.php?proxy=socks5://1.2.3.4:1080:user:pass'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

$proxyString = $_GET['proxy'];
$results = [];

// Parse proxy
$type = 'http'; // default
$proxyAddr = $proxyString;

// Check for scheme
if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/', $proxyString, $m)) {
    $type = strtolower($m[1]);
    $proxyAddr = $m[2];
    $results['detected_scheme'] = $type;
}

// Parse ip:port:user:pass
$parts = explode(':', $proxyAddr);
if (count($parts) < 2) {
    echo json_encode([
        'error' => 'Invalid proxy format',
        'received' => $proxyString,
        'expected' => 'ip:port or ip:port:user:pass'
    ], JSON_PRETTY_PRINT);
    exit;
}

$ip = $parts[0];
$port = $parts[1];
$user = isset($parts[2]) ? $parts[2] : '';
$pass = isset($parts[3]) ? $parts[3] : '';

$results['parsed'] = [
    'ip' => $ip,
    'port' => $port,
    'has_auth' => !empty($user) && !empty($pass),
    'username' => $user ? substr($user, 0, 3) . '***' : 'none',
    'password' => $pass ? '***' : 'none'
];

// Test URLs
$testUrls = [
    'http' => 'http://api.ipify.org?format=json',
    'https' => 'https://api.ipify.org?format=json',
    'shopify' => 'https://uvahs.myshopify.com'
];

// Proxy types to test
$typesToTest = ['http', 'https', 'socks4', 'socks5'];

// If scheme was specified, test only that type first
if (preg_match('/^(https?|socks4|socks5):\/\//', $proxyString)) {
    $typesToTest = array_unique(array_merge([$type], $typesToTest));
}

$results['tests'] = [];

foreach ($typesToTest as $proxyType) {
    $results['tests'][$proxyType] = [];
    
    foreach ($testUrls as $urlName => $testUrl) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        
        // Set proxy
        curl_setopt($ch, CURLOPT_PROXY, $ip . ':' . $port);
        
        // Set proxy type
        if ($proxyType === 'socks4') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        } elseif ($proxyType === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } elseif ($proxyType === 'https') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, defined('CURLPROXY_HTTPS') ? CURLPROXY_HTTPS : CURLPROXY_HTTP);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
        
        // For HTTP/HTTPS proxies connecting to HTTPS, use CONNECT tunnel
        if (($proxyType === 'http' || $proxyType === 'https') && parse_url($testUrl, PHP_URL_SCHEME) === 'https') {
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        
        // Set auth if provided
        if (!empty($user) && !empty($pass)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $user . ':' . $pass);
        }
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = round((microtime(true) - $startTime) * 1000); // ms
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        $success = ($response !== false && $httpCode >= 200 && $httpCode < 400);
        
        $testResult = [
            'url' => $urlName,
            'success' => $success,
            'http_code' => $httpCode,
            'duration_ms' => $duration,
        ];
        
        if ($success && $urlName === 'http' || $urlName === 'https') {
            $data = @json_decode($response, true);
            if ($data && isset($data['ip'])) {
                $testResult['proxy_ip'] = $data['ip'];
            }
        }
        
        if (!$success) {
            $testResult['error'] = $error ?: 'Unknown error';
            $testResult['error_code'] = $errno;
        }
        
        curl_close($ch);
        
        $results['tests'][$proxyType][$urlName] = $testResult;
    }
    
    // Calculate success rate for this proxy type
    $successes = 0;
    $total = count($testUrls);
    foreach ($results['tests'][$proxyType] as $test) {
        if ($test['success']) $successes++;
    }
    $results['tests'][$proxyType]['success_rate'] = round(($successes / $total) * 100, 1) . '%';
    $results['tests'][$proxyType]['working'] = $successes > 0;
}

// Recommendation
$workingTypes = [];
foreach ($results['tests'] as $ptype => $tests) {
    if ($tests['working']) {
        $workingTypes[] = $ptype;
    }
}

if (!empty($workingTypes)) {
    $results['recommendation'] = [
        'status' => '✅ Proxy is working!',
        'working_protocols' => $workingTypes,
        'best_protocol' => $workingTypes[0],
        'usage_example' => 'autosh.php?proxy=' . $workingTypes[0] . '://' . $ip . ':' . $port . ($user ? ':' . $user . ':' . $pass : '')
    ];
} else {
    $results['recommendation'] = [
        'status' => '❌ Proxy is not working',
        'reason' => 'All protocol types failed',
        'suggestions' => [
            'Check if proxy is online',
            'Verify credentials are correct',
            'Check if proxy IP/port is correct',
            'Try a different proxy',
            'Use ?noproxy parameter to bypass proxy'
        ]
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT);
