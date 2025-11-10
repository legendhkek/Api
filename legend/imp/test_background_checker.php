<?php
/**
 * Test Script for Background Proxy Checker
 * 
 * Tests all components before running in production
 */

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     BACKGROUND PROXY CHECKER - TEST SUITE                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$tests = [];
$passed = 0;
$failed = 0;

// Test 1: Check required files
echo "🔍 Test 1: Checking required files...\n";
$requiredFiles = [
    'background_proxy_checker.php',
    'ProxyManager.php',
    'TelegramNotifier.php',
    'ProxyAnalytics.php',
    'ProxyList.txt'
];

foreach ($requiredFiles as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "   ✅ $file found\n";
        $tests['files'][$file] = true;
    } else {
        echo "   ❌ $file NOT FOUND\n";
        $tests['files'][$file] = false;
        $failed++;
    }
}

if (!in_array(false, $tests['files'])) {
    $passed++;
    echo "   ✅ All required files present\n\n";
} else {
    echo "   ❌ Some files are missing\n\n";
}

// Test 2: Check PHP extensions
echo "🔍 Test 2: Checking PHP extensions...\n";
$requiredExtensions = ['curl', 'json', 'mbstring'];
$extensionTests = [];

foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "   ✅ $ext extension loaded\n";
        $extensionTests[$ext] = true;
    } else {
        echo "   ❌ $ext extension NOT loaded\n";
        $extensionTests[$ext] = false;
        $failed++;
    }
}

if (!in_array(false, $extensionTests)) {
    $passed++;
    echo "   ✅ All required extensions loaded\n\n";
} else {
    echo "   ❌ Some extensions are missing\n\n";
}

// Test 3: Check Telegram configuration
echo "🔍 Test 3: Checking Telegram configuration...\n";
$botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN');
$chatId = $_ENV['TELEGRAM_CHAT_ID'] ?? getenv('TELEGRAM_CHAT_ID');

if (!empty($botToken) && !empty($chatId)) {
    echo "   ✅ Telegram credentials configured\n";
    
    // Test connection
    require_once __DIR__ . '/TelegramNotifier.php';
    $telegram = new TelegramNotifier($botToken, $chatId);
    
    echo "   📤 Testing Telegram connection...\n";
    $testResult = $telegram->sendMessage("🧪 <b>Test Message</b>\n\nBackground Proxy Checker test at " . date('Y-m-d H:i:s'));
    
    if ($testResult) {
        echo "   ✅ Telegram connection successful!\n";
        echo "   📱 Check your Telegram for test message\n\n";
        $passed++;
    } else {
        echo "   ❌ Telegram connection failed\n";
        echo "   ℹ️  Check your bot token and chat ID\n\n";
        $failed++;
    }
} else {
    echo "   ⚠️  Telegram credentials not configured\n";
    echo "   ℹ️  Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID\n";
    echo "   ℹ️  Notifications will be disabled\n\n";
    $passed++;
}

// Test 4: Check ProxyList.txt
echo "🔍 Test 4: Checking proxy list...\n";
$proxyFile = __DIR__ . '/ProxyList.txt';

if (file_exists($proxyFile)) {
    $proxies = file($proxyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $cleanProxies = array_filter($proxies, function($line) {
        $line = trim($line);
        return !empty($line) && $line[0] !== '#';
    });
    
    $count = count($cleanProxies);
    
    if ($count > 0) {
        echo "   ✅ ProxyList.txt contains $count proxies\n";
        echo "   📋 Sample proxies:\n";
        foreach (array_slice($cleanProxies, 0, 3) as $proxy) {
            echo "      • $proxy\n";
        }
        echo "\n";
        $passed++;
    } else {
        echo "   ⚠️  ProxyList.txt is empty\n";
        echo "   ℹ️  Run fetch_proxies.php to get proxies\n\n";
        $failed++;
    }
} else {
    echo "   ❌ ProxyList.txt not found\n";
    echo "   ℹ️  Creating empty ProxyList.txt...\n";
    touch($proxyFile);
    echo "   ✅ Created empty ProxyList.txt\n";
    echo "   ℹ️  Run fetch_proxies.php to populate it\n\n";
    $failed++;
}

// Test 5: Check file permissions
echo "🔍 Test 5: Checking file permissions...\n";
$writeableFiles = [
    'ProxyList.txt',
    'background_checker.log',
    'last_background_check.txt'
];

$permissionTests = [];
foreach ($writeableFiles as $file) {
    $path = __DIR__ . '/' . $file;
    
    // Create if doesn't exist
    if (!file_exists($path)) {
        touch($path);
    }
    
    if (is_writable($path)) {
        echo "   ✅ $file is writable\n";
        $permissionTests[$file] = true;
    } else {
        echo "   ❌ $file is NOT writable\n";
        $permissionTests[$file] = false;
        $failed++;
    }
}

if (!in_array(false, $permissionTests)) {
    $passed++;
    echo "   ✅ All files are writable\n\n";
} else {
    echo "   ❌ Some files are not writable\n";
    echo "   ℹ️  Run: chmod 666 " . implode(' ', $writeableFiles) . "\n\n";
}

// Test 6: Test BackgroundProxyChecker instantiation
echo "🔍 Test 6: Testing BackgroundProxyChecker...\n";
try {
    require_once __DIR__ . '/background_proxy_checker.php';
    $checker = new BackgroundProxyChecker();
    echo "   ✅ BackgroundProxyChecker instantiated successfully\n";
    
    $status = $checker->getStatus();
    echo "   📊 Status:\n";
    echo "      • Last check: " . $status['last_check_formatted'] . "\n";
    echo "      • Proxy count: " . $status['proxy_count'] . "\n";
    echo "      • Telegram: " . ($status['telegram_enabled'] ? 'Enabled' : 'Disabled') . "\n";
    echo "      • Check interval: " . round($status['check_interval'] / 60) . " minutes\n";
    echo "\n";
    $passed++;
} catch (Exception $e) {
    echo "   ❌ Failed to instantiate: " . $e->getMessage() . "\n\n";
    $failed++;
}

// Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                        TEST SUMMARY                          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$total = $passed + $failed;
echo "Total Tests: $total\n";
echo "✅ Passed: $passed\n";
echo "❌ Failed: $failed\n\n";

if ($failed == 0) {
    echo "🎉 ALL TESTS PASSED! 🎉\n";
    echo "✅ System is ready to use\n\n";
    echo "Next steps:\n";
    echo "  1. Run a manual check:\n";
    echo "     php background_proxy_checker.php check\n\n";
    echo "  2. Or start daemon mode:\n";
    echo "     ./start_background_checker.sh\n\n";
} else {
    echo "⚠️  SOME TESTS FAILED\n";
    echo "Please fix the issues above before using the system\n\n";
    
    if (empty($botToken) || empty($chatId)) {
        echo "💡 To enable Telegram notifications:\n";
        echo "   export TELEGRAM_BOT_TOKEN='your_token'\n";
        echo "   export TELEGRAM_CHAT_ID='your_chat_id'\n\n";
    }
    
    if (!isset($count) || $count == 0) {
        echo "💡 To fetch proxies:\n";
        echo "   php fetch_proxies.php\n\n";
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
