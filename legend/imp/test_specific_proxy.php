<?php
/**
 * Test a specific proxy to diagnose issues
 */

// Performance optimization
ob_implicit_flush(true);

$proxyIP = $_GET['proxy'] ?? '4.156.78.45:80';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Proxy Tester</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 { color: #667eea; margin-bottom: 20px; }
        .test-box {
            background: #f5f5f5;
            border-radius: 10px;
            padding: 20px;
            margin: 15px 0;
        }
        .success { color: #4caf50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .info { color: #2196f3; }
        pre {
            background: #1e1e1e;
            color: #0f0;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 15px;
        }
        .stat {
            display: inline-block;
            margin: 5px 10px 5px 0;
            padding: 5px 10px;
            background: #e3f2fd;
            border-radius: 5px;
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 Proxy Diagnostic Test</h1>
    <p>Testing proxy: <strong>$proxyIP</strong></p>
";

flush();

/**
 * Test proxy with different configurations
 */
function testProxyConfig($proxy, $config) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROXY => $proxy,
        CURLOPT_PROXYTYPE => $config['type'],
        CURLOPT_HTTPPROXYTUNNEL => $config['tunnel'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_ENCODING => '', // Enable compression
        CURLOPT_TCP_NODELAY => true, // Speed optimization
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $elapsed = round(microtime(true) - $startTime, 3);
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return [
        'success' => ($response !== false && $httpCode >= 200 && $httpCode < 400),
        'response' => $response,
        'http_code' => $httpCode,
        'error' => $curlError,
        'errno' => $curlErrno,
        'time' => $elapsed,
        'info' => $info
    ];
}

// Test configurations
$configs = [
    [
        'name' => 'HTTP URL without tunnel (Recommended)',
        'url' => 'http://ip-api.com/json',
        'type' => CURLPROXY_HTTP,
        'tunnel' => false
    ],
    [
        'name' => 'HTTP URL with tunnel',
        'url' => 'http://ip-api.com/json',
        'type' => CURLPROXY_HTTP,
        'tunnel' => true
    ],
    [
        'name' => 'HTTPS URL without tunnel',
        'url' => 'https://api.ipify.org?format=json',
        'type' => CURLPROXY_HTTP,
        'tunnel' => false
    ],
    [
        'name' => 'HTTPS URL with tunnel',
        'url' => 'https://api.ipify.org?format=json',
        'type' => CURLPROXY_HTTP,
        'tunnel' => true
    ]
];

echo "<h2>📊 Test Results:</h2>";

foreach ($configs as $config) {
    echo "<div class='test-box'>";
    echo "<h3>{$config['name']}</h3>";
    echo "<div class='info'>Testing {$config['url']} via $proxyIP...</div>";
    flush();
    
    $result = testProxyConfig($proxyIP, $config);
    
    if ($result['success']) {
        echo "<div class='success'>✓ SUCCESS</div>";
        echo "<div class='stat'>HTTP Code: {$result['http_code']}</div>";
        echo "<div class='stat'>Time: {$result['time']}s</div>";
        
        $data = json_decode($result['response'], true);
        if ($data) {
            echo "<pre>";
            echo "Proxy IP: " . ($data['query'] ?? $data['ip'] ?? 'N/A') . "\n";
            if (isset($data['country'])) echo "Country: {$data['country']}\n";
            if (isset($data['city'])) echo "City: {$data['city']}\n";
            if (isset($data['isp'])) echo "ISP: {$data['isp']}\n";
            echo "</pre>";
        }
    } else {
        echo "<div class='error'>✗ FAILED</div>";
        echo "<div class='stat'>HTTP Code: {$result['http_code']}</div>";
        echo "<div class='stat'>Errno: {$result['errno']}</div>";
        echo "<div class='stat'>Time: {$result['time']}s</div>";
        echo "<div class='error'>Error: {$result['error']}</div>";
    }
    
    echo "</div>";
    flush();
}

// Recommendation
echo "<div class='test-box' style='background: #e8f5e9; border: 2px solid #4caf50;'>";
echo "<h3>💡 Recommendation</h3>";
echo "<p><strong>For this proxy type:</strong></p>";
echo "<ul>";
echo "<li>Use <code>CURLOPT_HTTPPROXYTUNNEL = false</code> (disable CONNECT tunnel)</li>";
echo "<li>Prefer HTTP URLs over HTTPS when possible</li>";
echo "<li>If HTTPS required, test both tunnel settings</li>";
echo "<li>This proxy appears to be a simple HTTP proxy without SSL tunnel support</li>";
echo "</ul>";
echo "</div>";

echo "<div class='test-box'>";
echo "<h3>🔧 Fixed Configuration</h3>";
echo "<pre>";
echo "\$ch = curl_init();\n";
echo "curl_setopt(\$ch, CURLOPT_URL, 'http://example.com'); // Use HTTP\n";
echo "curl_setopt(\$ch, CURLOPT_PROXY, '$proxyIP');\n";
echo "curl_setopt(\$ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);\n";
echo "curl_setopt(\$ch, CURLOPT_HTTPPROXYTUNNEL, false); // KEY FIX\n";
echo "curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);\n";
echo "curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);\n";
echo "curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, false);\n";
echo "\$response = curl_exec(\$ch);\n";
echo "</pre>";
echo "</div>";

echo "<a href='/' class='btn'>← Back to Dashboard</a>";
echo "<a href='?proxy=$proxyIP' class='btn' style='background: #95a5a6;'>🔄 Retest</a>";

echo "</div></body></html>";
?>
