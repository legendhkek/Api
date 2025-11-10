<?php
/**
 * Telegram Bot Notifier
 * 
 * Features:
 * - Send notifications to Telegram
 * - Proxy status updates
 * - System alerts
 * - Performance reports
 * - Interactive commands
 */
class TelegramNotifier {
    private $botToken = '';
    private $chatId = '';
    private $enabled = false;
    private $apiUrl = 'https://api.telegram.org/bot';
    private $proxyManager = null;
    
    public function __construct(string $botToken = '', string $chatId = '') {
        $this->botToken = $botToken ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
        $this->chatId = $chatId ?: ($_ENV['TELEGRAM_CHAT_ID'] ?? '');
        $this->enabled = !empty($this->botToken) && !empty($this->chatId);
        
        if ($this->enabled) {
            $this->apiUrl .= $this->botToken . '/';
        }
        
        // Initialize ProxyManager for sending messages through proxies
        if (file_exists(__DIR__ . '/ProxyManager.php')) {
            require_once __DIR__ . '/ProxyManager.php';
            $this->proxyManager = new ProxyManager();
            if (file_exists(__DIR__ . '/ProxyList.txt')) {
                $this->proxyManager->loadFromFile(__DIR__ . '/ProxyList.txt');
            }
        }
    }
    
    /**
     * Send message to Telegram
     * 
     * @param string $message Message text
     * @param string $parseMode Parse mode (HTML, Markdown)
     * @return bool Success status
     */
    public function sendMessage(string $message, string $parseMode = 'HTML'): bool {
        if (!$this->enabled) {
            return false;
        }
        
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => $parseMode
        ];
        
