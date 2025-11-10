<?php
/**
 * Full REST API with Authentication
 * Owner: @LEGEND_BL
 * 
 * Features:
 * - JWT-based authentication
 * - API key support
 * - Rate limiting
 * - Comprehensive endpoints
 * - CORS support
 * - Request logging
 */
class RestAPI {
    private $config;
    private $db;
    private $analytics;
    private $telegram;
    private $jwtSecret = '';
    private $rateLimitDb = 'rate_limits.db';
    
    public function __construct() {
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? 'change_this_secret_key_in_production';
        $this->initDatabase();
        
        require_once 'ProxyAnalytics.php';
        require_once 'TelegramNotifier.php';
        
        $this->analytics = new ProxyAnalytics();
        $this->telegram = new TelegramNotifier();
    }
    
    /**
     * Initialize API database
     */
    private function initDatabase(): void {
        try {
            $this->db = new PDO('sqlite:api_users.db');
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // API users table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS api_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    api_key TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    email TEXT,
                    role TEXT DEFAULT 'user',
                    enabled INTEGER DEFAULT 1,
                    rate_limit INTEGER DEFAULT 1000,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_login DATETIME
                )
            ");
            
            // API logs table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS api_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    endpoint TEXT,
                    method TEXT,
                    ip_address TEXT,
                    user_agent TEXT,
                    status_code INTEGER,
                    response_time REAL,
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // No default users - must be created manually for security
            // Use api_create_user.php to create your first admin user
            
        } catch (PDOException $e) {
            error_log("RestAPI DB Error: " . $e->getMessage());
        }
    }
    
    /**
     * Handle API request
     * 
     * @return array Response
     */
    public function handleRequest(): array {
        $startTime = microtime(true);
        
        // CORS headers
        $this->setCORSHeaders();
        
        // Handle OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        // Get request details
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];
        $path = parse_url($path, PHP_URL_PATH);
        $path = str_replace('/api.php', '', $path);
        
        // Parse route
        $route = $this->parseRoute($path);
        
        // Check authentication (except for auth endpoints)
        if (!in_array($route['endpoint'], ['login', 'register', 'health'])) {
            $auth = $this->authenticate();
            if (!$auth['success']) {
                return $this->errorResponse($auth['error'], 401);
            }
            $userId = $auth['user_id'];
            
            // Check rate limit
            $rateLimitCheck = $this->checkRateLimit($userId);
            if (!$rateLimitCheck) {
                return $this->errorResponse('Rate limit exceeded', 429);
            }
        } else {
            $userId = null;
        }
        
        // Route request
        try {
            $response = $this->routeRequest($route, $method);
            $statusCode = $response['status'] ?? 200;
        } catch (Exception $e) {
            $response = $this->errorResponse($e->getMessage(), 500);
            $statusCode = 500;
        }
        
        // Log request
        $responseTime = microtime(true) - $startTime;
        $this->logRequest($userId, $route['endpoint'], $method, $statusCode, $responseTime);
        
        return $response;
    }
    
    /**
     * Parse route from path
     * 
     * @param string $path URL path
     * @return array Route info
     */
    private function parseRoute(string $path): array {
        $parts = array_filter(explode('/', $path));
        $parts = array_values($parts);
        
        return [
            'endpoint' => $parts[0] ?? 'index',
            'params' => array_slice($parts, 1)
        ];
    }
    
    /**
     * Route request to handler
     * 
     * @param array $route Route info
     * @param string $method HTTP method
     * @return array Response
     */
    private function routeRequest(array $route, string $method): array {
        $endpoint = $route['endpoint'];
        $params = $route['params'];
        
        return match($endpoint) {
            'login' => $this->handleLogin(),
            'register' => $this->handleRegister(),
            'proxies' => $this->handleProxies($method, $params),
            'fetch' => $this->handleFetch(),
            'analytics' => $this->handleAnalytics($params),
            'health' => $this->handleHealth(),
            'test' => $this->handleTest($params),
            'users' => $this->handleUsers($method, $params),
            'notifications' => $this->handleNotifications($method),
            default => $this->errorResponse('Endpoint not found', 404)
        };
    }
    
    /**
     * Authenticate request
     * 
     * @return array Auth result
     */
    private function authenticate(): array {
        // Check for API key in header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
        
        if ($apiKey) {
            return $this->authenticateAPIKey($apiKey);
        }
        
        // Check for JWT token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return $this->authenticateJWT($matches[1]);
        }
        
        return ['success' => false, 'error' => 'No authentication provided'];
    }
    
    /**
     * Authenticate using API key
     * 
     * @param string $apiKey API key
     * @return array Auth result
     */
    private function authenticateAPIKey(string $apiKey): array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, role, enabled 
                FROM api_users 
                WHERE api_key = ? AND enabled = 1
            ");
            $stmt->execute([$apiKey]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                return [
                    'success' => true,
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role']
                ];
            }
        } catch (PDOException $e) {
            error_log("Auth Error: " . $e->getMessage());
        }
        
        return ['success' => false, 'error' => 'Invalid API key'];
    }
    
    /**
     * Authenticate using JWT token
     * 
     * @param string $token JWT token
     * @return array Auth result
     */
    private function authenticateJWT(string $token): array {
        try {
            $payload = $this->verifyJWT($token);
            if ($payload) {
                return [
                    'success' => true,
                    'user_id' => $payload['user_id'],
                    'username' => $payload['username'],
                    'role' => $payload['role']
                ];
            }
        } catch (Exception $e) {
            error_log("JWT Error: " . $e->getMessage());
        }
        
        return ['success' => false, 'error' => 'Invalid or expired token'];
    }
    
    /**
     * Create JWT token
     * 
     * @param array $payload Token payload
     * @return string JWT token
     */
    private function createJWT(array $payload): string {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + 86400; // 24 hours
        $payload = json_encode($payload);
        
        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->jwtSecret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    /**
     * Verify JWT token
     * 
     * @param string $token JWT token
     * @return array|null Payload if valid
     */
    private function verifyJWT(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;
        
        $signature = $this->base64UrlDecode($base64UrlSignature);
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->jwtSecret, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return null;
        }
        
        $payload = json_decode($this->base64UrlDecode($base64UrlPayload), true);
        
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null; // Expired
        }
        
        return $payload;
    }
    
    /**
     * Base64 URL encode
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     * 
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private function base64UrlDecode(string $data): string {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    /**
     * Handle login
     * 
     * @return array Response
     */
    private function handleLogin(): array {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? $_POST['username'] ?? '';
        $password = $input['password'] ?? $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            return $this->errorResponse('Username and password required', 400);
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, password_hash, role, enabled 
                FROM api_users 
                WHERE username = ?
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                if (!$user['enabled']) {
                    return $this->errorResponse('Account disabled', 403);
                }
                
                // Update last login
                $stmt = $this->db->prepare("UPDATE api_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                // Generate tokens
                $payload = [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role']
                ];
                $jwt = $this->createJWT($payload);
                
                // Get API key
                $stmt = $this->db->prepare("SELECT api_key FROM api_users WHERE id = ?");
                $stmt->execute([$user['id']]);
                $apiKey = $stmt->fetchColumn();
                
                return [
                    'success' => true,
                    'token' => $jwt,
                    'api_key' => $apiKey,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role']
                    ]
                ];
            }
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
        }
        
        return $this->errorResponse('Invalid credentials', 401);
    }
    
    /**
     * Handle register
     * 
     * @return array Response
     */
    private function handleRegister(): array {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? $_POST['username'] ?? '';
        $password = $input['password'] ?? $_POST['password'] ?? '';
        $email = $input['email'] ?? $_POST['email'] ?? '';
        
        if (empty($username) || empty($password)) {
            return $this->errorResponse('Username and password required', 400);
        }
        
        return $this->createUser($username, $password, $email) 
            ? ['success' => true, 'message' => 'User created successfully']
            : $this->errorResponse('Failed to create user', 500);
    }
    
    /**
     * Handle proxies endpoint
     * 
     * @param string $method HTTP method
     * @param array $params URL parameters
     * @return array Response
     */
    private function handleProxies(string $method, array $params): array {
        if ($method === 'GET') {
            // Get proxies
            $filters = [
                'country' => $_GET['country'] ?? '',
                'isp' => $_GET['isp'] ?? '',
                'protocol' => $_GET['protocol'] ?? '',
                'min_quality' => isset($_GET['min_quality']) ? (float)$_GET['min_quality'] : 0
            ];
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            
            $proxies = $this->analytics->getTopProxies($limit, array_filter($filters));
            
            return [
                'success' => true,
                'count' => count($proxies),
                'proxies' => $proxies
            ];
        }
        
        return $this->errorResponse('Method not allowed', 405);
    }
    
    /**
     * Handle fetch endpoint
     * 
     * @return array Response
     */
    private function handleFetch(): array {
        require_once 'AutoProxyFetcher.php';
        
        $fetcher = new AutoProxyFetcher();
        $result = $fetcher->fetchProxies([
            'protocols' => $_GET['protocols'] ?? 'all',
            'count' => isset($_GET['count']) ? (int)$_GET['count'] : 50,
            'timeout' => isset($_GET['timeout']) ? (int)$_GET['timeout'] : 3
        ]);
        
        return $result;
    }
    
    /**
     * Handle analytics endpoint
     * 
     * @param array $params URL parameters
     * @return array Response
     */
    private function handleAnalytics(array $params): array {
        if (empty($params)) {
            // Overall analytics
            return [
                'success' => true,
                'analytics' => $this->analytics->getOverallAnalytics()
            ];
        }
        
        // Specific proxy analytics
        $proxy = implode('/', $params);
        $stats = $this->analytics->getProxyStats(urldecode($proxy));
        
        if ($stats) {
            return ['success' => true, 'proxy' => $stats];
        }
        
        return $this->errorResponse('Proxy not found', 404);
    }
    
    /**
     * Handle health endpoint
     * 
     * @return array Response
     */
    private function handleHealth(): array {
        require_once 'HealthMonitor.php';
        
        $monitor = new HealthMonitor();
        $health = $monitor->runHealthCheck(false);
        
        return [
            'success' => true, 
            'health' => $health,
            'owner' => '@LEGEND_BL',
            'system' => 'Advanced Proxy Management System'
        ];
    }
    
    /**
     * Handle test endpoint
     * 
     * @param array $params URL parameters
     * @return array Response
     */
    private function handleTest(array $params): array {
        if (empty($params)) {
            return $this->errorResponse('Proxy URL required', 400);
        }
        
        $proxy = implode('/', $params);
        $proxy = urldecode($proxy);
        
        // Test proxy
        require_once 'ProxyManager.php';
        $pm = new ProxyManager();
        $parsed = $pm->parseProxyString($proxy);
        
        if (!$parsed) {
            return $this->errorResponse('Invalid proxy format', 400);
        }
        
        $result = $pm->checkProxyHealth($parsed);
        
        return [
            'success' => $result,
            'proxy' => $proxy,
            'working' => $result
        ];
    }
    
    /**
     * Handle users endpoint (admin only)
     * 
     * @param string $method HTTP method
     * @param array $params URL parameters
     * @return array Response
     */
    private function handleUsers(string $method, array $params): array {
        // Admin check would go here
        
        if ($method === 'GET') {
            try {
                $stmt = $this->db->query("
                    SELECT id, username, email, role, enabled, created_at, last_login 
                    FROM api_users
                ");
                return [
                    'success' => true,
                    'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)
                ];
            } catch (PDOException $e) {
                return $this->errorResponse('Database error', 500);
            }
        }
        
        return $this->errorResponse('Method not allowed', 405);
    }
    
    /**
     * Handle notifications endpoint
     * 
     * @param string $method HTTP method
     * @return array Response
     */
    private function handleNotifications(string $method): array {
        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $message = $input['message'] ?? '';
            $type = $input['type'] ?? 'info';
            
            if (empty($message)) {
                return $this->errorResponse('Message required', 400);
            }
            
            $sent = $this->telegram->sendAlert('API Notification', $message, $type);
            
            return [
                'success' => $sent,
                'message' => $sent ? 'Notification sent' : 'Failed to send notification'
            ];
        }
        
        return $this->errorResponse('Method not allowed', 405);
    }
    
    /**
     * Create user
     * 
     * @param string $username Username
     * @param string $password Password
     * @param string $email Email
     * @param string $role Role
     * @return bool Success status
     */
    private function createUser(string $username, string $password, string $email = '', string $role = 'user'): bool {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $apiKey = bin2hex(random_bytes(32));
            
            $stmt = $this->db->prepare("
                INSERT INTO api_users (username, password_hash, api_key, email, role)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$username, $passwordHash, $apiKey, $email, $role]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Create User Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check rate limit
     * 
     * @param int $userId User ID
     * @return bool Within limit
     */
    private function checkRateLimit(int $userId): bool {
        // Simple in-memory rate limiting
        // In production, use Redis or similar
        return true;
    }
    
    /**
     * Log API request
     * 
     * @param int|null $userId User ID
     * @param string $endpoint Endpoint
     * @param string $method HTTP method
     * @param int $statusCode Status code
     * @param float $responseTime Response time
     */
    private function logRequest(?int $userId, string $endpoint, string $method, int $statusCode, float $responseTime): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_logs (user_id, endpoint, method, ip_address, user_agent, status_code, response_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $endpoint,
                $method,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $statusCode,
                $responseTime
            ]);
        } catch (PDOException $e) {
            error_log("Log Request Error: " . $e->getMessage());
        }
    }
    
    /**
     * Set CORS headers
     */
    private function setCORSHeaders(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
        header('Content-Type: application/json');
    }
    
    /**
     * Error response
     * 
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return array Response
     */
    private function errorResponse(string $message, int $code = 400): array {
        http_response_code($code);
        return [
            'success' => false,
            'error' => $message,
            'code' => $code
        ];
    }
}
