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
    
    // Rate limiting detection and handling
    private $rateLimitedProxies = [];
    private $rateLimitCooldown = 60; // Seconds before retrying rate-limited proxy
    private $enableRateLimitDetection = true;
    private $autoRotateOnRateLimit = true;
    private $maxRateLimitRetries = 5; // Max retries specifically for rate limits
    private $rateLimitBackoff = [1, 2, 5, 10, 20]; // Exponential backoff in seconds
    
    // Proxy type constants
    const TYPE_HTTP = CURLPROXY_HTTP;
    const TYPE_HTTPS = CURLPROXY_HTTPS;
    const TYPE_SOCKS4 = CURLPROXY_SOCKS4;
    const TYPE_SOCKS5 = CURLPROXY_SOCKS5;
    const TYPE_TOR = CURLPROXY_SOCKS5; // Tor uses SOCKS5
    
    // Extended proxy type support
    private $supportedTypes = [
        'http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h',
        'residential', 'rotating', 'datacenter', 'mobile', 'isp'
    ];
    
    // Rotating proxy detection patterns
    private $rotatingProxyPatterns = [
        'rotating', 'rotate', 'backconnect', 'gateway', 'pool'
    ];
    
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
     * Check if proxy is a rotating proxy
     * 
     * @param string $proxyString Proxy string
     * @return bool Whether proxy is rotating type
     */
    private function isRotatingProxy(string $proxyString): bool {
        $lower = strtolower($proxyString);
        foreach ($this->rotatingProxyPatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Parse proxy string into structured array with support for all proxy types
     * 
     * @param string $proxyString Proxy string
     * @return array|null Parsed proxy data or null on failure
     */
    private function parseProxyString(string $proxyString): ?array {
        $originalString = $proxyString;
        $proxy_lower = strtolower(trim($proxyString));
        $type = 'http';
        $type_int = self::TYPE_HTTP;
        $originalType = null;
        $isRotating = $this->isRotatingProxy($proxyString);
        
        // Extract protocol prefix - support all types
        if (preg_match('/^(https?|socks[45]h?|socks[45]a?|tor|residential|rotating|datacenter|mobile|isp):\/\/(.+)$/', $proxy_lower, $matches)) {
            $type = $matches[1];
            $proxyString = $matches[2];
            
            // Track original type for special proxies
            if (in_array($type, ['residential', 'rotating', 'datacenter', 'mobile', 'isp'])) {
                $originalType = $type;
                $type = 'http'; // Map to HTTP for cURL compatibility
                $type_int = self::TYPE_HTTP;
                $isRotating = $isRotating || ($originalType === 'rotating');
            } else {
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
            'original_type' => $originalType ?? $type,
            'string' => ($originalType ? "$originalType://" : "$type://") . "$ip:$port" . (!empty($user) ? ":$user" : ""),
            'is_rotating' => $isRotating,
            'is_residential' => ($originalType === 'residential'),
            'is_datacenter' => ($originalType === 'datacenter'),
            'is_mobile' => ($originalType === 'mobile'),
            'is_isp' => ($originalType === 'isp')
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
     * Execute cURL request with automatic proxy rotation on failure and rate limiting
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
                'proxy' => null,
                'rate_limited' => false
            ];
        }
        
        $attempts = 0;
        $rateLimitAttempts = 0;
        $maxAttempts = $this->autoRotateOnRateLimit ? $this->maxRateLimitRetries : $this->maxRetries;
        
        while ($attempts < $maxAttempts) {
            $proxy = $this->getNextProxy(true);
            if (!$proxy) {
                $this->log("ERROR: No available proxy for retry attempt " . ($attempts + 1));
                break;
            }
            
            // Skip rate-limited proxies if they're in cooldown
            if ($this->isProxyRateLimited($proxy)) {
                $this->log("⏳ Skipping rate-limited proxy {$proxy['string']} (cooldown)");
                $attempts++;
                continue;
            }
            
            $this->applyCurlProxy($ch, $proxy);
            $startTime = microtime(true);
            $response = curl_exec($ch);
            $responseTime = microtime(true) - $startTime;
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $headers = [];
            
            // Get response headers if available
            if ($response !== false) {
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                if ($headerSize > 0) {
                    $headerText = substr($response, 0, $headerSize);
                    $headers = $this->parseHeaders($headerText);
                }
            }
            
            // Check for rate limiting
            if ($this->enableRateLimitDetection && $this->isRateLimited($httpCode, $response, $headers)) {
                $this->markProxyRateLimited($proxy);
                $rateLimitAttempts++;
                
                // Apply exponential backoff for rate limits
                if ($this->autoRotateOnRateLimit) {
                    $backoffIndex = min($rateLimitAttempts - 1, count($this->rateLimitBackoff) - 1);
                    $backoffTime = $this->rateLimitBackoff[$backoffIndex];
                    $this->log("⚠️ Rate limited via {$proxy['string']} - HTTP $httpCode - Rotating to next proxy (backoff: {$backoffTime}s)");
                    
                    // Optional: brief pause before trying next proxy
                    if ($backoffTime > 0 && $rateLimitAttempts > 2) {
                        sleep(min($backoffTime, 5)); // Max 5 second delay
                    }
                    
                    $attempts++;
                    continue; // Try next proxy
                } else {
                    $this->log("⚠️ Rate limited via {$proxy['string']} - HTTP $httpCode - Aborting");
                    return [
                        'response' => $response,
                        'http_code' => $httpCode,
                        'proxy_used' => true,
                        'proxy' => $proxy,
                        'rate_limited' => true,
                        'error' => 'Rate limit detected'
                    ];
                }
            }
            
            // Success condition
            if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
                $this->proxyStats[$proxy['id']]['success']++;
                $this->proxyStats[$proxy['id']]['avg_speed'] = 
                    ($this->proxyStats[$proxy['id']]['avg_speed'] + $responseTime) / 2;
                $this->log("✓ Request successful via {$proxy['string']} - HTTP $httpCode ({$responseTime}s)");
                
                // Clear rate limit flag if request succeeded
                $this->clearProxyRateLimit($proxy);
                
                return [
                    'response' => $response,
                    'http_code' => $httpCode,
                    'proxy_used' => true,
                    'proxy' => $proxy,
                    'rate_limited' => false,
                    'response_time' => $responseTime
                ];
            }
            
            // Handle client errors (4xx except 429)
            if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
                $this->log("⚠️ Client error via {$proxy['string']} - HTTP $httpCode (not a proxy issue)");
                return [
                    'response' => $response,
                    'http_code' => $httpCode,
                    'proxy_used' => true,
                    'proxy' => $proxy,
                    'rate_limited' => false,
                    'error' => 'Client error - not retrying'
                ];
            }
            
            // Failure - mark proxy and retry
            $this->proxyStats[$proxy['id']]['failed']++;
            $this->log("✗ Request failed via {$proxy['string']} - HTTP $httpCode - $curlError");
            
            // Only mark as dead if not rate limited (rate limits are temporary)
            if ($httpCode !== 429 && $httpCode !== 503) {
                $this->markProxyDead($proxy);
            }
            
            $attempts++;
        }
        
        // All retries failed
        $error = $rateLimitAttempts > 0 ? 'All proxies rate limited' : 'All proxy attempts failed';
        $this->log("❌ FAILED: $error after $attempts attempts");
        
        return [
            'response' => false,
            'http_code' => 0,
            'proxy_used' => false,
            'proxy' => null,
            'rate_limited' => $rateLimitAttempts > 0,
            'error' => $error
        ];
    }
    
    /**
     * Check if response indicates rate limiting
     * 
     * @param int $httpCode HTTP status code
     * @param mixed $response Response body
     * @param array $headers Response headers
     * @return bool Whether rate limit was detected
     */
    private function isRateLimited(int $httpCode, $response, array $headers): bool {
        // Check HTTP status codes
        if (in_array($httpCode, [429, 503])) {
            return true;
        }
        
        // Check for rate limit headers
        $rateLimitHeaders = [
            'x-ratelimit-remaining',
            'x-rate-limit-remaining',
            'ratelimit-remaining',
            'retry-after'
        ];
        
        foreach ($rateLimitHeaders as $header) {
            if (isset($headers[$header])) {
                $value = $headers[$header];
                // If remaining is 0 or very low
                if (is_numeric($value) && (int)$value === 0) {
                    return true;
                }
            }
        }
        
        // Check response body for rate limit indicators
        if (is_string($response)) {
            $rateLimitKeywords = [
                'rate limit',
                'too many requests',
                'request limit',
                'throttle',
                'slow down',
                'exceeded',
                'quota'
            ];
            
            $lowerResponse = strtolower($response);
            foreach ($rateLimitKeywords as $keyword) {
                if (strpos($lowerResponse, $keyword) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Parse response headers from header text
     * 
     * @param string $headerText Raw header text
     * @return array Parsed headers
     */
    private function parseHeaders(string $headerText): array {
        $headers = [];
        $lines = explode("\r\n", $headerText);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        return $headers;
    }
    
    /**
     * Mark proxy as rate-limited (temporary)
     * 
     * @param array $proxy Proxy info
     */
    private function markProxyRateLimited(array $proxy): void {
        $this->rateLimitedProxies[$proxy['id']] = time() + $this->rateLimitCooldown;
        if (!isset($this->proxyStats[$proxy['id']]['rate_limited'])) {
            $this->proxyStats[$proxy['id']]['rate_limited'] = 0;
        }
        $this->proxyStats[$proxy['id']]['rate_limited']++;
    }
    
    /**
     * Check if proxy is currently rate-limited
     * 
     * @param array $proxy Proxy info
     * @return bool Whether proxy is in rate limit cooldown
     */
    private function isProxyRateLimited(array $proxy): bool {
        if (!isset($this->rateLimitedProxies[$proxy['id']])) {
            return false;
        }
        
        $cooldownEnd = $this->rateLimitedProxies[$proxy['id']];
        $now = time();
        
        // If cooldown expired, clear it
        if ($now >= $cooldownEnd) {
            unset($this->rateLimitedProxies[$proxy['id']]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Clear rate limit flag for proxy
     * 
     * @param array $proxy Proxy info
     */
    private function clearProxyRateLimit(array $proxy): void {
        if (isset($this->rateLimitedProxies[$proxy['id']])) {
            unset($this->rateLimitedProxies[$proxy['id']]);
            $this->log("✓ Cleared rate limit for {$proxy['string']}");
        }
    }
    
    /**
     * Enable or disable automatic rate limit detection
     * 
     * @param bool $enabled Whether to enable detection
     */
    public function setRateLimitDetection(bool $enabled): void {
        $this->enableRateLimitDetection = $enabled;
    }
    
    /**
     * Enable or disable automatic proxy rotation on rate limit
     * 
     * @param bool $enabled Whether to auto-rotate
     */
    public function setAutoRotateOnRateLimit(bool $enabled): void {
        $this->autoRotateOnRateLimit = $enabled;
    }
    
    /**
     * Set rate limit cooldown period
     * 
     * @param int $seconds Cooldown in seconds
     */
    public function setRateLimitCooldown(int $seconds): void {
        $this->rateLimitCooldown = $seconds;
    }
    
    /**
     * Set maximum rate limit retry attempts
     * 
     * @param int $max Maximum attempts
     */
    public function setMaxRateLimitRetries(int $max): void {
        $this->maxRateLimitRetries = $max;
    }
    
    /**
     * Get proxy statistics including rate limit info
     * 
     * @return array Statistics data
     */
    public function getStats(): array {
        return [
            'total_proxies' => count($this->proxies),
            'dead_proxies' => count($this->deadProxies),
            'live_proxies' => count($this->proxies) - count($this->deadProxies),
            'rate_limited_proxies' => count($this->rateLimitedProxies),
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
