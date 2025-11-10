<?php
/**
 * Auto Proxy Health Check System
 * Checks proxy health every hour and removes dead ones
 * Keeps only working proxies in ProxyList.txt
 */

$cacheFile = __DIR__ . '/proxy_check_time.txt';
$proxyListFile = __DIR__ . '/ProxyList.txt';
$checkInterval = 1 * 60 * 60; // 1 hour in seconds
const HEALTH_MAX_CONCURRENCY = 200;

/**
 * Check if health check is needed
 */
function shouldCheckProxies($cacheFile, $checkInterval) {
    if (!file_exists($cacheFile)) {
        return true;
    }
    
    $lastCheck = (int)file_get_contents($cacheFile);
    $timeSinceCheck = time() - $lastCheck;
    
    return $timeSinceCheck >= $checkInterval;
}

/**
 * Test if a proxy is working (supports http/https/socks4/socks5)
 */
function testProxyHealth($proxyString, $timeout = 5) {
    $proxyRaw = trim($proxyString);
    $type = 'http';
    $addr = $proxyRaw;
    if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $proxyRaw, $m)) {
        $type = strtolower($m[1]);
        $addr = $m[2];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'http://ip-api.com/json', // HTTP target avoids CONNECT tunnel
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_PROXY => $addr,
        CURLOPT_HTTPPROXYTUNNEL => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_TCP_NODELAY => true
    ]);
    // Set proxy type per scheme
    if ($type === 'socks4') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
    elseif ($type === 'socks5') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    elseif ($type === 'https') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
    else curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($response !== false && $httpCode == 200);
}

/**
 * Test proxies in parallel using curl_multi (batched)
 * Keeps timeouts unchanged. Default concurrency: 200
 * Returns arrays of working and dead proxies
 */
function testProxiesInParallelHealth(array $proxies, int $timeout = 5, int $concurrency = HEALTH_MAX_CONCURRENCY) {
    $workingProxies = [];
    $deadProxies = [];
    $total = count($proxies);

    if ($total === 0) {
        return [$workingProxies, $deadProxies];
    }

    // Process in chunks to limit parallelism
    $chunks = array_chunk($proxies, max(1, $concurrency));
    $tested = 0;

    foreach ($chunks as $chunkIndex => $chunk) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($chunk as $proxy) {
            $p = trim($proxy);
            if ($p === '' || $p[0] === '#') {
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'http://ip-api.com/json',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                // Proxy will be set below with proper type
                CURLOPT_HTTPPROXYTUNNEL => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => '',
                CURLOPT_TCP_NODELAY => true,
            ]);
            // Parse scheme and set proxy type
            $type = 'http';
            $addr = $p;
            if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $p, $m)) {
                $type = strtolower($m[1]);
                $addr = $m[2];
            }
            curl_setopt($ch, CURLOPT_PROXY, $addr);
            if ($type === 'socks4') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            elseif ($type === 'socks5') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            elseif ($type === 'https') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            else curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            $handles[] = [
                'handle' => $ch,
                'proxy' => $proxy,
            ];
            curl_multi_add_handle($mh, $ch);
        }

        // Execute all handles in this chunk
        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $mrc == CURLM_OK);

        // Collect results
        foreach ($handles as $entry) {
            $ch = $entry['handle'];
            $proxy = $entry['proxy'];
            $tested++;
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response !== false && $httpCode == 200) {
                $workingProxies[] = $proxy;
                echo "[$tested/$total] $proxy => ✅ WORKING\n";
            } else {
                $deadProxies[] = $proxy;
                $err = curl_error($ch);
                echo "[$tested/$total] $proxy => ❌ DEAD" . ($err ? " ($err)" : "") . "\n";
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    return [$workingProxies, $deadProxies];
}

/**
 * Check all proxies and keep only working ones
 */
function checkAndFilterProxies($proxyListFile) {
    if (!file_exists($proxyListFile)) {
        echo "❌ ProxyList.txt not found\n";
        return false;
    }
    
    $proxies = file($proxyListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($proxies)) {
        echo "❌ No proxies in ProxyList.txt\n";
        return false;
    }
    
    echo "📊 Found " . count($proxies) . " proxies to check\n";
    echo "⚡ Testing proxies (timeout: 5s each)...\n\n";
    
    // Clean proxy list (remove comments/empties)
    $cleanProxies = [];
    foreach ($proxies as $proxy) {
        $proxy = trim($proxy);
        if ($proxy === '' || $proxy[0] === '#') continue;
        $cleanProxies[] = $proxy;
    }

    // Parallel health check (200 at a time, 5s timeout each)
    list($workingProxies, $deadProxies) = testProxiesInParallelHealth($cleanProxies, 5, HEALTH_MAX_CONCURRENCY);
    
    return [
        'working' => $workingProxies,
        'dead' => $deadProxies,
        'total' => count($proxies)
    ];
}

/**
 * Update proxy list with only working proxies
 */
