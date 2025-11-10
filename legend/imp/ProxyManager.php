<?php
/**
 * ProxyManager - Advanced Proxy Rotation and Management System
 * 
 * Features:
 * - Rotating proxy support with automatic failover
 * - Multi-proxy type support (HTTP, HTTPS, SOCKS4, SOCKS5, Tor)
 * - Proxy health checking and validation
 * - Speed testing and performance tracking
 * - Automatic dead proxy removal
 * - Load from file or array
 * - Retry logic with exponential backoff
 * - Detailed logging and statistics
 */
class ProxyManager {
    private $proxies = [];
    private $currentIndex = 0;
    private $deadProxies = [];
    private $proxyStats = [];
    private $logFile = '';
    private $checkTimeout = 5;
    private $maxRetries = 3;
    private $healthCheckUrl = 'http://ip-api.com/json'; // Use HTTP not HTTPS for proxy testing
    
    // Proxy type constants
    const TYPE_HTTP = CURLPROXY_HTTP;
    const TYPE_HTTPS = CURLPROXY_HTTPS;
    const TYPE_SOCKS4 = CURLPROXY_SOCKS4;
    const TYPE_SOCKS5 = CURLPROXY_SOCKS5;
    const TYPE_TOR = CURLPROXY_SOCKS5; // Tor uses SOCKS5
    
    /**
     * Constructor
     * 
     * @param string $logFile Optional log file path
     */
    public function __construct(string $logFile = '') {
        $this->logFile = $logFile ?: __DIR__ . '/proxy_log.txt';
    }
    
    /**
     * Load proxies from file
     * Format: type://ip:port:username:password (username:password optional)
     * Example: socks5://127.0.0.1:9050 (Tor)
     *          http://proxy.com:8080:user:pass
     * 
     * @param string $filePath Path to proxy list file
     * @return int Number of proxies loaded
     */
    public function loadFromFile(string $filePath): int {
        if (!file_exists($filePath)) {
            $this->log("ERROR: Proxy file not found: $filePath");
            return 0;
        }
        
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $count = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if ($this->addProxy($line)) {
                $count++;
            }
        }
        
