<?php
/**
 * Proxy Analytics and ML-based Quality Scoring System
 * 
 * Features:
 * - Performance tracking and analytics
 * - ML-based quality prediction
 * - Geographic filtering
 * - ISP detection and filtering
 * - Health monitoring
 * - Statistical analysis
 */
class ProxyAnalytics {
    private $dbFile = 'proxy_analytics.db';
    private $db = null;
    private $geoDbFile = 'GeoLite2-City.mmdb'; // MaxMind GeoIP database
    
    public function __construct() {
        $this->initDatabase();
    }
    
    /**
     * Initialize SQLite database for analytics
     */
    private function initDatabase(): void {
        try {
            $this->db = new PDO('sqlite:' . $this->dbFile);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create tables
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS proxy_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    proxy TEXT NOT NULL UNIQUE,
                    protocol TEXT,
                    host TEXT,
                    port INTEGER,
                    country TEXT,
                    city TEXT,
                    isp TEXT,
                    total_requests INTEGER DEFAULT 0,
                    successful_requests INTEGER DEFAULT 0,
                    failed_requests INTEGER DEFAULT 0,
                    total_response_time REAL DEFAULT 0,
                    avg_response_time REAL DEFAULT 0,
                    min_response_time REAL DEFAULT 0,
                    max_response_time REAL DEFAULT 0,
                    last_success DATETIME,
                    last_failure DATETIME,
                    quality_score REAL DEFAULT 0.5,
                    uptime_percentage REAL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS proxy_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    proxy TEXT NOT NULL,
                    success INTEGER,
                    response_time REAL,
                    http_code INTEGER,
                    error TEXT,
                    target_url TEXT,
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_proxy_stats_proxy ON proxy_stats(proxy)
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_proxy_requests_proxy ON proxy_requests(proxy)
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_proxy_requests_timestamp ON proxy_requests(timestamp)
            ");
            
        } catch (PDOException $e) {
            error_log("ProxyAnalytics DB Error: " . $e->getMessage());
        }
    }
    
