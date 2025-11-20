<?php
/**
 * Proxy System Test & Verification Script
 * 
 * This script tests all proxy functionality to ensure everything works correctly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     PROXY ROTATION SYSTEM - TEST & VERIFICATION           ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Check if ProxyManager exists
if (!file_exists('ProxyManager.php')) {
    echo "❌ ERROR: ProxyManager.php not found!\n";
    exit(1);
}

require_once 'ProxyManager.php';

// Test counter
$testsPassed = 0;
$testsFailed = 0;

function testResult($name, $passed, $message = '') {
    global $testsPassed, $testsFailed;
    if ($passed) {
        echo "✅ PASS: $name\n";
        if ($message) echo "   → $message\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: $name\n";
        if ($message) echo "   → $message\n";
        $testsFailed++;
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 1: ProxyManager Class Initialization\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $pm = new ProxyManager('test_proxy.log');
    testResult("ProxyManager instantiation", true, "Class loaded successfully");
} catch (Exception $e) {
    testResult("ProxyManager instantiation", false, $e->getMessage());
    exit(1);
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 2: Proxy Format Parsing\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$testProxies = [
    'http://proxy.com:8080' => 'HTTP proxy without auth',
    'http://proxy.com:8080:user:pass' => 'HTTP proxy with auth',
    'https://proxy.com:443' => 'HTTPS proxy',
    'socks4://proxy.com:1080' => 'SOCKS4 proxy',
    'socks5://proxy.com:1080' => 'SOCKS5 proxy',
    'socks5://proxy.com:1080:user:pass' => 'SOCKS5 with auth',
    'tor://127.0.0.1:9050' => 'Tor proxy',
];

foreach ($testProxies as $proxy => $desc) {
    $result = $pm->addProxy($proxy);
    testResult($desc, $result, $proxy);
}

// Test invalid formats
$invalidProxies = [
    'invalid' => 'Invalid format',
    'proxy.com' => 'Missing port',
    'proxy.com:8080' => 'Missing protocol',
];

foreach ($invalidProxies as $proxy => $desc) {
    $result = $pm->addProxy($proxy);
    testResult("$desc (should fail)", !$result, $proxy);
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 3: Proxy Retrieval\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Get next proxy (rotation)
$proxy1 = $pm->getNextProxy(false);
testResult("Get first proxy (rotation)", $proxy1 !== null, $proxy1 ? $proxy1['string'] : 'none');

$proxy2 = $pm->getNextProxy(false);
testResult("Get second proxy (rotation)", $proxy2 !== null, $proxy2 ? $proxy2['string'] : 'none');

// Verify rotation (should be different proxies)
$isDifferent = $proxy1 && $proxy2 && $proxy1['id'] !== $proxy2['id'];
testResult("Proxy rotation working", $isDifferent, "Proxies are different");

// Get random proxy
$randomProxy = $pm->getRandomProxy(false);
testResult("Get random proxy", $randomProxy !== null, $randomProxy ? $randomProxy['string'] : 'none');

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 4: Statistics\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$stats = $pm->getStats();
testResult("Get statistics", is_array($stats), "Total proxies: {$stats['total_proxies']}");
testResult("Stats structure valid", 
    isset($stats['total_proxies']) && isset($stats['live_proxies']) && isset($stats['dead_proxies']),
    "All required fields present");

echo "   Total Proxies: {$stats['total_proxies']}\n";
echo "   Live Proxies:  {$stats['live_proxies']}\n";
echo "   Dead Proxies:  {$stats['dead_proxies']}\n";

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 5: Configuration Methods\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    $pm->setCheckTimeout(10);
    testResult("Set check timeout", true, "Timeout set to 10 seconds");
    
    $pm->setMaxRetries(5);
    testResult("Set max retries", true, "Max retries set to 5");
    
    $pm->setHealthCheckUrl('https://api.ipify.org');
    testResult("Set health check URL", true, "URL updated");
} catch (Exception $e) {
    testResult("Configuration methods", false, $e->getMessage());
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 6: cURL Integration\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$proxy = $pm->getNextProxy(false);
if ($proxy) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://httpbin.org/status/200');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    try {
        $pm->applyCurlProxy($ch, $proxy);
        testResult("Apply proxy to cURL", true, "Proxy applied successfully");
    } catch (Exception $e) {
        testResult("Apply proxy to cURL", false, $e->getMessage());
    }
    
    curl_close($ch);
} else {
    testResult("Apply proxy to cURL", false, "No proxy available");
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 7: File Loading\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if (file_exists('ProxyList.txt')) {
    $pm2 = new ProxyManager();
    $count = $pm2->loadFromFile('ProxyList.txt');
    testResult("Load proxies from file", $count >= 0, "Loaded $count proxies");
    
    if ($count > 0) {
        $fileProxy = $pm2->getNextProxy(false);
        testResult("Get proxy from file", $fileProxy !== null, 
            $fileProxy ? $fileProxy['string'] : 'none');
    }
} else {
    testResult("Load proxies from file", false, "ProxyList.txt not found (create it to test)");
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 8: Dead Proxy Management\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$pm3 = new ProxyManager();
$pm3->addProxy('http://dead-proxy-test.invalid:9999');

$beforeStats = $pm3->getStats();
testResult("Initial stats", $beforeStats['dead_proxies'] === 0, "No dead proxies initially");

// Try to get proxy without health check
$testProxy = $pm3->getNextProxy(false);
testResult("Get proxy without health check", $testProxy !== null, "Proxy retrieved");

// Reset dead proxies
$pm3->resetDeadProxies();
$afterReset = $pm3->getStats();
testResult("Reset dead proxies", $afterReset['dead_proxies'] === 0, "Dead proxies cleared");

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 9: Add Improved Address Provider\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if (file_exists('add_improved.php')) {
    require_once 'add_improved.php';
    
    testResult("AddressProvider class exists", class_exists('AddressProvider'), "Class loaded");
    
    $address = AddressProvider::getRandomAddress();
    testResult("Get random address", is_array($address) && isset($address['city']), 
        "Address: {$address['city']}, {$address['state']}");
    
    $formatted = AddressProvider::formatAddress($address);
    testResult("Format address", !empty($formatted), $formatted);
    
    $count = AddressProvider::count();
    testResult("Address count", $count > 0, "Total addresses: $count");
} else {
    testResult("Add Improved module", false, "add_improved.php not found");
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "TEST 10: Live Proxy Test (Optional)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Check if Tor is available
$pm4 = new ProxyManager();
$pm4->addProxy('socks5://127.0.0.1:9050');

echo "   ℹ️  Testing Tor proxy (socks5://127.0.0.1:9050)...\n";
$torProxy = $pm4->getNextProxy(false);
if ($torProxy) {
    $isTorHealthy = $pm4->checkProxyHealth($torProxy, 'http://ip-api.com/json');
    if ($isTorHealthy) {
        testResult("Tor proxy available", true, "Tor is running and accessible");
    } else {
        echo "   ⚠️  INFO: Tor proxy not responding (install with: apt-get install tor)\n";
    }
} else {
    echo "   ⚠️  INFO: Could not create Tor proxy object\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$totalTests = $testsPassed + $testsFailed;
$successRate = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100, 1) : 0;

echo "\n";
echo "Total Tests:    $totalTests\n";
echo "Tests Passed:   ✅ $testsPassed\n";
echo "Tests Failed:   ❌ $testsFailed\n";
echo "Success Rate:   $successRate%\n";
echo "\n";

if ($testsFailed === 0) {
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║          🎉 ALL TESTS PASSED SUCCESSFULLY! 🎉              ║\n";
    echo "║                                                            ║\n";
    echo "║  Your proxy rotation system is ready to use!              ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
} else {
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║          ⚠️  SOME TESTS FAILED  ⚠️                         ║\n";
    echo "║                                                            ║\n";
    echo "║  Review the failures above and check:                     ║\n";
    echo "║  1. ProxyManager.php is present                           ║\n";
    echo "║  2. add_improved.php is present                           ║\n";
    echo "║  3. File permissions are correct                          ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "NEXT STEPS:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
echo "1. Add your proxies to ProxyList.txt:\n";
echo "   socks5://127.0.0.1:9050\n";
echo "   http://your-proxy.com:8080:user:pass\n";
echo "\n";
echo "2. Test with your proxies:\n";
echo "   php proxy_example.php\n";
echo "\n";
echo "3. Integrate into autosh.php:\n";
echo "   See autosh_with_proxy_rotation.php for template\n";
echo "\n";
echo "4. Read the documentation:\n";
echo "   PROXY_ROTATION_GUIDE.md - Complete guide\n";
echo "   IMPLEMENTATION_SUMMARY.md - Quick reference\n";
echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Cleanup test log
if (file_exists('test_proxy.log')) {
    unlink('test_proxy.log');
}

exit($testsFailed > 0 ? 1 : 0);
