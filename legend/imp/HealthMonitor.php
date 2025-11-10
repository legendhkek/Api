<?php
/**
 * Automatic Health Monitoring System
 * 
 * Features:
 * - Continuous proxy health checks
 * - Automatic dead proxy removal
 * - System health monitoring
 * - Performance tracking
 * - Alert triggering
 */
class HealthMonitor {
    private $proxyFile = 'ProxyList.txt';
    private $logFile = 'health_monitor.log';
    private $checkInterval = 300; // 5 minutes
    private $lastCheckFile = 'last_health_check.txt';
    private $analytics;
    private $telegram;
    private $proxyManager;
    
    public function __construct() {
        require_once 'ProxyAnalytics.php';
        require_once 'TelegramNotifier.php';
        require_once 'ProxyManager.php';
        
        $this->analytics = new ProxyAnalytics();
        $this->telegram = new TelegramNotifier();
        $this->proxyManager = new ProxyManager($this->logFile);
        
        // Load proxies for health checks
        if (file_exists($this->proxyFile)) {
            $this->proxyManager->loadFromFile($this->proxyFile);
        }
    }
    
    /**
     * Run health check
     * 
     * @param bool $full Full check or quick check
     * @return array Health check results
     */
    public function runHealthCheck(bool $full = false): array {
        $this->log("Starting health check (full: " . ($full ? 'yes' : 'no') . ")");
        
        $results = [
            'timestamp' => time(),
            'status' => 'healthy',
            'checks' => [],
            'metrics' => [],
            'issues' => [],
            'recommendations' => []
        ];
        
        // Check 1: Proxy file exists
        $results['checks']['proxy_file_exists'] = file_exists($this->proxyFile);
        if (!$results['checks']['proxy_file_exists']) {
            $results['issues'][] = 'ProxyList.txt not found';
            $results['status'] = 'critical';
        }
        
        // Check 2: Proxy count
        $proxies = $this->getProxies();
        $proxyCount = count($proxies);
        $results['metrics']['proxy_count'] = $proxyCount;
        $results['checks']['has_proxies'] = $proxyCount > 0;
        
        if ($proxyCount < 5) {
            $results['issues'][] = "Low proxy count: $proxyCount (recommended: 10+)";
            $results['recommendations'][] = 'Run fetch_proxies.php to get more proxies';
            if ($results['status'] === 'healthy') {
                $results['status'] = 'warning';
            }
        }
        
        // Check 3: PHP extensions
        $results['checks']['curl_extension'] = extension_loaded('curl');
        $results['checks']['gd_extension'] = extension_loaded('gd');
        $results['checks']['pdo_extension'] = extension_loaded('pdo');
        
        if (!$results['checks']['curl_extension']) {
            $results['issues'][] = 'cURL extension not loaded';
            $results['status'] = 'critical';
        }
        
        // Check 4: Disk space
        $freeSpace = disk_free_space(__DIR__);
        $totalSpace = disk_total_space(__DIR__);
        $usedPercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;
        $results['metrics']['disk_usage'] = round($usedPercentage, 2) . '%';
        $results['checks']['disk_space_ok'] = $usedPercentage < 90;
        
        if ($usedPercentage > 90) {
            $results['issues'][] = 'Low disk space: ' . round($usedPercentage) . '% used';
            if ($results['status'] === 'healthy') {
                $results['status'] = 'warning';
            }
        }
        
        // Check 5: Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseSize(ini_get('memory_limit'));
        $memoryPercentage = ($memoryUsage / $memoryLimit) * 100;
        $results['metrics']['memory_usage'] = $this->formatBytes($memoryUsage);
        $results['checks']['memory_ok'] = $memoryPercentage < 80;
        
        // Check 6: Analytics database
        $analyticsStats = $this->analytics->getOverallAnalytics();
        $results['checks']['analytics_db'] = !empty($analyticsStats);
        $results['metrics']['total_tracked_proxies'] = $analyticsStats['total_proxies'] ?? 0;
        $results['metrics']['avg_quality_score'] = round(($analyticsStats['avg_quality_score'] ?? 0) * 100, 1) . '%';
        
        // Check 7: Recent activity
        $lastCheckTime = file_exists($this->lastCheckFile) ? (int)file_get_contents($this->lastCheckFile) : 0;
        $timeSinceLastCheck = time() - $lastCheckTime;
        $results['metrics']['last_check'] = $lastCheckTime > 0 ? date('Y-m-d H:i:s', $lastCheckTime) : 'Never';
        $results['checks']['recent_activity'] = $timeSinceLastCheck < 3600; // Last hour
        
        // Full check: Test proxies
        if ($full && $proxyCount > 0) {
            $testResults = $this->testProxySample($proxies);
            $results['metrics']['working_proxies'] = $testResults['working'];
            $results['metrics']['dead_proxies'] = $testResults['dead'];
            $results['metrics']['proxy_success_rate'] = $testResults['success_rate'] . '%';
            $results['checks']['proxies_working'] = $testResults['working'] > 0;
            
            // Send working proxies to Telegram
            if ($testResults['working'] > 0 && $this->telegram->isEnabled()) {
                $workingProxies = $this->getWorkingProxies($proxies, $testResults['working']);
                $this->telegram->sendProxyList($workingProxies, [
                    'total_tested' => $testResults['total'],
                    'working' => $testResults['working'],
                    'dead' => $testResults['dead'],
                    'success_rate' => $testResults['success_rate']
                ]);
            }
            
            if ($testResults['success_rate'] < 30) {
                $results['issues'][] = "Low proxy success rate: {$testResults['success_rate']}%";
                $results['recommendations'][] = 'Fetch fresh proxies';
                if ($results['status'] === 'healthy') {
                    $results['status'] = 'warning';
                }
            }
        }
        
        // Update last check time
        file_put_contents($this->lastCheckFile, time());
        
        // Determine final status
        if (empty($results['issues'])) {
            $results['status'] = 'healthy';
        }
        
        $this->log("Health check completed - Status: {$results['status']}");
        
        // Send notification if enabled
        if ($this->telegram->isEnabled()) {
            $this->telegram->notifyHealthCheck($results);
        }
        
        return $results;
    }
    
