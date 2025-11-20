<?php
/**
 * 2Captcha API Integration for Captcha Solving
 * 
 * Supports:
 * - hCaptcha
 * - reCAPTCHA v2/v3
 * - Image captchas
 * - Text captchas
 */
class TwoCaptchaSolver {
    private $apiKey;
    private $apiUrl = 'https://2captcha.com';
    private $debug = false;
    private $timeout = 120; // Maximum wait time for solution
    private $pollingInterval = 5; // Seconds between status checks
    
    public function __construct(string $apiKey, bool $debug = false) {
        $this->apiKey = $apiKey;
        $this->debug = $debug;
    }
    
    /**
     * Solve hCaptcha challenge
     * 
     * @param string $sitekey hCaptcha site key
     * @param string $pageUrl URL where captcha is located
     * @return array Result with token or error
     */
    public function solveHCaptcha(string $sitekey, string $pageUrl): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => '2Captcha API key not configured'];
        }
        
        if ($this->debug) {
            error_log("[2Captcha] Solving hCaptcha for sitekey: $sitekey");
        }
        
        // Submit captcha
        $taskId = $this->submitHCaptcha($sitekey, $pageUrl);
        if (!$taskId) {
            return ['success' => false, 'error' => 'Failed to submit hCaptcha task'];
        }
        
        // Poll for solution
        $solution = $this->pollForSolution($taskId);
        
        return $solution;
    }
    
    /**
     * Solve reCAPTCHA v2
     * 
     * @param string $sitekey reCAPTCHA site key
     * @param string $pageUrl URL where captcha is located
     * @return array Result with token or error
     */
    public function solveRecaptchaV2(string $sitekey, string $pageUrl): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => '2Captcha API key not configured'];
        }
        
        if ($this->debug) {
            error_log("[2Captcha] Solving reCAPTCHA v2 for sitekey: $sitekey");
        }
        
        // Submit captcha
        $taskId = $this->submitRecaptchaV2($sitekey, $pageUrl);
        if (!$taskId) {
            return ['success' => false, 'error' => 'Failed to submit reCAPTCHA task'];
        }
        
        // Poll for solution
        $solution = $this->pollForSolution($taskId);
        
        return $solution;
    }
    
    /**
     * Solve reCAPTCHA v3
     * 
     * @param string $sitekey reCAPTCHA site key
     * @param string $pageUrl URL where captcha is located
     * @param string $action Action name (optional)
     * @param float $minScore Minimum score (optional, default 0.3)
     * @return array Result with token or error
     */
    public function solveRecaptchaV3(string $sitekey, string $pageUrl, string $action = 'verify', float $minScore = 0.3): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => '2Captcha API key not configured'];
        }
        
        if ($this->debug) {
            error_log("[2Captcha] Solving reCAPTCHA v3 for sitekey: $sitekey");
        }
        
        // Submit captcha
        $taskId = $this->submitRecaptchaV3($sitekey, $pageUrl, $action, $minScore);
        if (!$taskId) {
            return ['success' => false, 'error' => 'Failed to submit reCAPTCHA v3 task'];
        }
        
        // Poll for solution
        $solution = $this->pollForSolution($taskId);
        
        return $solution;
    }
    
    /**
     * Submit hCaptcha task to 2Captcha
     */
    private function submitHCaptcha(string $sitekey, string $pageUrl): ?string {
        $url = $this->apiUrl . '/in.php';
        $postData = [
            'key' => $this->apiKey,
            'method' => 'hcaptcha',
            'sitekey' => $sitekey,
            'pageurl' => $pageUrl,
            'json' => 1
        ];
        
        $response = $this->makeRequest($url, $postData);
        
        if ($response && isset($response['status']) && $response['status'] == 1) {
            return $response['request'];
        }
        
        if ($this->debug && isset($response['request'])) {
            error_log("[2Captcha] Submit error: " . $response['request']);
        }
        
        return null;
    }
    
    /**
     * Submit reCAPTCHA v2 task to 2Captcha
     */
    private function submitRecaptchaV2(string $sitekey, string $pageUrl): ?string {
        $url = $this->apiUrl . '/in.php';
        $postData = [
            'key' => $this->apiKey,
            'method' => 'userrecaptcha',
            'googlekey' => $sitekey,
            'pageurl' => $pageUrl,
            'json' => 1
        ];
        
        $response = $this->makeRequest($url, $postData);
        
        if ($response && isset($response['status']) && $response['status'] == 1) {
            return $response['request'];
        }
        
        return null;
    }
    
    /**
     * Submit reCAPTCHA v3 task to 2Captcha
     */
    private function submitRecaptchaV3(string $sitekey, string $pageUrl, string $action, float $minScore): ?string {
        $url = $this->apiUrl . '/in.php';
        $postData = [
            'key' => $this->apiKey,
            'method' => 'userrecaptcha',
            'version' => 'v3',
            'googlekey' => $sitekey,
            'pageurl' => $pageUrl,
            'action' => $action,
            'min_score' => $minScore,
            'json' => 1
        ];
        
        $response = $this->makeRequest($url, $postData);
        
        if ($response && isset($response['status']) && $response['status'] == 1) {
            return $response['request'];
        }
        
        return null;
    }
    
    /**
     * Poll 2Captcha for solution
     */
    private function pollForSolution(string $taskId): array {
        $url = $this->apiUrl . '/res.php';
        $startTime = time();
        
        while ((time() - $startTime) < $this->timeout) {
            sleep($this->pollingInterval);
            
            $params = [
                'key' => $this->apiKey,
                'action' => 'get',
                'id' => $taskId,
                'json' => 1
            ];
            
            $response = $this->makeRequest($url . '?' . http_build_query($params), null, 'GET');
            
            if ($response && isset($response['status'])) {
                if ($response['status'] == 1) {
                    // Solution ready
                    if ($this->debug) {
                        error_log("[2Captcha] Solution received for task: $taskId");
                    }
                    return [
                        'success' => true,
                        'token' => $response['request'],
                        'method' => '2captcha_api'
                    ];
                } elseif (isset($response['request']) && $response['request'] != 'CAPCHA_NOT_READY') {
                    // Error
                    if ($this->debug) {
                        error_log("[2Captcha] Error: " . $response['request']);
                    }
                    return [
                        'success' => false,
                        'error' => $response['request']
                    ];
                }
                // Still processing, continue polling
            }
        }
        
        return [
            'success' => false,
            'error' => 'Timeout waiting for captcha solution'
        ];
    }
    
    /**
     * Make HTTP request to 2Captcha API
     */
    private function makeRequest(string $url, ?array $postData = null, string $method = 'POST'): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($method == 'POST' && $postData) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 && $response) {
            return json_decode($response, true);
        }
        
        return null;
    }
    
    /**
     * Get account balance
     */
    public function getBalance(): ?float {
        $url = $this->apiUrl . '/res.php';
        $params = [
            'key' => $this->apiKey,
            'action' => 'getbalance',
            'json' => 1
        ];
        
        $response = $this->makeRequest($url . '?' . http_build_query($params), null, 'GET');
        
        if ($response && isset($response['status']) && $response['status'] == 1) {
            return (float)$response['request'];
        }
        
        return null;
    }
}
