<?php
/**
 * ProxyManager Usage Examples
 * 
 * This file demonstrates how to use the ProxyManager class
 */

require_once 'ProxyManager.php';

// ============================================
// Example 1: Basic Setup with Proxy List
// ============================================
echo "=== Example 1: Load Proxies from File ===\n";

$proxyManager = new ProxyManager('proxy_debug.log');
$count = $proxyManager->loadFromFile('ProxyList.txt');
echo "Loaded $count proxies\n\n";

// ============================================
// Example 2: Add Proxies Manually
// ============================================
echo "=== Example 2: Add Proxies Manually ===\n";

$proxyManager2 = new ProxyManager();
$proxies = [
    'http://proxy1.example.com:8080',
    'https://proxy2.example.com:443:user:pass',
    'socks5://proxy3.example.com:1080',
    'socks4://proxy4.example.com:1080',
    'tor://127.0.0.1:9050', // Local Tor
];

$added = $proxyManager2->addProxies($proxies);
echo "Added $added proxies\n\n";

// ============================================
// Example 3: Get Next Proxy (Rotation)
// ============================================
echo "=== Example 3: Proxy Rotation ===\n";

$proxy1 = $proxyManager2->getNextProxy(false); // false = skip health check
if ($proxy1) {
    echo "First proxy: {$proxy1['string']}\n";
}

$proxy2 = $proxyManager2->getNextProxy(false);
if ($proxy2) {
    echo "Second proxy: {$proxy2['string']}\n";
}

$proxy3 = $proxyManager2->getNextProxy(false);
if ($proxy3) {
    echo "Third proxy: {$proxy3['string']}\n";
}
echo "\n";

// ============================================
// Example 4: Get Random Proxy
// ============================================
echo "=== Example 4: Random Proxy Selection ===\n";

$randomProxy = $proxyManager2->getRandomProxy(false);
if ($randomProxy) {
    echo "Random proxy: {$randomProxy['string']}\n";
}
echo "\n";

// ============================================
// Example 5: Check Proxy Health
// ============================================
echo "=== Example 5: Proxy Health Check ===\n";

// Example with real test (requires working proxy)
if ($proxy1) {
    $isHealthy = $proxyManager2->checkProxyHealth($proxy1);
    echo "Proxy {$proxy1['string']} is " . ($isHealthy ? "LIVE" : "DEAD") . "\n";
}
echo "\n";

// ============================================
// Example 6: Use Proxy with cURL
// ============================================
echo "=== Example 6: Using Proxy with cURL ===\n";

$proxy = $proxyManager2->getNextProxy(false);
if ($proxy) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://httpbin.org/ip");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Apply proxy to cURL
    $proxyManager2->applyCurlProxy($ch, $proxy);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n";
}
echo "\n";

// ============================================
// Example 7: Auto-Retry with Rotation
// ============================================
echo "=== Example 7: Auto-Retry with Rotation ===\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://httpbin.org/ip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// This will automatically rotate proxies on failure
$result = $proxyManager2->executeWithRotation($ch, true);

echo "HTTP Code: {$result['http_code']}\n";
echo "Proxy Used: " . ($result['proxy_used'] ? "YES" : "NO") . "\n";
if ($result['proxy']) {
    echo "Proxy: {$result['proxy']['string']}\n";
}
curl_close($ch);
echo "\n";

// ============================================
// Example 8: Get Statistics
// ============================================
echo "=== Example 8: Proxy Statistics ===\n";

$stats = $proxyManager2->getStats();
echo "Total Proxies: {$stats['total_proxies']}\n";
echo "Live Proxies: {$stats['live_proxies']}\n";
echo "Dead Proxies: {$stats['dead_proxies']}\n";
echo "\nDetailed Stats:\n";
print_r($stats['proxy_details']);
echo "\n";

// ============================================
// Example 9: Configure Settings
// ============================================
echo "=== Example 9: Configure Settings ===\n";

$proxyManager2->setCheckTimeout(10); // 10 seconds timeout
$proxyManager2->setMaxRetries(5); // 5 retry attempts
$proxyManager2->setHealthCheckUrl("https://api.ipify.org"); // Custom health check
echo "Settings updated\n\n";

// ============================================
// Example 10: Reset Dead Proxies
// ============================================
echo "=== Example 10: Reset Dead Proxies ===\n";

$proxyManager2->resetDeadProxies();
echo "All proxies re-enabled\n\n";

// ============================================
// Example 11: Complete Workflow
// ============================================
echo "=== Example 11: Complete Workflow ===\n";

// Initialize
$pm = new ProxyManager();
$pm->addProxies([
    'socks5://127.0.0.1:9050', // Tor
    'http://my-proxy.com:8080:user:pass'
]);

// Get working proxy
$workingProxy = $pm->getNextProxy(true); // true = check health
if ($workingProxy) {
    echo "Using proxy: {$workingProxy['string']}\n";
    
    // Make request
    $ch = curl_init("https://api.ipify.org?format=json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $pm->applyCurlProxy($ch, $workingProxy);
    
    $response = curl_exec($ch);
    echo "My IP via proxy: $response\n";
    curl_close($ch);
} else {
    echo "No working proxy available\n";
}

echo "\n=== Examples Complete ===\n";
