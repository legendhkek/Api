<?php
/**
 * Background Proxy Checker with Telegram Notifications
 * 
 * Features:
 * - Background health checks using proxies
 * - Automatic proxy rotation
 * - Sends proxy status to Telegram bot
 * - Sends working proxy list to Telegram
 * - Runs periodically without blocking
 * - Comprehensive logging
 */

require_once __DIR__ . '/ProxyManager.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/ProxyAnalytics.php';

class BackgroundProxyChecker {
    private $proxyManager;
    private $telegram;
    private $analytics;
    private $proxyFile = 'ProxyList.txt';
    private $logFile = 'background_checker.log';
    private $lastCheckFile = 'last_background_check.txt';
    private $checkInterval = 1800; // 30 minutes
    private $useProxyForChecks = true;
    private $maxConcurrentChecks = 50;
    
    public function __construct(array $config = []) {
        $this->proxyFile = $config['proxyFile'] ?? 'ProxyList.txt';
        $this->checkInterval = $config['checkInterval'] ?? 1800;
        $this->useProxyForChecks = $config['useProxyForChecks'] ?? true;
        
        $this->proxyManager = new ProxyManager(__DIR__ . '/proxy_rotation.log');
        $this->telegram = new TelegramNotifier(
            $config['telegram_token'] ?? ($_ENV['TELEGRAM_BOT_TOKEN'] ?? ''),
            $config['telegram_chat_id'] ?? ($_ENV['TELEGRAM_CHAT_ID'] ?? '')
        );
        $this->analytics = new ProxyAnalytics();
        
        $this->log("Background Proxy Checker initialized");
    }
    
    /**
     * Check if background check is needed
     */
    public function needsCheck(): bool {
        if (!file_exists($this->lastCheckFile)) {
            return true;
        }
        
        $lastCheck = (int)file_get_contents($this->lastCheckFile);
        $timeSinceCheck = time() - $lastCheck;
        
        return $timeSinceCheck >= $this->checkInterval;
    }
    
    /**
     * Run background check
     */
    public function runBackgroundCheck(bool $force = false): array {
        if (!$force && !$this->needsCheck()) {
            $timeLeft = $this->checkInterval - (time() - (int)file_get_contents($this->lastCheckFile));
            $this->log("Check not needed yet. Next check in " . round($timeLeft / 60) . " minutes");
            return [
                'success' => false,
                'message' => 'Check not needed yet',
                'next_check_in' => $timeLeft
            ];
        }
        
        $this->log("Starting background proxy check...");
        $startTime = microtime(true);
        
        // Load proxies
        $proxies = $this->loadProxies();
        if (empty($proxies)) {
            $this->log("ERROR: No proxies available to check", 'ERROR');
            return [
                'success' => false,
                'message' => 'No proxies available',
                'error' => 'ProxyList.txt is empty or not found'
            ];
        }
        
        $this->log("Loaded " . count($proxies) . " proxies for checking");
        
        // Load proxies into ProxyManager
        $this->proxyManager->addProxies($proxies);
        
        // Test proxies (with or without using proxies for testing)
        $results = $this->testProxies($proxies);
        
        // Prepare report
        $report = $this->generateReport($results, microtime(true) - $startTime);
        
        // Send to Telegram
        if ($this->telegram->isEnabled()) {
            $this->sendTelegramReport($report);
        } else {
            $this->log("Telegram notifications not configured", 'WARNING');
        }
        
        // Update check time
        file_put_contents($this->lastCheckFile, time());
        
        // Update ProxyList.txt with only working proxies
        if (!empty($results['working'])) {
            $this->updateProxyList($results['working']);
        }
        
        $this->log("Background check completed in " . round($report['duration'], 2) . "s");
        
        return [
            'success' => true,
            'report' => $report,
            'working_proxies' => count($results['working']),
            'dead_proxies' => count($results['dead'])
        ];
    }
    
    /**
     * Load proxies from file
     */
    private function loadProxies(): array {
        if (!file_exists($this->proxyFile)) {
            return [];
        }
        
        $lines = file($this->proxyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $proxies = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && $line[0] !== '#') {
                $proxies[] = $line;
            }
        }
        
