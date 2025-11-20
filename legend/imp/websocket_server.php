<?php
/**
 * WebSocket Server for Real-Time Dashboard
 * 
 * Features:
 * - Real-time proxy status updates
 * - Live analytics streaming
 * - Health monitoring updates
 * - Event broadcasting
 * 
 * Usage:
 * php websocket_server.php
 */

// Simple WebSocket server implementation
class WebSocketServer {
    private $host = '0.0.0.0';
    private $port = 8080;
    private $socket;
    private $clients = [];
    private $analytics;
    private $lastUpdate = 0;
    private $updateInterval = 5; // seconds
    
    public function __construct(string $host = '0.0.0.0', int $port = 8080) {
        $this->host = $host;
        $this->port = $port;
        
        require_once __DIR__ . '/ProxyAnalytics.php';
        $this->analytics = new ProxyAnalytics();
    }
    
    /**
     * Start WebSocket server
     */
    public function start(): void {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, $this->host, $this->port);
        socket_listen($this->socket);
        
        echo "WebSocket server started on {$this->host}:{$this->port}\n";
        
        while (true) {
            $read = array_merge([$this->socket], $this->clients);
            $write = $except = null;
            
            if (socket_select($read, $write, $except, 0, 200000) < 1) {
                // Check if it's time to send updates
                if (time() - $this->lastUpdate >= $this->updateInterval) {
                    $this->broadcastUpdate();
                    $this->lastUpdate = time();
                }
                continue;
            }
            
            if (in_array($this->socket, $read)) {
                $this->acceptClient();
                unset($read[array_search($this->socket, $read)]);
            }
            
            foreach ($read as $client) {
                $this->handleClient($client);
            }
        }
    }
    
    /**
     * Accept new client
     */
    private function acceptClient(): void {
        $client = socket_accept($this->socket);
        if ($client) {
            $this->clients[] = $client;
            $this->performHandshake($client);
            echo "New client connected. Total: " . count($this->clients) . "\n";
        }
    }
    
    /**
     * Handle client message
     * 
     * @param resource $client Client socket
     */
    private function handleClient($client): void {
        $data = @socket_read($client, 1024);
        
        if ($data === false || $data === '') {
            $this->disconnectClient($client);
            return;
        }
        
        $message = $this->decodeMessage($data);
        
        if ($message) {
            echo "Received: $message\n";
            
            // Handle message
            $response = $this->processMessage($message);
            $this->sendMessage($client, json_encode($response));
        }
    }
    
    /**
     * Process client message
     * 
     * @param string $message Message
     * @return array Response
     */
    private function processMessage(string $message): array {
        $data = json_decode($message, true);
        
        if (!$data || !isset($data['type'])) {
            return ['error' => 'Invalid message format'];
        }
        
        return match($data['type']) {
            'get_analytics' => $this->getAnalyticsData(),
            'get_proxies' => $this->getProxiesData(),
            'get_health' => $this->getHealthData(),
            default => ['error' => 'Unknown message type']
        };
    }
    
    /**
     * Get analytics data
     * 
     * @return array Analytics
     */
    private function getAnalyticsData(): array {
        return [
            'type' => 'analytics',
            'data' => $this->analytics->getOverallAnalytics(),
            'timestamp' => time()
        ];
    }
    
    /**
     * Get proxies data
     * 
     * @return array Proxies
     */
    private function getProxiesData(): array {
        return [
            'type' => 'proxies',
            'data' => $this->analytics->getTopProxies(50),
            'timestamp' => time()
        ];
    }
    
    /**
     * Get health data
     * 
     * @return array Health
     */
    private function getHealthData(): array {
        require_once __DIR__ . '/HealthMonitor.php';
        $monitor = new HealthMonitor();
        
        return [
            'type' => 'health',
            'data' => $monitor->runHealthCheck(false),
            'timestamp' => time()
        ];
    }
    
    /**
     * Broadcast update to all clients
     */
    private function broadcastUpdate(): void {
        $update = [
            'type' => 'update',
            'analytics' => $this->analytics->getOverallAnalytics(),
            'timestamp' => time()
        ];
        
        $message = json_encode($update);
        
        foreach ($this->clients as $client) {
            $this->sendMessage($client, $message);
        }
    }
    
    /**
     * Disconnect client
     * 
     * @param resource $client Client socket
     */
    private function disconnectClient($client): void {
        $key = array_search($client, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
            socket_close($client);
            echo "Client disconnected. Total: " . count($this->clients) . "\n";
        }
    }
    
    /**
     * Perform WebSocket handshake
     * 
     * @param resource $client Client socket
     */
    private function performHandshake($client): void {
        $request = socket_read($client, 5000);
        
        preg_match('#Sec-WebSocket-Key: (.*)\r\n#', $request, $matches);
        if (empty($matches[1])) {
            return;
        }
        
        $key = $matches[1];
        $acceptKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";
        
        socket_write($client, $response);
    }
    
    /**
     * Send message to client
     * 
     * @param resource $client Client socket
     * @param string $message Message
     */
    private function sendMessage($client, string $message): void {
        $encoded = $this->encodeMessage($message);
        @socket_write($client, $encoded, strlen($encoded));
    }
    
    /**
     * Encode message for WebSocket
     * 
     * @param string $message Message
     * @return string Encoded message
     */
    private function encodeMessage(string $message): string {
        $length = strlen($message);
        $header = chr(129); // Text frame
        
        if ($length <= 125) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }
        
        return $header . $message;
    }
    
    /**
     * Decode WebSocket message
     * 
     * @param string $data Raw data
     * @return string|null Decoded message
     */
    private function decodeMessage(string $data): ?string {
        $length = ord($data[1]) & 127;
        
        if ($length == 126) {
            $masks = substr($data, 4, 4);
            $dataStart = 8;
        } elseif ($length == 127) {
            $masks = substr($data, 10, 4);
            $dataStart = 14;
        } else {
            $masks = substr($data, 2, 4);
            $dataStart = 6;
        }
        
        $text = '';
        for ($i = $dataStart; $i < strlen($data); $i++) {
            $text .= $data[$i] ^ $masks[($i - $dataStart) % 4];
        }
        
        return $text;
    }
}

// Start server if run directly
if (php_sapi_name() === 'cli') {
    $host = $argv[1] ?? '0.0.0.0';
    $port = isset($argv[2]) ? (int)$argv[2] : 8080;
    
    $server = new WebSocketServer($host, $port);
    $server->start();
}