        return $this->callAPI('sendMessage', $data);
    }
    
    /**
     * Send proxy status notification
     * 
     * @param array $stats Proxy statistics
     */
    public function notifyProxyStatus(array $stats): bool {
        $message = "🔍 <b>Proxy Status Update</b>\n\n";
        $message .= "📊 <b>Statistics:</b>\n";
        $message .= "• Total Proxies: {$stats['total_proxies']}\n";
        $message .= "• Active Proxies: {$stats['active_proxies']}\n";
        $message .= "• Success Rate: {$stats['success_rate']}%\n";
        $message .= "• Avg Quality: " . round($stats['avg_quality_score'] * 100, 1) . "%\n";
        $message .= "• Avg Response: {$stats['avg_response_time']}s\n";
        $message .= "\n⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Send alert notification
     * 
     * @param string $title Alert title
     * @param string $details Alert details
     * @param string $level Alert level (info, warning, critical)
     */
    public function sendAlert(string $title, string $details, string $level = 'info'): bool {
        $emoji = match($level) {
            'critical' => '🚨',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            default => '📢'
        };
        
        $message = "$emoji <b>$title</b>\n\n";
        $message .= "$details\n";
        $message .= "\n⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Send proxy found notification
     * 
     * @param int $count Number of proxies found
     * @param array $details Additional details
     */
    public function notifyProxiesFound(int $count, array $details = []): bool {
        $message = "✅ <b>New Proxies Found</b>\n\n";
        $message .= "📦 Found: <b>$count</b> working proxies\n";
        
        if (!empty($details['protocols'])) {
            $message .= "\n🔧 <b>Protocols:</b>\n";
            foreach ($details['protocols'] as $protocol => $pcount) {
                $message .= "• " . strtoupper($protocol) . ": $pcount\n";
            }
        }
        
        if (!empty($details['countries'])) {
            $message .= "\n🌍 <b>Countries:</b>\n";
            $top = array_slice($details['countries'], 0, 5);
            foreach ($top as $country => $ccount) {
                $message .= "• $country: $ccount\n";
            }
        }
        
        $message .= "\n⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Send health check notification
     * 
     * @param array $health Health check results
     */
    public function notifyHealthCheck(array $health): bool {
        $status = $health['status'] ?? 'unknown';
        $emoji = $status === 'healthy' ? '✅' : '⚠️';
        
        $message = "$emoji <b>Health Check Report</b>\n\n";
        $message .= "Status: <b>" . strtoupper($status) . "</b>\n\n";
        
        if (!empty($health['checks'])) {
            $message .= "📋 <b>Checks:</b>\n";
            foreach ($health['checks'] as $check => $result) {
                $checkEmoji = $result ? '✅' : '❌';
                $message .= "$checkEmoji $check\n";
            }
        }
        
        if (!empty($health['metrics'])) {
            $message .= "\n📊 <b>Metrics:</b>\n";
            foreach ($health['metrics'] as $metric => $value) {
                $message .= "• $metric: $value\n";
            }
        }
        
        $message .= "\n⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Send daily summary
     * 
     * @param array $summary Daily summary data
     */
    public function sendDailySummary(array $summary): bool {
        $message = "📊 <b>Daily Summary Report</b>\n";
        $message .= "📅 " . date('Y-m-d') . "\n\n";
        
        $message .= "🔢 <b>Requests:</b>\n";
        $message .= "• Total: {$summary['total_requests']}\n";
        $message .= "• Successful: {$summary['successful_requests']}\n";
        $message .= "• Failed: {$summary['failed_requests']}\n";
        $message .= "• Success Rate: {$summary['success_rate']}%\n\n";
        
        $message .= "⚡ <b>Performance:</b>\n";
        $message .= "• Avg Response: {$summary['avg_response_time']}s\n";
        $message .= "• Fastest: {$summary['min_response_time']}s\n";
        $message .= "• Slowest: {$summary['max_response_time']}s\n\n";
        
        $message .= "🌐 <b>Proxies:</b>\n";
        $message .= "• Total: {$summary['total_proxies']}\n";
        $message .= "• Active: {$summary['active_proxies']}\n";
        $message .= "• Quality: " . round($summary['avg_quality'] * 100, 1) . "%\n";
        
        return $this->sendMessage($message);
    }
    
    /**
     * Send performance report
     * 
     * @param array $report Performance report
     */
    public function sendPerformanceReport(array $report): bool {
        $message = "📈 <b>Performance Report</b>\n\n";
        
        if (!empty($report['top_proxies'])) {
            $message .= "🏆 <b>Top Performing Proxies:</b>\n";
            $top = array_slice($report['top_proxies'], 0, 5);
            foreach ($top as $i => $proxy) {
                $num = $i + 1;
                $score = round($proxy['quality_score'] * 100, 1);
                $message .= "$num. {$proxy['proxy']} - {$score}%\n";
            }
            $message .= "\n";
        }
        
        if (!empty($report['slow_proxies'])) {
            $message .= "🐌 <b>Slowest Proxies:</b>\n";
            $slow = array_slice($report['slow_proxies'], 0, 3);
            foreach ($slow as $proxy) {
                $time = round($proxy['avg_response_time'], 2);
                $message .= "• {$proxy['proxy']} - {$time}s\n";
            }
            $message .= "\n";
        }
        
        if (!empty($report['countries'])) {
            $message .= "🌍 <b>Geographic Distribution:</b>\n";
            foreach ($report['countries'] as $country => $count) {
                $message .= "• $country: $count\n";
            }
        }
        
        $message .= "\n⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Call Telegram API (with proxy support for background checks)
     * 
     * @param string $method API method
     * @param array $data Request data
     * @return bool Success status
     */
    private function callAPI(string $method, array $data): bool {
        if (!$this->enabled) {
            return false;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . $method,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        // Use proxy for background checks if ProxyManager is available
        if ($this->proxyManager) {
            $proxy = $this->proxyManager->getNextProxy(false); // Don't check health recursively
            if ($proxy) {
                $this->proxyManager->applyCurlProxy($ch, $proxy);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response && $httpCode === 200) {
            $result = json_decode($response, true);
            return $result['ok'] ?? false;
        }
        
        return false;
    }
    
    /**
     * Test Telegram connection
     * 
     * @return bool Success status
     */
    public function testConnection(): bool {
        if (!$this->enabled) {
            return false;
        }
        
        return $this->sendMessage("🤖 <b>Test Message</b>\n\nTelegram notifier is working correctly!");
    }
    
    /**
     * Check if notifier is enabled
     * 
     * @return bool Enabled status
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }
    
    /**
     * Send proxy list to Telegram
     * 
     * @param array $proxies Array of proxy strings
     * @param array $stats Optional statistics about the proxies
     * @return bool Success status
     */
    public function sendProxyList(array $proxies, array $stats = []): bool {
        if (!$this->enabled) {
            return false;
        }
        
        $message = "🔗 <b>Working Proxies Found</b>\n\n";
        
        // Add statistics if provided
        if (!empty($stats)) {
            $message .= "📊 <b>Statistics:</b>\n";
            if (isset($stats['total_tested'])) {
                $message .= "• Total Tested: {$stats['total_tested']}\n";
            }
            if (isset($stats['working'])) {
                $message .= "• Working: {$stats['working']}\n";
            }
            if (isset($stats['dead'])) {
                $message .= "• Dead: {$stats['dead']}\n";
            }
            if (isset($stats['success_rate'])) {
                $message .= "• Success Rate: {$stats['success_rate']}%\n";
            }
            $message .= "\n";
        }
        
        // Add proxy list
        $message .= "🌐 <b>Working Proxies:</b>\n";
        $maxProxies = 20; // Limit to avoid message too long error
        $proxiesToShow = array_slice($proxies, 0, $maxProxies);
        
        foreach ($proxiesToShow as $index => $proxy) {
            $num = $index + 1;
            $message .= "$num. <code>" . htmlspecialchars($proxy) . "</code>\n";
        }
        
        if (count($proxies) > $maxProxies) {
            $remaining = count($proxies) - $maxProxies;
            $message .= "\n... and $remaining more proxies\n";
        }
        
        $message .= "\n⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Send single proxy to Telegram
     * 
     * @param string $proxy Proxy string
     * @param array $details Optional proxy details
     * @return bool Success status
     */
    public function sendProxy(string $proxy, array $details = []): bool {
        if (!$this->enabled) {
            return false;
        }
        
        $message = "🔗 <b>Proxy Information</b>\n\n";
        $message .= "🌐 <b>Proxy:</b>\n<code>" . htmlspecialchars($proxy) . "</code>\n\n";
        
        if (!empty($details)) {
            $message .= "📋 <b>Details:</b>\n";
            foreach ($details as $key => $value) {
                $keyFormatted = ucwords(str_replace('_', ' ', $key));
                $message .= "• $keyFormatted: $value\n";
            }
            $message .= "\n";
        }
        
        $message .= "⏰ " . date('Y-m-d H:i:s');
        
        return $this->sendMessage($message);
    }
    
    /**
     * Set credentials
     * 
     * @param string $botToken Bot token
     * @param string $chatId Chat ID
     */
    public function setCredentials(string $botToken, string $chatId): void {
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->enabled = !empty($botToken) && !empty($chatId);
        
        if ($this->enabled) {
            $this->apiUrl = 'https://api.telegram.org/bot' . $botToken . '/';
        }
    }
}