        return $proxies;
    }
    
    /**
     * Test proxies in parallel
     */
    private function testProxies(array $proxies): array {
        $workingProxies = [];
        $deadProxies = [];
        $proxyDetails = [];
        
        $testUrl = 'http://ip-api.com/json';
        $timeout = 5;
        
        // Process in batches
        $batches = array_chunk($proxies, $this->maxConcurrentChecks);
        $tested = 0;
        $total = count($proxies);
        
        foreach ($batches as $batchIndex => $batch) {
            $this->log("Testing batch " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " proxies)");
            
            $mh = curl_multi_init();
            $handles = [];
            
            foreach ($batch as $proxyString) {
                $proxyString = trim($proxyString);
                if (empty($proxyString)) continue;
                
                // Parse proxy
                $proxyInfo = $this->parseProxy($proxyString);
                if (!$proxyInfo) continue;
                
                $ch = curl_init();
                
                // If using proxy for checks, route through another working proxy
                $checkProxy = null;
                if ($this->useProxyForChecks && $batchIndex > 0 && !empty($workingProxies)) {
                    // Use a previously verified working proxy
                    $checkProxy = $workingProxies[array_rand($workingProxies)];
                }
                
                $curlOpts = [
                    CURLOPT_URL => $testUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_PROXY => $proxyInfo['address'],
                    CURLOPT_PROXYTYPE => $proxyInfo['type_int'],
                    CURLOPT_HTTPPROXYTUNNEL => false,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_ENCODING => '',
                    CURLOPT_TCP_NODELAY => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ];
                
                curl_setopt_array($ch, $curlOpts);
                
                $handles[] = [
                    'handle' => $ch,
                    'proxy' => $proxyString,
                    'info' => $proxyInfo
                ];
                
                curl_multi_add_handle($mh, $ch);
            }
            
            // Execute batch
            $running = null;
            do {
                $mrc = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($running && $mrc == CURLM_OK);
            
            // Collect results
            foreach ($handles as $entry) {
                $ch = $entry['handle'];
                $proxy = $entry['proxy'];
                $info = $entry['info'];
                $tested++;
                
                $startTime = microtime(true);
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                $error = curl_error($ch);
                
                $isWorking = ($response !== false && $httpCode >= 200 && $httpCode < 400);
                
                if ($isWorking) {
                    $workingProxies[] = $proxy;
                    
                    // Extract location info if available
                    $location = $this->extractLocationFromResponse($response);
                    
                    $proxyDetails[] = [
                        'proxy' => $proxy,
                        'type' => $info['type'],
                        'status' => 'working',
                        'response_time' => round($totalTime, 3),
                        'http_code' => $httpCode,
                        'location' => $location
                    ];
                    
                    $this->log("[$tested/$total] ✓ $proxy - OK ({$totalTime}s)");
                } else {
                    $deadProxies[] = $proxy;
                    
                    $proxyDetails[] = [
                        'proxy' => $proxy,
                        'type' => $info['type'],
                        'status' => 'dead',
                        'error' => $error ?: "HTTP $httpCode"
                    ];
                    
                    $this->log("[$tested/$total] ✗ $proxy - DEAD ($error)");
                }
                
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            
            curl_multi_close($mh);
        }
        
        return [
            'working' => $workingProxies,
            'dead' => $deadProxies,
            'details' => $proxyDetails,
            'total' => $total
        ];
    }
    
    /**
     * Parse proxy string
     */
    private function parseProxy(string $proxyString): ?array {
        $type = 'http';
        $typeInt = CURLPROXY_HTTP;
        $address = $proxyString;
        
        if (preg_match('/^(https?|socks[45]|socks[45]h?):\/\/(.+)$/i', $proxyString, $matches)) {
            $type = strtolower($matches[1]);
            $address = $matches[2];
            
            if (strpos($type, 'socks5') === 0) {
                $typeInt = CURLPROXY_SOCKS5;
            } elseif (strpos($type, 'socks4') === 0) {
                $typeInt = CURLPROXY_SOCKS4;
            } elseif ($type === 'https') {
                $typeInt = CURLPROXY_HTTPS;
            }
        }
        
        // Parse ip:port:user:pass
        $parts = explode(':', $address);
        if (count($parts) < 2) {
            return null;
        }
        
        return [
            'type' => $type,
            'type_int' => $typeInt,
            'address' => $address,
            'ip' => $parts[0],
            'port' => $parts[1],
            'user' => $parts[2] ?? '',
            'pass' => $parts[3] ?? ''
        ];
    }
    
    /**
     * Extract location from API response
     */
    private function extractLocationFromResponse(string $response): ?array {
        $data = json_decode($response, true);
        if (!$data) return null;
        
        return [
            'country' => $data['country'] ?? 'Unknown',
            'country_code' => $data['countryCode'] ?? 'XX',
            'region' => $data['regionName'] ?? 'Unknown',
            'city' => $data['city'] ?? 'Unknown',
            'isp' => $data['isp'] ?? 'Unknown',
            'ip' => $data['query'] ?? 'Unknown'
        ];
    }
    
    /**
     * Generate comprehensive report
     */
    private function generateReport(array $results, float $duration): array {
        $working = count($results['working']);
        $dead = count($results['dead']);
        $total = $results['total'];
        $successRate = $total > 0 ? round(($working / $total) * 100, 1) : 0;
        
        // Group by type
        $byType = [];
        $byCountry = [];
        $avgResponseTimes = [];
        
        foreach ($results['details'] as $detail) {
            if ($detail['status'] === 'working') {
                $type = $detail['type'];
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                
                if (isset($detail['location']['country'])) {
                    $country = $detail['location']['country'];
                    $byCountry[$country] = ($byCountry[$country] ?? 0) + 1;
                }
                
                if (isset($detail['response_time'])) {
                    $avgResponseTimes[] = $detail['response_time'];
                }
            }
        }
        
        $avgResponseTime = !empty($avgResponseTimes) 
            ? round(array_sum($avgResponseTimes) / count($avgResponseTimes), 3) 
            : 0;
        
        return [
            'timestamp' => time(),
            'timestamp_formatted' => date('Y-m-d H:i:s'),
            'duration' => $duration,
            'total_proxies' => $total,
            'working_proxies' => $working,
            'dead_proxies' => $dead,
            'success_rate' => $successRate,
            'avg_response_time' => $avgResponseTime,
            'by_type' => $byType,
            'by_country' => $byCountry,
            'proxy_list' => $results['working'],
            'details' => $results['details']
        ];
    }
    
    /**
     * Send report to Telegram
     */
    private function sendTelegramReport(array $report): bool {
        // Main status message
        $emoji = $report['success_rate'] >= 70 ? '✅' : ($report['success_rate'] >= 40 ? '⚠️' : '❌');
        
        $message = "$emoji <b>Background Proxy Check Report</b>\n\n";
        $message .= "📊 <b>Statistics:</b>\n";
        $message .= "• Total Proxies: {$report['total_proxies']}\n";
        $message .= "• Working: {$report['working_proxies']}\n";
        $message .= "• Dead: {$report['dead_proxies']}\n";
        $message .= "• Success Rate: {$report['success_rate']}%\n";
        $message .= "• Avg Response: {$report['avg_response_time']}s\n";
        $message .= "• Check Duration: " . round($report['duration'], 1) . "s\n\n";
        
        if (!empty($report['by_type'])) {
            $message .= "🔧 <b>By Protocol:</b>\n";
            foreach ($report['by_type'] as $type => $count) {
                $message .= "• " . strtoupper($type) . ": $count\n";
            }
            $message .= "\n";
        }
        
        if (!empty($report['by_country'])) {
            $message .= "🌍 <b>Top Countries:</b>\n";
            arsort($report['by_country']);
            $topCountries = array_slice($report['by_country'], 0, 5, true);
            foreach ($topCountries as $country => $count) {
                $message .= "• $country: $count\n";
            }
            $message .= "\n";
        }
        
        $message .= "⏰ " . $report['timestamp_formatted'];
        
        $result = $this->telegram->sendMessage($message);
        
        // Send working proxy list if there are any
        if (!empty($report['proxy_list']) && $report['working_proxies'] <= 50) {
            $this->sendProxyListToTelegram($report['proxy_list']);
        } elseif ($report['working_proxies'] > 50) {
            // Send summary for large lists
            $sample = array_slice($report['proxy_list'], 0, 10);
            $proxyMessage = "📋 <b>Working Proxies (Sample of {$report['working_proxies']}):</b>\n\n";
            $proxyMessage .= "<code>" . implode("\n", $sample) . "</code>\n\n";
            $proxyMessage .= "... and " . ($report['working_proxies'] - 10) . " more proxies";
            $this->telegram->sendMessage($proxyMessage);
        }
        
        return $result;
    }
    
    /**
     * Send working proxy list to Telegram
     */
    private function sendProxyListToTelegram(array $proxies): bool {
        if (empty($proxies)) {
            return false;
        }
        
        $message = "📋 <b>Working Proxy List (" . count($proxies) . "):</b>\n\n";
        $message .= "<code>" . implode("\n", $proxies) . "</code>";
        
        return $this->telegram->sendMessage($message);
    }
    
    /**
     * Update ProxyList.txt with working proxies only
     */
    private function updateProxyList(array $workingProxies): bool {
        if (empty($workingProxies)) {
            $this->log("Not updating ProxyList.txt - no working proxies", 'WARNING');
            return false;
        }
        
        $content = implode("\n", $workingProxies) . "\n";
        $result = file_put_contents($this->proxyFile, $content);
        
        if ($result !== false) {
            $this->log("Updated ProxyList.txt with " . count($workingProxies) . " working proxies");
            return true;
        }
        
        $this->log("Failed to update ProxyList.txt", 'ERROR');
        return false;
    }
    
    /**
     * Run continuous monitoring (daemon mode)
     */
    public function startDaemon(int $interval = null): void {
        $interval = $interval ?? $this->checkInterval;
        
        $this->log("Starting daemon mode (check interval: {$interval}s)");
        
        // Send startup notification
        if ($this->telegram->isEnabled()) {
            $this->telegram->sendMessage(
                "🤖 <b>Background Proxy Checker Started</b>\n\n" .
                "Check interval: " . round($interval / 60) . " minutes\n" .
                "Time: " . date('Y-m-d H:i:s')
            );
        }
        
        while (true) {
            try {
                $this->runBackgroundCheck(true);
            } catch (Exception $e) {
                $this->log("Error in daemon: " . $e->getMessage(), 'ERROR');
                
                if ($this->telegram->isEnabled()) {
                    $this->telegram->sendAlert(
                        'Background Checker Error',
                        $e->getMessage(),
                        'critical'
                    );
                }
            }
            
            $this->log("Sleeping for " . round($interval / 60) . " minutes");
            sleep($interval);
        }
    }
    
    /**
     * Get status
     */
    public function getStatus(): array {
        $lastCheck = file_exists($this->lastCheckFile) 
            ? (int)file_get_contents($this->lastCheckFile) 
            : 0;
        
        $proxies = $this->loadProxies();
        
        return [
            'last_check' => $lastCheck,
            'last_check_formatted' => $lastCheck > 0 ? date('Y-m-d H:i:s', $lastCheck) : 'Never',
            'time_since_check' => $lastCheck > 0 ? time() - $lastCheck : null,
            'needs_check' => $this->needsCheck(),
            'check_interval' => $this->checkInterval,
            'proxy_count' => count($proxies),
            'telegram_enabled' => $this->telegram->isEnabled(),
            'use_proxy_for_checks' => $this->useProxyForChecks
        ];
    }
    
    /**
     * Log message
     */
    private function log(string $message, string $level = 'INFO'): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Also output to console in CLI mode
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
}

// ============================================================================
// CLI & Web Interface
// ============================================================================

if (php_sapi_name() === 'cli') {
    // CLI Mode
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║     BACKGROUND PROXY CHECKER WITH TELEGRAM NOTIFICATIONS     ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    
    $checker = new BackgroundProxyChecker();
    
    // Parse command line arguments
    $command = $argv[1] ?? 'check';
    
    switch ($command) {
        case 'check':
            echo "🔄 Running background check...\n\n";
            $result = $checker->runBackgroundCheck(true);
            
            if ($result['success']) {
                echo "\n✅ Check completed successfully!\n";
                echo "Working proxies: {$result['working_proxies']}\n";
                echo "Dead proxies: {$result['dead_proxies']}\n";
            } else {
                echo "\n❌ Check failed: {$result['message']}\n";
            }
            break;
            
        case 'daemon':
            echo "🚀 Starting daemon mode...\n\n";
            $interval = isset($argv[2]) && is_numeric($argv[2]) ? (int)$argv[2] : 1800;
            $checker->startDaemon($interval);
            break;
            
        case 'status':
            echo "📊 Status:\n\n";
            $status = $checker->getStatus();
            foreach ($status as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }
                echo str_pad($key . ':', 25) . " $value\n";
            }
            break;
            
        default:
            echo "Usage:\n";
            echo "  php " . basename(__FILE__) . " check          - Run single check\n";
            echo "  php " . basename(__FILE__) . " daemon [secs]  - Run as daemon (default: 1800s)\n";
            echo "  php " . basename(__FILE__) . " status         - Show status\n";
            break;
    }
    
} else {
    // Web Mode
    header('Content-Type: application/json');
    
    $checker = new BackgroundProxyChecker();
    
    $action = $_GET['action'] ?? 'status';
    
    switch ($action) {
        case 'check':
            $result = $checker->runBackgroundCheck(isset($_GET['force']));
            echo json_encode($result, JSON_PRETTY_PRINT);
            break;
            
        case 'status':
        default:
            $status = $checker->getStatus();
            echo json_encode($status, JSON_PRETTY_PRINT);
            break;
    }
}
