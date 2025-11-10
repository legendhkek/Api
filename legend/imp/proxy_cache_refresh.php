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

// Load environment configuration if available
if (file_exists(__DIR__ . '/.env')) {
    $envConfig = parse_ini_file(__DIR__ . '/.env');
    if (is_array($envConfig)) {
        foreach ($envConfig as $key => $value) {
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

require_once __DIR__ . '/TelegramNotifier.php';

/**
 * Resolve Telegram overrides from CLI arguments or HTTP parameters.
 *
 * @return array{bot_token:?string,chat_id:?string}
 */
function resolveTelegramOverrides(): array {
    $overrides = [
        'bot_token' => null,
        'chat_id' => null,
    ];

    if (php_sapi_name() === 'cli') {
        global $argv;
        if (!empty($argv)) {
            foreach ($argv as $arg) {
                if (strpos($arg, '--bot-token=') === 0) {
                    $overrides['bot_token'] = substr($arg, 12);
                } elseif (strpos($arg, '--bot=') === 0) {
                    $overrides['bot_token'] = substr($arg, 6);
                } elseif (strpos($arg, '--chat-id=') === 0) {
                    $overrides['chat_id'] = substr($arg, 10);
                } elseif (strpos($arg, '--chat=') === 0) {
                    $overrides['chat_id'] = substr($arg, 7);
                }
            }
        }
    } else {
        if (isset($_GET['bot_token']) && $_GET['bot_token'] !== '') {
            $overrides['bot_token'] = (string)$_GET['bot_token'];
        }
        if (isset($_GET['chat_id']) && $_GET['chat_id'] !== '') {
            $overrides['chat_id'] = (string)$_GET['chat_id'];
        }
    }

    return $overrides;
}

$telegramOverrides = resolveTelegramOverrides();
$telegramNotifier = new TelegramNotifier(
    $telegramOverrides['bot_token'] ?? '',
    $telegramOverrides['chat_id'] ?? ''
);

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
 * Update proxy list with only working proxies and optionally notify Telegram.
 *
 * @param TelegramNotifier|null $notifier Optional notifier instance.
 * @param array $options Additional options (title, preview_limit, filename).
 * @return array Summary of the health check and update.
 */
function updateProxyList(?TelegramNotifier $notifier = null, array $options = []): array {
    global $cacheFile, $proxyListFile;

    echo "🔄 Checking proxy health...\n\n";

    $startedAt = time();
    $result = checkAndFilterProxies($proxyListFile);

    $baseSummary = [
        'success' => false,
        'updated' => false,
        'working' => [],
        'dead' => [],
        'stats' => [
            'total' => 0,
            'working' => 0,
            'dead' => 0,
            'success_rate' => 0.0,
        ],
        'message' => 'Proxy health check failed.',
        'checked_at' => $startedAt,
        'notified' => false,
        'telegram_enabled' => $notifier ? $notifier->isEnabled() : false,
    ];

    $title = $options['title'] ?? 'Proxy Health Check';

    if ($result === false) {
        if ($notifier && $notifier->isEnabled()) {
            $baseSummary['notified'] = $notifier->sendProxyList([], [
                'title' => $title,
                'empty_message' => "⚠️ <b>{$title}:</b> Unable to evaluate proxies.",
            ]);
        }
        return $baseSummary;
    }

    $workingProxies = $result['working'];
    $deadProxies = $result['dead'];
    $totalCount = $result['total'];
    $workingCount = count($workingProxies);
    $deadCount = count($deadProxies);
    $successRate = $totalCount > 0 ? round(($workingCount / $totalCount) * 100, 1) : 0.0;

    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📊 Health Check Results:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Total Proxies: {$totalCount}\n";
    echo "✅ Working: {$workingCount}\n";
    echo "❌ Dead: {$deadCount}\n";
    echo "Success Rate: {$successRate}%\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $summary = [
        'success' => $workingCount > 0,
        'updated' => false,
        'working' => $workingProxies,
        'dead' => $deadProxies,
        'stats' => [
            'total' => $totalCount,
            'working' => $workingCount,
            'dead' => $deadCount,
            'success_rate' => $successRate,
        ],
        'message' => 'No working proxies found; ProxyList.txt not updated.',
        'checked_at' => $startedAt,
        'notified' => false,
        'telegram_enabled' => $notifier ? $notifier->isEnabled() : false,
    ];

    if ($workingCount === 0) {
        echo "⚠️  WARNING: No working proxies found!\n";
        echo "❌ ProxyList.txt NOT updated (keeping old list)\n";

        if ($notifier && $notifier->isEnabled()) {
            $summary['notified'] = $notifier->sendProxyList([], [
                'title' => $title,
                'stats' => $summary['stats'],
                'empty_message' => "⚠️ <b>{$title}:</b> No working proxies found.",
            ]);
        }

        return $summary;
    }

    // Save only working proxies
    file_put_contents($proxyListFile, implode("\n", $workingProxies));

    // Update check time
    file_put_contents($cacheFile, time());

    if ($deadCount > 0) {
        echo "🗑️  Removed {$deadCount} dead proxy(ies)\n";
    }
    echo "✅ ProxyList.txt updated with {$workingCount} working proxy(ies)\n";
    echo "⏰ Next check in 1 hour\n";

    $summary['updated'] = true;
    $summary['message'] = "ProxyList.txt updated with {$workingCount} working proxies.";

    if ($notifier && $notifier->isEnabled()) {
        $summary['notified'] = $notifier->sendProxyList($workingProxies, [
            'title' => $title,
            'stats' => $summary['stats'],
            'preview_limit' => $options['preview_limit'] ?? 10,
            'filename' => $options['filename'] ?? ('working_proxies_' . date('Ymd_His') . '.txt'),
        ]);
    }

    return $summary;
}

/**
 * Log Telegram notification status to CLI output.
 *
 * @param array $summary Summary returned by updateProxyList.
 * @param string $label Context label for the log line.
 */
function logTelegramSummaryStatus(array $summary, string $label = 'Telegram'): void {
    if (!isset($summary['telegram_enabled'])) {
        return;
    }

    if (!$summary['telegram_enabled']) {
        echo "ℹ️ {$label}: Telegram notifications disabled (configure TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID or provide overrides).\n";
        return;
    }

    if (!empty($summary['notified'])) {
        echo "📨 {$label}: Telegram notification sent successfully.\n";
    } else {
        echo "⚠️ {$label}: Telegram notification could not be delivered.\n";
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

    if (!$telegramNotifier->isEnabled()) {
        echo "ℹ️ Telegram notifications disabled (set TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID or provide --bot/--chat overrides).\n\n";
    }
    
    if (shouldCheckProxies($cacheFile, $checkInterval)) {
        echo "⏰ Health check needed (>1 hour since last check)\n\n";
        $summary = updateProxyList($telegramNotifier, [
            'title' => 'Background Proxy Health Check',
        ]);
        logTelegramSummaryStatus($summary, 'Background');
    } else {
        $timeLeft = getTimeUntilNextCheck($cacheFile, $checkInterval);
        $minutesLeft = floor($timeLeft / 60);
        
        echo "✅ Proxies checked recently\n";
        echo "⏰ Next check in: {$minutesLeft} minutes\n";
        echo "\nTo force check, run: php " . basename(__FILE__) . " --force\n";
    }
    
    // Force check option
    $forceRequested = false;
    if (!empty($argv)) {
        foreach ($argv as $arg) {
            if ($arg === '--force' || $arg === '-f') {
                $forceRequested = true;
                break;
            }
        }
    }

    if ($forceRequested) {
        echo "\n🔄 Forcing health check...\n\n";
        $forcedSummary = updateProxyList($telegramNotifier, [
            'title' => 'Forced Proxy Health Check',
        ]);
        logTelegramSummaryStatus($forcedSummary, 'Force');
    }
} else {
    // Web mode - return status
    header('Content-Type: application/json');
    
    $lastCheck = file_exists($cacheFile) ? (int)file_get_contents($cacheFile) : 0;
    $timeUntilCheck = getTimeUntilNextCheck($cacheFile, $checkInterval);
    $needsCheck = shouldCheckProxies($cacheFile, $checkInterval);
    
    $latestSummary = null;
    $output = null;

    // Auto-check if needed
    if ($needsCheck && isset($_GET['auto'])) {
        ob_start();
        $latestSummary = updateProxyList($telegramNotifier, [
            'title' => 'Background Proxy Health Check',
        ]);
        $output = ob_get_clean();
        $lastCheck = time();
        $timeUntilCheck = $checkInterval;
        $needsCheck = false;
    }
    
    $status = $needsCheck ? 'needs_check' : 'healthy';
    if ($latestSummary !== null) {
        $status = !empty($latestSummary['success']) ? 'healthy' : 'warning';
    }

    echo json_encode([
        'status' => $status,
        'last_check' => $lastCheck,
        'last_check_formatted' => $lastCheck > 0 ? date('Y-m-d H:i:s', $lastCheck) : 'Never',
        'time_until_check' => $timeUntilCheck,
        'time_until_check_formatted' => floor($timeUntilCheck / 60) . ' minutes',
        'check_interval' => $checkInterval,
        'check_interval_formatted' => '1 hour',
        'proxy_file' => $proxyListFile,
        'proxy_count' => file_exists($proxyListFile) ? count(file($proxyListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0,
        'telegram_enabled' => $telegramNotifier->isEnabled(),
        'result' => $latestSummary,
        'output' => $output
    ], JSON_PRETTY_PRINT);
}
?>