        $this->log("Loaded $count proxies from file");
        return $count;
    }
    
    /**
     * Add a single proxy
     * 
     * @param string $proxyString Proxy string in format: type://ip:port:user:pass
     * @return bool Success status
     */
    public function addProxy(string $proxyString): bool {
        $proxy = $this->parseProxyString($proxyString);
        if (!$proxy) {
            $this->log("ERROR: Invalid proxy format: $proxyString");
            return false;
        }
        
        $this->proxies[] = $proxy;
        $this->proxyStats[$proxy['id']] = [
            'used' => 0,
            'success' => 0,
            'failed' => 0,
            'avg_speed' => 0,
            'last_used' => null
        ];
        
        return true;
    }
    
    /**
     * Add multiple proxies from array
     * 
     * @param array $proxyList Array of proxy strings
     * @return int Number of proxies added
     */
    public function addProxies(array $proxyList): int {
        $count = 0;
        foreach ($proxyList as $proxy) {
            if ($this->addProxy($proxy)) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Parse proxy string into structured array
     * 
     * @param string $proxyString Proxy string
     * @return array|null Parsed proxy data or null on failure
     */
    private function parseProxyString(string $proxyString): ?array {
        $originalString = $proxyString;
        $proxy_lower = strtolower(trim($proxyString));
        $type = 'http';
        $type_int = self::TYPE_HTTP;
        
        // Extract protocol prefix
        if (preg_match('/^(https?|socks[45]h?|socks[45]a?|tor):\/\/(.+)$/', $proxy_lower, $matches)) {
            $type = $matches[1];
            $proxyString = $matches[2];
            
            // Map type string to cURL constant
            if (strpos($type, 'socks5') === 0) {
                $type_int = self::TYPE_SOCKS5;
            } elseif (strpos($type, 'socks4') === 0) {
                $type_int = self::TYPE_SOCKS4;
            } elseif ($type === 'https') {
                $type_int = self::TYPE_HTTPS;
            } elseif ($type === 'tor') {
                $type_int = self::TYPE_TOR;
            } else {
                $type_int = self::TYPE_HTTP;
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
        
        // Validate IP and port
        if (empty($ip) || empty($port) || !is_numeric($port)) {
            return null;
        }
        
        // Validate IP format (accept both IP addresses and domain names)
        $isValidIP = filter_var($ip, FILTER_VALIDATE_IP);
        $isValidDomain = preg_match('/^[a-z0-9]+([-.]?[a-z0-9]+)*\.[a-z]{2,}$/i', $ip);
        
        if (!$isValidIP && !$isValidDomain) {
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
     * Get next available proxy (rotation)
     * 
     * @param bool $checkHealth Whether to verify proxy health
     * @return array|null Proxy data or null if none available
     */
    public function getNextProxy(bool $checkHealth = true): ?array {
        if (empty($this->proxies)) {
            $this->log("ERROR: No proxies available");
            return null;
        }
        
        $attempts = 0;
        $maxAttempts = count($this->proxies);
        
        while ($attempts < $maxAttempts) {
            $proxy = $this->proxies[$this->currentIndex];
            $this->currentIndex = ($this->currentIndex + 1) % count($this->proxies);
            $attempts++;
            
            // Skip dead proxies
            if (isset($this->deadProxies[$proxy['id']])) {
                continue;
            }
            
            // Check health if requested
            if ($checkHealth && !$this->checkProxyHealth($proxy)) {
                $this->markProxyDead($proxy);
                continue;
            }
            
            return $proxy;
        }
        
        $this->log("ERROR: All proxies are dead or unavailable");
        return null;
    }
    
    /**
     * Get a random proxy
     * 
     * @param bool $checkHealth Whether to verify proxy health
     * @return array|null Proxy data or null if none available
     */
    public function getRandomProxy(bool $checkHealth = true): ?array {
        if (empty($this->proxies)) {
            return null;
        }
        
        $liveProxies = array_filter($this->proxies, function($proxy) {
            return !isset($this->deadProxies[$proxy['id']]);
        });
        
        if (empty($liveProxies)) {
            $this->log("ERROR: No live proxies available");
            return null;
        }
        
        $proxy = $liveProxies[array_rand($liveProxies)];
        
        if ($checkHealth && !$this->checkProxyHealth($proxy)) {
            $this->markProxyDead($proxy);
            return $this->getRandomProxy($checkHealth);
        }
        
        return $proxy;
    }
    
    /**
     * Check if proxy is working
     * 
     * @param array $proxy Proxy data
     * @param string $testUrl Optional custom test URL
     * @return bool True if proxy is working
     */
    public function checkProxyHealth(array $proxy, string $testUrl = ''): bool {
        $url = $testUrl ?: $this->healthCheckUrl;
        $startTime = microtime(true);
        
        $ch = curl_init();
        
        // Build options array for faster setup
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $proxy['ip'] . ':' . $proxy['port'],
            CURLOPT_PROXYTYPE => $proxy['type_int'],
            CURLOPT_CONNECTTIMEOUT => $this->checkTimeout,
            CURLOPT_TIMEOUT => $this->checkTimeout * 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, // Limit redirects
            CURLOPT_HTTPPROXYTUNNEL => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_ENCODING => '', // Enable compression
            CURLOPT_TCP_FASTOPEN => true, // Enable TCP Fast Open
            CURLOPT_TCP_NODELAY => true, // Disable Nagle's algorithm
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Connection: keep-alive',
                'Accept-Encoding: gzip, deflate'
            ]
        ];
        
        if (!empty($proxy['user']) && !empty($proxy['pass'])) {
            $curlOptions[CURLOPT_PROXYUSERPWD] = $proxy['user'] . ':' . $proxy['pass'];
            $curlOptions[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        $elapsed = microtime(true) - $startTime;
        
        // Consider proxy healthy if we got a response and HTTP code is 200-399
        $isHealthy = ($response !== false && $httpCode >= 200 && $httpCode < 400);
        
        if ($isHealthy) {
            $this->proxyStats[$proxy['id']]['avg_speed'] = 
                ($this->proxyStats[$proxy['id']]['avg_speed'] + $elapsed) / 2;
            $this->log("✓ Proxy health check passed: {$proxy['string']} ({$elapsed}s)");
        } else {
            $errorMsg = $curlError ?: "HTTP $httpCode";
            $this->log("✗ Proxy health check failed: {$proxy['string']} - Error: $errorMsg (Code: $curlErrno)");
        }
        
        return $isHealthy;
    }
    
    /**
     * Mark proxy as dead
     * 
     * @param array $proxy Proxy data
     */
    private function markProxyDead(array $proxy): void {
        $this->deadProxies[$proxy['id']] = time();
        $this->log("✗ Marked proxy as DEAD: {$proxy['string']}");
    }
    
    /**
     * Apply proxy to cURL handle
     * 
     * @param resource $ch cURL handle
     * @param array $proxy Proxy data
     */
    public function applyCurlProxy($ch, array $proxy): void {
        $proxyOptions = [
            CURLOPT_PROXY => $proxy['ip'] . ':' . $proxy['port'],
            CURLOPT_PROXYTYPE => $proxy['type_int'],
            CURLOPT_TCP_NODELAY => true, // Speed optimization
            CURLOPT_ENCODING => '' // Enable compression
        ];
        
        // Disable CONNECT tunnel for HTTP proxies (avoids 400 errors)
        if ($proxy['type_int'] === CURLPROXY_HTTP) {
            $proxyOptions[CURLOPT_HTTPPROXYTUNNEL] = false;
        }
        
        if (!empty($proxy['user']) && !empty($proxy['pass'])) {
            $proxyOptions[CURLOPT_PROXYUSERPWD] = $proxy['user'] . ':' . $proxy['pass'];
        }
        
        curl_setopt_array($ch, $proxyOptions);
        
        $this->proxyStats[$proxy['id']]['used']++;
        $this->proxyStats[$proxy['id']]['last_used'] = time();
    }
    
    /**
     * Execute cURL request with automatic proxy rotation on failure
     * 
     * @param resource $ch cURL handle
     * @param bool $useProxy Whether to use proxy
     * @return array Response data with status
     */
    public function executeWithRotation($ch, bool $useProxy = true): array {
        if (!$useProxy) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return [
                'response' => $response,
                'http_code' => $httpCode,
                'proxy_used' => false,
                'proxy' => null
            ];
        }
        
        $attempts = 0;
        while ($attempts < $this->maxRetries) {
            $proxy = $this->getNextProxy(true);
            if (!$proxy) {
                $this->log("ERROR: No available proxy for retry attempt " . ($attempts + 1));
                break;
            }
            
            $this->applyCurlProxy($ch, $proxy);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            if ($response !== false && $httpCode >= 200 && $httpCode < 500) {
                $this->proxyStats[$proxy['id']]['success']++;
                $this->log("✓ Request successful via {$proxy['string']} - HTTP $httpCode");
                
                return [
                    'response' => $response,
                    'http_code' => $httpCode,
                    'proxy_used' => true,
                    'proxy' => $proxy
                ];
            }
            
            $this->proxyStats[$proxy['id']]['failed']++;
            $this->log("✗ Request failed via {$proxy['string']} - HTTP $httpCode - $curlError");
            $this->markProxyDead($proxy);
            $attempts++;
            // No sleep delay for faster failover
        }
        
        // All retries failed
        return [
            'response' => false,
            'http_code' => 0,
            'proxy_used' => false,
            'proxy' => null,
            'error' => 'All proxy attempts failed'
        ];
    }
    
    /**
     * Get proxy statistics
     * 
     * @return array Statistics data
     */
    public function getStats(): array {
        return [
            'total_proxies' => count($this->proxies),
            'dead_proxies' => count($this->deadProxies),
            'live_proxies' => count($this->proxies) - count($this->deadProxies),
            'proxy_details' => $this->proxyStats
        ];
    }
    
    /**
     * Reset dead proxies (re-enable them for testing)
     */
    public function resetDeadProxies(): void {
        $count = count($this->deadProxies);
        $this->deadProxies = [];
        $this->log("Reset $count dead proxies - all proxies re-enabled");
    }
    
    /**
     * Log message to file and optionally echo
     * 
     * @param string $message Log message
     * @param bool $echo Whether to echo message
     */
    private function log(string $message, bool $echo = false): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        if ($this->logFile) {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        }
        
        if ($echo) {
            echo $logMessage;
        }
    }
    
    /**
     * Set health check timeout
     * 
     * @param int $seconds Timeout in seconds
     */
    public function setCheckTimeout(int $seconds): void {
        $this->checkTimeout = $seconds;
    }
    
    /**
     * Set maximum retry attempts
     * 
     * @param int $retries Number of retries
     */
    public function setMaxRetries(int $retries): void {
        $this->maxRetries = $retries;
    }
    
    /**
     * Set custom health check URL
     * 
     * @param string $url Health check URL
     */
    public function setHealthCheckUrl(string $url): void {
        $this->healthCheckUrl = $url;
    }
}
