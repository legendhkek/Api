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
    
    public function __construct(string $botToken = '', string $chatId = '') {
        $this->botToken = $botToken ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
        $this->chatId = $chatId ?: ($_ENV['TELEGRAM_CHAT_ID'] ?? '');
        $this->enabled = !empty($this->botToken) && !empty($this->chatId);
        
        if ($this->enabled) {
            $this->apiUrl .= $this->botToken . '/';
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
     * Send a proxy list document with summary details.
     *
     * @param array $proxies List of proxy strings.
     * @param array $options Optional settings: title, stats, preview_limit, filename, empty_message, fallback_message.
     */
    public function sendProxyList(array $proxies, array $options = []): bool {
        if (!$this->enabled) {
            return false;
        }

        $normalized = [];
        foreach ($proxies as $proxy) {
            $proxy = trim((string)$proxy);
            if ($proxy !== '') {
                $normalized[] = $proxy;
            }
        }

        $stats = $options['stats'] ?? [];
        $totalChecked = $stats['total'] ?? null;
        $workingCount = $stats['working'] ?? count($normalized);
        $deadCount = $stats['dead'] ?? (($totalChecked !== null) ? max(0, $totalChecked - $workingCount) : null);
        $successRate = $stats['success_rate'] ?? null;
        $title = $options['title'] ?? 'Proxy Health Check';

        if (empty($normalized)) {
            $message = $options['empty_message'] ?? "⚠️ <b>{$title}:</b> No working proxies found.";
            if ($totalChecked !== null) {
                $message .= "\n• Checked: {$totalChecked}";
            }
            if ($deadCount !== null) {
                $message .= "\n• Dead: {$deadCount}";
            }
            $message .= "\n⏰ " . date('Y-m-d H:i:s');
            return $this->sendMessage($message);
        }

        $summary = [];
        $summary[] = "📊 <b>{$title}</b>";
        if ($totalChecked !== null) {
            $summary[] = "• Checked: {$totalChecked}";
        }
        $summary[] = "• Working: {$workingCount}";
        if ($deadCount !== null) {
            $summary[] = "• Dead: {$deadCount}";
        }
        if ($successRate !== null) {
            $summary[] = "• Success Rate: {$successRate}%";
        }
        $summary[] = "⏰ " . date('Y-m-d H:i:s');

        $caption = implode("\n", $summary);

        $previewLimit = isset($options['preview_limit']) ? (int)$options['preview_limit'] : 10;
        if ($previewLimit > 0) {
            $preview = array_slice($normalized, 0, $previewLimit);
            if (!empty($preview)) {
                $caption .= "\n\n<pre>" . htmlspecialchars(implode("\n", $preview), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
                if (count($normalized) > count($preview)) {
                    $caption .= "\n...";
                }
            }
        }

        $caption = $this->truncateCaption($caption);

        $filename = $options['filename'] ?? ('working_proxies_' . date('Ymd_His') . '.txt');
        $tmpFile = tempnam(sys_get_temp_dir(), 'proxy_list_');

        if ($tmpFile === false) {
            return $this->sendMessage($caption);
        }

        file_put_contents($tmpFile, implode(PHP_EOL, $normalized));

        $document = function_exists('curl_file_create')
            ? curl_file_create($tmpFile, 'text/plain', $filename)
            : new CURLFile($tmpFile, 'text/plain', $filename);

        $payload = [
            'chat_id' => $this->chatId,
            'document' => $document,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        $success = $this->callAPI('sendDocument', $payload);

        @unlink($tmpFile);

        if (!$success && ($options['fallback_message'] ?? true)) {
            return $this->sendMessage($caption);
        }

        return $success;
    }
    
    /**
     * Call Telegram API
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

    /**
     * Ensure Telegram caption stays within limit.
     */
    private function truncateCaption(string $caption, int $limit = 1024): string {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($caption, 'UTF-8') <= $limit) {
                return $caption;
            }
            return rtrim(mb_substr($caption, 0, $limit - 3, 'UTF-8')) . '...';
        }

        if (strlen($caption) <= $limit) {
            return $caption;
        }

        return rtrim(substr($caption, 0, $limit - 3)) . '...';
    }
}