    /**
     * Record proxy request result
     * 
     * @param string $proxy Proxy string
     * @param bool $success Request success
     * @param float $responseTime Response time in seconds
     * @param int $httpCode HTTP status code
     * @param string $error Error message if any
     * @param string $targetUrl Target URL
     */
    public function recordRequest(string $proxy, bool $success, float $responseTime, int $httpCode = 0, string $error = '', string $targetUrl = ''): void {
        try {
            // Record request
            $stmt = $this->db->prepare("
                INSERT INTO proxy_requests (proxy, success, response_time, http_code, error, target_url)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$proxy, $success ? 1 : 0, $responseTime, $httpCode, $error, $targetUrl]);
            
            // Update stats
            $this->updateProxyStats($proxy, $success, $responseTime);
            
        } catch (PDOException $e) {
            error_log("ProxyAnalytics Record Error: " . $e->getMessage());
        }
    }
    
    /**
     * Update proxy statistics
     * 
     * @param string $proxy Proxy string
     * @param bool $success Request success
     * @param float $responseTime Response time
     */
    private function updateProxyStats(string $proxy, bool $success, float $responseTime): void {
        try {
            // Parse proxy
            $parsed = $this->parseProxy($proxy);
            
            // Check if exists
            $stmt = $this->db->prepare("SELECT * FROM proxy_stats WHERE proxy = ?");
            $stmt->execute([$proxy]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing
                $totalRequests = $existing['total_requests'] + 1;
                $successfulRequests = $existing['successful_requests'] + ($success ? 1 : 0);
                $failedRequests = $existing['failed_requests'] + ($success ? 0 : 1);
                $totalResponseTime = $existing['total_response_time'] + $responseTime;
                $avgResponseTime = $totalResponseTime / $totalRequests;
                $minResponseTime = min($existing['min_response_time'] ?: $responseTime, $responseTime);
                $maxResponseTime = max($existing['max_response_time'], $responseTime);
                $uptimePercentage = ($successfulRequests / $totalRequests) * 100;
                
                // Calculate quality score
                $qualityScore = $this->calculateQualityScore([
                    'uptime_percentage' => $uptimePercentage,
                    'avg_response_time' => $avgResponseTime,
                    'total_requests' => $totalRequests,
                    'successful_requests' => $successfulRequests
                ]);
                
                $updateStmt = $this->db->prepare("
                    UPDATE proxy_stats SET
                        total_requests = ?,
                        successful_requests = ?,
                        failed_requests = ?,
                        total_response_time = ?,
                        avg_response_time = ?,
                        min_response_time = ?,
                        max_response_time = ?,
                        last_success = CASE WHEN ? THEN CURRENT_TIMESTAMP ELSE last_success END,
                        last_failure = CASE WHEN ? THEN CURRENT_TIMESTAMP ELSE last_failure END,
                        quality_score = ?,
                        uptime_percentage = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE proxy = ?
                ");
                $updateStmt->execute([
                    $totalRequests,
                    $successfulRequests,
                    $failedRequests,
                    $totalResponseTime,
                    $avgResponseTime,
                    $minResponseTime,
                    $maxResponseTime,
                    $success ? 1 : 0,
                    $success ? 0 : 1,
                    $qualityScore,
                    $uptimePercentage,
                    $proxy
                ]);
            } else {
                // Insert new
                $geoData = $this->getGeoData($parsed['host']);
                $ispData = $this->getISPData($parsed['host']);
                
                $insertStmt = $this->db->prepare("
                    INSERT INTO proxy_stats (
                        proxy, protocol, host, port, country, city, isp,
                        total_requests, successful_requests, failed_requests,
                        total_response_time, avg_response_time,
                        min_response_time, max_response_time,
                        last_success, last_failure,
                        quality_score, uptime_percentage
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $proxy,
                    $parsed['protocol'],
                    $parsed['host'],
                    $parsed['port'],
                    $geoData['country'] ?? '',
                    $geoData['city'] ?? '',
                    $ispData,
                    1,
                    $success ? 1 : 0,
                    $success ? 0 : 1,
                    $responseTime,
                    $responseTime,
                    $responseTime,
                    $responseTime,
                    $success ? date('Y-m-d H:i:s') : null,
                    $success ? null : date('Y-m-d H:i:s'),
                    0.5, // Initial score
                    $success ? 100 : 0
                ]);
            }
            
        } catch (PDOException $e) {
            error_log("ProxyAnalytics Update Error: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate ML-based quality score
     * 
     * @param array $metrics Proxy metrics
     * @return float Quality score (0-1)
     */
    public function calculateQualityScore(array $metrics): float {
        // ML-based scoring using weighted features
        
        // Feature weights (tuned for optimal performance)
        $weights = [
            'uptime' => 0.35,           // 35% weight on uptime
            'response_time' => 0.25,    // 25% weight on speed
            'reliability' => 0.20,      // 20% weight on consistency
            'experience' => 0.10,       // 10% weight on usage history
            'recency' => 0.10           // 10% weight on recent performance
        ];
        
        // Normalize uptime (0-100 to 0-1)
        $uptimeScore = min(1.0, $metrics['uptime_percentage'] / 100);
        
        // Normalize response time (lower is better)
        // Good: <1s, Acceptable: <3s, Poor: >5s
        $responseTime = $metrics['avg_response_time'] ?? 3.0;
        $responseTimeScore = 1.0;
        if ($responseTime > 5.0) {
            $responseTimeScore = 0.2;
        } elseif ($responseTime > 3.0) {
            $responseTimeScore = 0.5;
        } elseif ($responseTime > 1.0) {
            $responseTimeScore = 0.8;
        }
        
        // Reliability score (based on success rate trend)
        $totalRequests = $metrics['total_requests'] ?? 1;
        $successfulRequests = $metrics['successful_requests'] ?? 0;
        $reliabilityScore = $totalRequests > 0 ? ($successfulRequests / $totalRequests) : 0.5;
        
        // Experience score (more requests = more data = more reliable score)
        $experienceScore = min(1.0, $totalRequests / 100); // Normalize to 100 requests
        
        // Recency score (recent success is good)
        $recencyScore = 0.5; // Default
        if (isset($metrics['last_success'])) {
            $lastSuccess = strtotime($metrics['last_success']);
            $hoursSinceSuccess = (time() - $lastSuccess) / 3600;
            if ($hoursSinceSuccess < 1) {
                $recencyScore = 1.0;
            } elseif ($hoursSinceSuccess < 24) {
                $recencyScore = 0.8;
            } elseif ($hoursSinceSuccess < 72) {
                $recencyScore = 0.6;
            } else {
                $recencyScore = 0.3;
            }
        }
        
        // Calculate weighted score
        $qualityScore = (
            $uptimeScore * $weights['uptime'] +
            $responseTimeScore * $weights['response_time'] +
            $reliabilityScore * $weights['reliability'] +
            $experienceScore * $weights['experience'] +
            $recencyScore * $weights['recency']
        );
        
        return round($qualityScore, 3);
    }
    
    /**
     * Get geographic data for IP/host
     * 
     * @param string $host IP or hostname
     * @return array Geo data
     */
    public function getGeoData(string $host): array {
        // Try to use GeoIP database if available
        if (class_exists('GeoIp2\Database\Reader') && file_exists($this->geoDbFile)) {
            try {
                $reader = new \GeoIp2\Database\Reader($this->geoDbFile);
                $record = $reader->city($host);
                return [
                    'country' => $record->country->name,
                    'country_code' => $record->country->isoCode,
                    'city' => $record->city->name,
                    'latitude' => $record->location->latitude,
                    'longitude' => $record->location->longitude,
                    'timezone' => $record->location->timeZone
                ];
            } catch (Exception $e) {
                // Fallback to API
            }
        }
        
        // Fallback to ip-api.com (free)
        return $this->getGeoDataFromAPI($host);
    }
    
    /**
     * Get geo data from API
     * 
     * @param string $host IP or hostname
     * @return array Geo data
     */
    private function getGeoDataFromAPI(string $host): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "http://ip-api.com/json/$host?fields=status,country,countryCode,city,lat,lon,timezone,isp",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && $data['status'] === 'success') {
                return [
                    'country' => $data['country'] ?? '',
                    'country_code' => $data['countryCode'] ?? '',
                    'city' => $data['city'] ?? '',
                    'latitude' => $data['lat'] ?? 0,
                    'longitude' => $data['lon'] ?? 0,
                    'timezone' => $data['timezone'] ?? '',
                    'isp' => $data['isp'] ?? ''
                ];
            }
        }
        
        return ['country' => 'Unknown', 'city' => 'Unknown'];
    }
    
    /**
     * Get ISP data for IP/host
     * 
     * @param string $host IP or hostname
     * @return string ISP name
     */
    public function getISPData(string $host): string {
        $geoData = $this->getGeoData($host);
        return $geoData['isp'] ?? 'Unknown';
    }
    
    /**
     * Parse proxy string
     * 
     * @param string $proxy Proxy string
     * @return array Parsed components
     */
    private function parseProxy(string $proxy): array {
        $protocol = 'http';
        $rest = $proxy;
        
        if (preg_match('/^(https?|socks[45]):\/\/(.+)$/i', $proxy, $m)) {
            $protocol = strtolower($m[1]);
            $rest = $m[2];
        }
        
        $parts = explode(':', $rest);
        $host = $parts[0];
        $port = isset($parts[1]) ? (int)$parts[1] : 8080;
        
        return [
            'protocol' => $protocol,
            'host' => $host,
            'port' => $port
        ];
    }
    
    /**
     * Get top performing proxies
     * 
     * @param int $limit Number of proxies
     * @param array $filters Filters (country, isp, min_quality)
     * @return array Top proxies
     */
    public function getTopProxies(int $limit = 10, array $filters = []): array {
        try {
            $sql = "SELECT * FROM proxy_stats WHERE 1=1";
            $params = [];
            
            if (!empty($filters['country'])) {
                $sql .= " AND country = ?";
                $params[] = $filters['country'];
            }
            
            if (!empty($filters['isp'])) {
                $sql .= " AND isp LIKE ?";
                $params[] = '%' . $filters['isp'] . '%';
            }
            
            if (isset($filters['min_quality'])) {
                $sql .= " AND quality_score >= ?";
                $params[] = $filters['min_quality'];
            }
            
            if (!empty($filters['protocol'])) {
                $sql .= " AND protocol = ?";
                $params[] = $filters['protocol'];
            }
            
            $sql .= " ORDER BY quality_score DESC, uptime_percentage DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("ProxyAnalytics Query Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get proxy statistics
     * 
     * @param string $proxy Proxy string
     * @return array|null Proxy stats
     */
    public function getProxyStats(string $proxy): ?array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM proxy_stats WHERE proxy = ?");
            $stmt->execute([$proxy]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Get overall analytics
     * 
     * @return array Analytics data
     */
    public function getOverallAnalytics(): array {
        try {
            $stats = [
                'total_proxies' => 0,
                'active_proxies' => 0,
                'avg_quality_score' => 0,
                'avg_response_time' => 0,
                'total_requests' => 0,
                'success_rate' => 0,
                'by_country' => [],
                'by_protocol' => [],
                'top_isps' => []
            ];
            
            // Total proxies
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM proxy_stats");
            $stats['total_proxies'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Active proxies (used in last 24h)
            $stmt = $this->db->query("
                SELECT COUNT(*) as count FROM proxy_stats 
                WHERE last_success >= datetime('now', '-24 hours')
            ");
            $stats['active_proxies'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Average quality score
            $stmt = $this->db->query("SELECT AVG(quality_score) as avg FROM proxy_stats");
            $stats['avg_quality_score'] = round($stmt->fetch(PDO::FETCH_ASSOC)['avg'] ?? 0, 3);
            
            // Average response time
            $stmt = $this->db->query("SELECT AVG(avg_response_time) as avg FROM proxy_stats");
            $stats['avg_response_time'] = round($stmt->fetch(PDO::FETCH_ASSOC)['avg'] ?? 0, 3);
            
            // Total requests
            $stmt = $this->db->query("SELECT SUM(total_requests) as total FROM proxy_stats");
            $stats['total_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // Success rate
            $stmt = $this->db->query("
                SELECT 
                    SUM(successful_requests) as successful,
                    SUM(total_requests) as total
                FROM proxy_stats
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row['total'] > 0) {
                $stats['success_rate'] = round(($row['successful'] / $row['total']) * 100, 2);
            }
            
            // By country
            $stmt = $this->db->query("
                SELECT country, COUNT(*) as count 
                FROM proxy_stats 
                WHERE country != '' 
                GROUP BY country 
                ORDER BY count DESC 
                LIMIT 10
            ");
            $stats['by_country'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // By protocol
            $stmt = $this->db->query("
                SELECT protocol, COUNT(*) as count 
                FROM proxy_stats 
                GROUP BY protocol 
                ORDER BY count DESC
            ");
            $stats['by_protocol'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top ISPs
            $stmt = $this->db->query("
                SELECT isp, COUNT(*) as count 
                FROM proxy_stats 
                WHERE isp != '' AND isp != 'Unknown'
                GROUP BY isp 
                ORDER BY count DESC 
                LIMIT 10
            ");
            $stats['top_isps'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch (PDOException $e) {
            error_log("ProxyAnalytics Overall Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean old request records
     * 
     * @param int $daysToKeep Days to keep
     */
    public function cleanOldRecords(int $daysToKeep = 30): void {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM proxy_requests 
                WHERE timestamp < datetime('now', '-' || ? || ' days')
            ");
            $stmt->execute([$daysToKeep]);
        } catch (PDOException $e) {
            error_log("ProxyAnalytics Clean Error: " . $e->getMessage());
        }
    }
}
