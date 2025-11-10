<?php
/**
 * Auto Proxy Fetcher - Automatically fetch working proxies when needed
 * 
 * Features:
 * - Automatically fetch proxies when ProxyList.txt is empty or has few proxies
 * - Background fetching without blocking main process
 * - Caching to avoid repeated fetches
 * - Integration with fetch_proxies.php
 */
class AutoProxyFetcher {
    private $proxyFile = 'ProxyList.txt';
    private $minProxiesThreshold = 5;
    private $fetchTimeout = 120; // 2 minutes max for fetching
    private $cacheDuration = 1800; // 30 minutes cache
    private $lastFetchFile = 'last_proxy_fetch.txt';
    private $debugMode = false;
    
    public function __construct(array $options = []) {
        $this->proxyFile = $options['proxyFile'] ?? 'ProxyList.txt';
        $this->minProxiesThreshold = $options['minProxies'] ?? 5;
        $this->fetchTimeout = $options['fetchTimeout'] ?? 120;
        $this->debugMode = $options['debug'] ?? false;
    }
    
    /**
     * Check if proxies need to be fetched
     * 
     * @return bool True if fetch is needed
     */
    public function needsFetch(): bool {
        // Check if proxy file exists and has enough proxies
        if (!file_exists($this->proxyFile)) {
            $this->log("Proxy file not found, fetch needed");
            return true;
        }
        
        $proxies = $this->getWorkingProxies();
        $count = count($proxies);
        
        if ($count < $this->minProxiesThreshold) {
            $this->log("Only $count proxies available (threshold: {$this->minProxiesThreshold}), fetch needed");
            return true;
        }
        
        // Check if last fetch was too long ago
        if ($this->shouldRefetch()) {
            $this->log("Cache expired, refresh needed");
            return true;
        }
        
        return false;
    }
    
    /**
     * Get working proxies from file
     * 
     * @return array Working proxies
     */
    public function getWorkingProxies(): array {
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
     * Check if refetch is needed based on cache time
     * 
     * @return bool True if refetch is needed
     */
    private function shouldRefetch(): bool {
        if (!file_exists($this->lastFetchFile)) {
            return true;
        }
        
        $lastFetch = (int)file_get_contents($this->lastFetchFile);
        $elapsed = time() - $lastFetch;
        
        return $elapsed > $this->cacheDuration;
    }
    
    /**
     * Fetch proxies automatically
     * 
     * @param array $options Fetch options (protocols, count, timeout, etc.)
     * @return array Result with status and stats
     */
    public function fetchProxies(array $options = []): array {
        $this->log("Starting automatic proxy fetch...");
        
        // Default options
        $protocols = $options['protocols'] ?? 'http,https,socks4,socks5';
        $count = $options['count'] ?? 50; // Target working proxies
        $timeout = $options['timeout'] ?? 3;
        $concurrency = $options['concurrency'] ?? 200;
        $sources = $options['sources'] ?? 'builtin,github,proxyscrape';
        
        // Build fetch URL
        $baseUrl = $this->getBaseUrl();
        $fetchUrl = $baseUrl . 'fetch_proxies.php?' . http_build_query([
            'api' => '1',
            'protocols' => $protocols,
            'count' => $count,
            'timeout' => $timeout,
            'concurrency' => $concurrency,
            'sources' => $sources,
        ]);
        
        $this->log("Fetching from: $fetchUrl");
        
        // Execute fetch request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fetchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->fetchTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            $this->log("Fetch failed: HTTP $httpCode - $error");
            return [
                'success' => false,
                'error' => "Failed to fetch proxies: $error",
                'http_code' => $httpCode
            ];
        }
        
        // Parse response
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['success'])) {
            $this->log("Invalid response from fetch endpoint");
            return [
                'success' => false,
                'error' => 'Invalid response from fetch endpoint'
            ];
        }
        
        if (!$data['success']) {
            $this->log("Fetch returned failure: " . ($data['message'] ?? 'Unknown error'));
            return [
                'success' => false,
                'error' => $data['message'] ?? 'Unknown error',
                'stats' => $data['stats'] ?? []
            ];
        }
        
        // Update last fetch time
        file_put_contents($this->lastFetchFile, time());
        
        $proxyCount = count($data['proxies'] ?? []);
        $this->log("Successfully fetched $proxyCount working proxies");
        
        return [
            'success' => true,
            'proxies' => $data['proxies'] ?? [],
            'stats' => $data['stats'] ?? [],
            'count' => $proxyCount
        ];
    }
    
    /**
     * Ensure proxies are available, fetch if needed
     * 
     * @param bool $force Force fetch even if proxies are available
     * @return array Result with status
     */
    public function ensureProxies(bool $force = false): array {
        if (!$force && !$this->needsFetch()) {
            $proxies = $this->getWorkingProxies();
            $this->log("Proxies already available: " . count($proxies));
            return [
                'success' => true,
                'fetched' => false,
                'count' => count($proxies),
                'message' => 'Proxies already available'
            ];
        }
        
        $result = $this->fetchProxies();
        
        return [
            'success' => $result['success'],
            'fetched' => true,
            'count' => $result['count'] ?? 0,
            'stats' => $result['stats'] ?? [],
            'error' => $result['error'] ?? null
        ];
    }
    
    /**
     * Get base URL of the application
     * 
     * @return string Base URL
     */
    private function getBaseUrl(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $base = rtrim($protocol . '://' . $host . $scriptDir, '/') . '/';
        return $base;
    }
    
    /**
     * Log message
     * 
     * @param string $message Log message
     */
    private function log(string $message): void {
        if ($this->debugMode) {
            error_log("[AutoProxyFetcher] $message");
        }
    }
    
    /**
     * Get proxy stats
     * 
     * @return array Proxy statistics
     */
    public function getStats(): array {
        $proxies = $this->getWorkingProxies();
        $lastFetch = file_exists($this->lastFetchFile) 
            ? (int)file_get_contents($this->lastFetchFile) 
            : null;
        
        return [
            'total_proxies' => count($proxies),
            'last_fetch' => $lastFetch,
            'last_fetch_human' => $lastFetch ? date('Y-m-d H:i:s', $lastFetch) : 'Never',
            'cache_expires_in' => $lastFetch ? max(0, $this->cacheDuration - (time() - $lastFetch)) : 0,
            'needs_fetch' => $this->needsFetch()
        ];
    }
}