function updateProxyList() {
    global $cacheFile, $proxyListFile;
    
    echo "🔄 Checking proxy health...\n\n";
    
    $result = checkAndFilterProxies($proxyListFile);
    
    if ($result === false) {
        return false;
    }
    
    $workingCount = count($result['working']);
    $deadCount = count($result['dead']);
    $successRate = round(($workingCount / $result['total']) * 100, 1);
    
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📊 Health Check Results:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Total Proxies: {$result['total']}\n";
    echo "✅ Working: $workingCount\n";
    echo "❌ Dead: $deadCount\n";
    echo "Success Rate: $successRate%\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    // Send to Telegram if configured
    sendTelegramNotification($workingCount, $deadCount, $result['total'], $successRate, $result['working']);
    
    if (empty($result['working'])) {
        echo "⚠️  WARNING: No working proxies found!\n";
        echo "❌ ProxyList.txt NOT updated (keeping old list)\n";
        return false;
    }
    
    // Save only working proxies
    file_put_contents($proxyListFile, implode("\n", $result['working']));
    
    // Update check time
    file_put_contents($cacheFile, time());
    
    if ($deadCount > 0) {
        echo "🗑️  Removed $deadCount dead proxy(ies)\n";
    }
    echo "✅ ProxyList.txt updated with $workingCount working proxy(ies)\n";
    echo "⏰ Next check in 1 hour\n";
    
    return true;
}

/**
 * Send Telegram notification with proxy check results
 */
function sendTelegramNotification($working, $dead, $total, $successRate, $workingProxies) {
    // Check if Telegram is configured
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN');
    $chatId = $_ENV['TELEGRAM_CHAT_ID'] ?? getenv('TELEGRAM_CHAT_ID');
    
    if (empty($botToken) || empty($chatId)) {
        echo "ℹ️  Telegram notifications not configured (skipping)\n";
        return false;
    }
    
    // Load TelegramNotifier if available
    if (file_exists(__DIR__ . '/TelegramNotifier.php')) {
        require_once __DIR__ . '/TelegramNotifier.php';
        $telegram = new TelegramNotifier($botToken, $chatId);
        
        // Determine emoji based on success rate
        $emoji = $successRate >= 70 ? '✅' : ($successRate >= 40 ? '⚠️' : '❌');
        
        // Build message
        $message = "$emoji <b>Proxy Health Check Complete</b>\n\n";
        $message .= "📊 <b>Results:</b>\n";
        $message .= "• Total: $total\n";
        $message .= "• Working: $working\n";
        $message .= "• Dead: $dead\n";
        $message .= "• Success Rate: $successRate%\n\n";
        
        // Add working proxies if reasonable count
        if ($working > 0 && $working <= 20) {
            $message .= "📋 <b>Working Proxies:</b>\n";
            $message .= "<code>" . implode("\n", array_slice($workingProxies, 0, 20)) . "</code>\n\n";
        } elseif ($working > 20) {
            $message .= "📋 <b>Working Proxies (Sample):</b>\n";
            $message .= "<code>" . implode("\n", array_slice($workingProxies, 0, 10)) . "</code>\n";
            $message .= "... and " . ($working - 10) . " more\n\n";
        }
        
        $message .= "⏰ " . date('Y-m-d H:i:s');
        
        $result = $telegram->sendMessage($message);
        
        if ($result) {
            echo "✅ Telegram notification sent\n";
        } else {
            echo "❌ Failed to send Telegram notification\n";
        }
        
        return $result;
    } else {
        echo "⚠️  TelegramNotifier.php not found\n";
        return false;
    }
}

/**
 * Get time until next check
 */
function getTimeUntilNextCheck($cacheFile, $checkInterval) {
    if (!file_exists($cacheFile)) {
        return 0;
    }
    
    $lastCheck = (int)file_get_contents($cacheFile);
    $timeSinceCheck = time() - $lastCheck;
    $timeUntilCheck = $checkInterval - $timeSinceCheck;
    
    return max(0, $timeUntilCheck);
}

// Main execution
if (php_sapi_name() === 'cli') {
    // CLI mode - run health check
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║         AUTO PROXY HEALTH CHECK SYSTEM                       ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    
    if (shouldCheckProxies($cacheFile, $checkInterval)) {
        echo "⏰ Health check needed (>1 hour since last check)\n\n";
        updateProxyList();
    } else {
        $timeLeft = getTimeUntilNextCheck($cacheFile, $checkInterval);
        $minutesLeft = floor($timeLeft / 60);
        
        echo "✅ Proxies checked recently\n";
        echo "⏰ Next check in: {$minutesLeft} minutes\n";
        echo "\nTo force check, run: php " . basename(__FILE__) . " --force\n";
    }
    
    // Force check option
    if (isset($argv[1]) && $argv[1] === '--force') {
        echo "\n🔄 Forcing health check...\n\n";
        updateProxyList();
    }
} else {
    // Web mode - return status
    header('Content-Type: application/json');
    
    $lastCheck = file_exists($cacheFile) ? (int)file_get_contents($cacheFile) : 0;
    $timeUntilCheck = getTimeUntilNextCheck($cacheFile, $checkInterval);
    $needsCheck = shouldCheckProxies($cacheFile, $checkInterval);
    
    // Auto-check if needed
    if ($needsCheck && isset($_GET['auto'])) {
        ob_start();
        $success = updateProxyList();
        $output = ob_get_clean();
        $lastCheck = time();
        $timeUntilCheck = $checkInterval;
        $needsCheck = false;
    }
    
    echo json_encode([
        'status' => $needsCheck ? 'needs_check' : 'healthy',
        'last_check' => $lastCheck,
        'last_check_formatted' => $lastCheck > 0 ? date('Y-m-d H:i:s', $lastCheck) : 'Never',
        'time_until_check' => $timeUntilCheck,
        'time_until_check_formatted' => floor($timeUntilCheck / 60) . ' minutes',
        'check_interval' => $checkInterval,
        'check_interval_formatted' => '1 hour',
        'proxy_file' => $proxyListFile,
        'proxy_count' => file_exists($proxyListFile) ? count(file($proxyListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0,
        'output' => $output ?? null
    ], JSON_PRETTY_PRINT);
}
?>