    /**
     * Get proxies from file
     * 
     * @return array Proxies
     */
    private function getProxies(): array {
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
     * Test a sample of proxies
     * 
     * @param array $proxies All proxies
     * @param int $sampleSize Sample size
     * @return array Test results
     */
    private function testProxySample(array $proxies, int $sampleSize = 10): array {
        $sample = array_slice($proxies, 0, min($sampleSize, count($proxies)));
        $working = 0;
        $dead = 0;
        
        foreach ($sample as $proxy) {
            if ($this->testProxy($proxy)) {
                $working++;
            } else {
                $dead++;
            }
        }
        
        $total = $working + $dead;
        $successRate = $total > 0 ? round(($working / $total) * 100, 1) : 0;
        
        return [
            'working' => $working,
            'dead' => $dead,
            'total' => $total,
            'success_rate' => $successRate
        ];
    }
    
    /**
     * Test single proxy
     * Uses ProxyManager's checkProxyHealth method which can use proxies for background checks
     * 
     * @param string $proxy Proxy string to test
     * @return bool Working status
     */
    private function testProxy(string $proxy): bool {
        // Parse proxy string using ProxyManager
        $proxyData = $this->parseProxyForTest($proxy);
        if (!$proxyData) {
            // Fallback to direct test if parsing fails
            return $this->testProxyDirect($proxy);
        }
        
        // Use ProxyManager's health check which can use proxies for background operations
        return $this->proxyManager->checkProxyHealth($proxyData);
    }
    
    /**
     * Parse proxy string for testing
     * 
     * @param string $proxyString Proxy string
     * @return array|null Parsed proxy data
     */
    private function parseProxyForTest(string $proxyString): ?array {
        // Try to add proxy to ProxyManager to get parsed data
        // We'll use a temporary ProxyManager instance to parse
        $tempManager = new ProxyManager();
        if ($tempManager->addProxy($proxyString)) {
            // Get the proxy data from ProxyManager's internal structure
            // Since we can't access private properties, we'll parse it ourselves
            return $this->parseProxyString($proxyString);
        }
        return null;
    }
    
    /**
     * Parse proxy string into array format
     * 
     * @param string $proxyString Proxy string
     * @return array|null Parsed proxy data
     */
    private function parseProxyString(string $proxyString): ?array {
        $proxy_lower = strtolower(trim($proxyString));
        $type = 'http';
        $type_int = CURLPROXY_HTTP;
        
        // Extract protocol prefix
        if (preg_match('/^(https?|socks[45]h?|socks[45]a?|tor):\/\/(.+)$/', $proxy_lower, $matches)) {
            $type = $matches[1];
            $proxyString = $matches[2];
            
            if (strpos($type, 'socks5') === 0) {
                $type_int = CURLPROXY_SOCKS5;
            } elseif (strpos($type, 'socks4') === 0) {
                $type_int = CURLPROXY_SOCKS4;
            } elseif ($type === 'https') {
                $type_int = CURLPROXY_HTTPS;
            } else {
                $type_int = CURLPROXY_HTTP;
            }
        }
        
        // Parse ip:port:user:pass
        $parts = explode(':', $proxyString);
        if (count($parts) < 2) {
            return null;
        }
        
        $ip = trim($parts[0]);
        $port = trim($parts[1]);
        $user = isset($parts[2]) ? trim($parts[2]) : '';
        $pass = isset($parts[3]) ? trim($parts[3]) : '';
        
        if (empty($ip) || empty($port) || !is_numeric($port)) {
            return null;
        }
        
        return [
            'id' => md5($ip . $port . $user),
            'ip' => $ip,
            'port' => (int)$port,
            'user' => $user,
            'pass' => $pass,
            'type' => $type,
            'type_int' => $type_int,
            'string' => "$type://$ip:$port" . (!empty($user) ? ":$user" : "")
        ];
    }
    
    /**
     * Test proxy directly (fallback method)
     * 
     * @param string $proxy Proxy string
     * @return bool Working status
     */
    private function testProxyDirect(string $proxy): bool {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://ip-api.com/json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROXY => $proxy,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($response !== false && $httpCode == 200);
    }
    
    /**
     * Apply proxy directly to cURL handle (fallback)
     * 
     * @param resource $ch cURL handle
     * @param string $proxyString Proxy string
     */
    private function applyProxyDirect($ch, string $proxyString): void {
        curl_setopt($ch, CURLOPT_PROXY, $proxyString);
    }
    
    /**
     * Monitor continuously (daemon mode)
     * 
     * @param int $interval Check interval in seconds
     */
    public function startMonitoring(int $interval = null): void {
        $interval = $interval ?? $this->checkInterval;
        
        $this->log("Starting continuous monitoring (interval: {$interval}s)");
        
        while (true) {
            try {
                $results = $this->runHealthCheck(true);
                
                // Take action based on status
                if ($results['status'] === 'critical') {
                    $this->handleCriticalStatus($results);
                } elseif ($results['status'] === 'warning') {
                    $this->handleWarningStatus($results);
                }
                
                // Clean old analytics records
                $this->analytics->cleanOldRecords(30);
                
            } catch (Exception $e) {
                $this->log("Error during health check: " . $e->getMessage(), 'ERROR');
            }
            
            sleep($interval);
        }
    }
    
    /**
     * Handle critical status
     * 
     * @param array $results Health check results
     */
    private function handleCriticalStatus(array $results): void {
        $this->log("CRITICAL status detected", 'CRITICAL');
        
        if ($this->telegram->isEnabled()) {
            $issues = implode("\n", $results['issues']);
            $this->telegram->sendAlert(
                'Critical System Issue',
                "Issues detected:\n$issues",
                'critical'
            );
        }
    }
    
    /**
     * Handle warning status
     * 
     * @param array $results Health check results
     */
    private function handleWarningStatus(array $results): void {
        $this->log("WARNING status detected", 'WARNING');
        
        // Auto-fetch proxies if needed
        if (isset($results['metrics']['proxy_count']) && $results['metrics']['proxy_count'] < 5) {
            $this->log("Auto-fetching proxies due to low count");
            $this->autoFetchProxies();
        }
    }
    
    /**
     * Auto-fetch proxies
     */
    private function autoFetchProxies(): void {
        require_once 'AutoProxyFetcher.php';
        $fetcher = new AutoProxyFetcher();
        $result = $fetcher->ensureProxies();
        
        if ($result['success']) {
            $this->log("Auto-fetch successful: {$result['count']} proxies");
        } else {
            $this->log("Auto-fetch failed", 'ERROR');
        }
    }
    
    /**
     * Parse size string (e.g., "128M" to bytes)
     * 
     * @param string $size Size string
     * @return int Bytes
     */
    private function parseSize(string $size): int {
        $size = trim($size);
        $last = strtolower($size[strlen($size)-1]);
        $size = (int)$size;
        
        switch($last) {
            case 'g': $size *= 1024;
            case 'm': $size *= 1024;
            case 'k': $size *= 1024;
        }
        
        return $size;
    }
    
    /**
     * Format bytes to human readable
     * 
     * @param int $bytes Bytes
     * @return string Formatted size
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Get working proxies from sample
     * 
     * @param array $proxies All proxies
     * @param int $count Number of working proxies needed
     * @return array Working proxy strings
     */
    private function getWorkingProxies(array $proxies, int $count): array {
        $working = [];
        $sample = array_slice($proxies, 0, min(count($proxies), $count * 2)); // Test more to find working ones
        
        foreach ($sample as $proxy) {
            if (count($working) >= $count) {
                break;
            }
            if ($this->testProxy($proxy)) {
                $working[] = $proxy;
            }
        }
        
        return $working;
    }
    
    /**
     * Log message
     * 
     * @param string $message Message
     * @param string $level Log level
     */
    private function log(string $message, string $level = 'INFO'): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}
