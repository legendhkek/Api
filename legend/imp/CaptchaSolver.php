<?php
/**
 * Advanced Captcha Solver - No External API Required
 * 
 * Features:
 * - Simple math captcha solver (e.g., "What is 2+3?")
 * - Text-based captcha detection
 * - hCaptcha/reCAPTCHA detection and wait mechanism
 * - Pattern recognition for common captcha types
 */
class CaptchaSolver {
    private $debugMode = false;
    
    public function __construct(bool $debug = false) {
        $this->debugMode = $debug;
    }
    
    /**
     * Detect captcha type from HTML content
     * 
     * @param string $html HTML content
     * @return array ['type' => 'none|math|text|hcaptcha|recaptcha', 'data' => mixed]
     */
    public function detectCaptcha(string $html): array {
        $html_lower = strtolower($html);
        
        // Check for hCaptcha
        if (strpos($html_lower, 'h-captcha') !== false || 
            strpos($html_lower, 'hcaptcha') !== false ||
            preg_match('/data-sitekey=["\'][\w-]+["\']/', $html)) {
            if (preg_match('/data-sitekey=["\']([^"\']+)["\']/', $html, $m)) {
                return ['type' => 'hcaptcha', 'data' => ['sitekey' => $m[1]]];
            }
            return ['type' => 'hcaptcha', 'data' => []];
        }
        
        // Check for reCAPTCHA
        if (strpos($html_lower, 'g-recaptcha') !== false || 
            strpos($html_lower, 'recaptcha') !== false ||
            strpos($html_lower, 'grecaptcha') !== false) {
            if (preg_match('/data-sitekey=["\']([^"\']+)["\']/', $html, $m)) {
                return ['type' => 'recaptcha', 'data' => ['sitekey' => $m[1]]];
            }
            return ['type' => 'recaptcha', 'data' => []];
        }
        
        // Check for math captcha
        // Patterns: "What is 2+3?", "Solve: 5-2", "Calculate 4*3"
        $mathPatterns = [
            '/(?:what\s+is|solve|calculate|math)\s*:?\s*(\d+)\s*([+\-*\/])\s*(\d+)/i',
            '/(\d+)\s*([+\-*\/])\s*(\d+)\s*=\s*\?/i',
            '/(\d+)\s*plus\s*(\d+)/i',
            '/(\d+)\s*minus\s*(\d+)/i',
            '/(\d+)\s*times\s*(\d+)/i',
            '/(\d+)\s*divided\s+by\s*(\d+)/i',
        ];
        
        foreach ($mathPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return ['type' => 'math', 'data' => [
                    'question' => $matches[0],
                    'numbers' => array_slice($matches, 1)
                ]];
            }
        }
        
        // Check for simple text captcha
        if (preg_match('/captcha|verification|security\s+code/i', $html) && 
            preg_match('/<img[^>]+src=["\']([^"\']+captcha[^"\']*)["\'][^>]*>/i', $html, $m)) {
            return ['type' => 'text', 'data' => ['image_url' => $m[1]]];
        }
        
        return ['type' => 'none', 'data' => []];
    }
    
    /**
     * Solve math captcha
     * 
     * @param string $question Math question
     * @return int|float|null Result or null if cannot solve
     */
    public function solveMath(string $question) {
        $question = strtolower(trim($question));
        
        // Extract numbers and operator
        $patterns = [
            // Standard format: "2+3", "5-2", "4*3", "10/2"
            '/(\d+)\s*([+\-*\/×÷])\s*(\d+)/' => function($m) {
                $a = (int)$m[1];
                $b = (int)$m[3];
                $op = $m[2];
                
                switch ($op) {
                    case '+': return $a + $b;
                    case '-': return $a - $b;
                    case '*':
                    case '×': return $a * $b;
                    case '/':
                    case '÷': return $b != 0 ? (int)($a / $b) : null;
                    default: return null;
                }
            },
            // Word format: "2 plus 3", "5 minus 2"
            '/(\d+)\s*plus\s*(\d+)/' => function($m) {
                return (int)$m[1] + (int)$m[2];
            },
            '/(\d+)\s*minus\s*(\d+)/' => function($m) {
                return (int)$m[1] - (int)$m[2];
            },
            '/(\d+)\s*times\s*(\d+)/' => function($m) {
                return (int)$m[1] * (int)$m[2];
            },
            '/(\d+)\s*divided\s+by\s*(\d+)/' => function($m) {
                $b = (int)$m[2];
                return $b != 0 ? (int)((int)$m[1] / $b) : null;
            },
        ];
        
        foreach ($patterns as $pattern => $solver) {
            if (preg_match($pattern, $question, $matches)) {
                $result = $solver($matches);
                if ($this->debugMode) {
                    error_log("[CaptchaSolver] Solved math: $question = $result");
                }
                return $result;
            }
        }
        
        return null;
    }
    
    /**
     * Wait for user to solve captcha (for hCaptcha/reCAPTCHA)
     * Returns instructions for manual solving
     * 
     * @param string $type Captcha type
     * @param array $data Captcha data
     * @return array Status and instructions
     */
    public function waitForManualSolve(string $type, array $data = []): array {
        $instructions = [
            'status' => 'pending',
            'type' => $type,
            'message' => '',
            'wait_time' => 30, // Recommended wait time in seconds
        ];
        
        switch ($type) {
            case 'hcaptcha':
                $instructions['message'] = 'hCaptcha detected. Please solve manually or wait for automation.';
                $instructions['sitekey'] = $data['sitekey'] ?? 'unknown';
                $instructions['method'] = 'Manual intervention required or use hCaptcha solver service';
                break;
                
            case 'recaptcha':
                $instructions['message'] = 'reCAPTCHA detected. Please solve manually or wait for automation.';
                $instructions['sitekey'] = $data['sitekey'] ?? 'unknown';
                $instructions['method'] = 'Manual intervention required or use reCAPTCHA solver service';
                break;
                
            default:
                $instructions['message'] = 'Unknown captcha type detected.';
                break;
        }
        
        return $instructions;
    }
    
    /**
     * Auto-fill captcha if possible
     * 
     * @param string $html HTML content
     * @param string $captchaFieldName Name of captcha input field
     * @return array ['success' => bool, 'value' => mixed, 'type' => string]
     */
    public function autoFillCaptcha(string $html, string $captchaFieldName = 'captcha'): array {
        $detection = $this->detectCaptcha($html);
        
        switch ($detection['type']) {
            case 'math':
                $question = $detection['data']['question'] ?? '';
                $answer = $this->solveMath($question);
                
                if ($answer !== null) {
                    return [
                        'success' => true,
                        'value' => $answer,
                        'type' => 'math',
                        'question' => $question
                    ];
                }
                break;
                
            case 'hcaptcha':
            case 'recaptcha':
                return [
                    'success' => false,
                    'type' => $detection['type'],
                    'requires_manual' => true,
                    'instructions' => $this->waitForManualSolve($detection['type'], $detection['data'])
                ];
                
            case 'text':
                // Cannot solve image-based text captcha without external OCR
                return [
                    'success' => false,
                    'type' => 'text',
                    'requires_manual' => true,
                    'message' => 'Image-based captcha requires manual solving or OCR service'
                ];
        }
        
        return [
            'success' => false,
            'type' => 'none',
            'message' => 'No captcha detected or cannot be solved automatically'
        ];
    }
    
    /**
     * Check if response contains captcha challenge
     * 
     * @param string $html HTML content
     * @return bool True if captcha is present
     */
    public function hasCaptcha(string $html): bool {
        $detection = $this->detectCaptcha($html);
        return $detection['type'] !== 'none';
    }
    
    /**
     * Get captcha info for debugging
     * 
     * @param string $html HTML content
     * @return string Human-readable captcha info
     */
    public function getCaptchaInfo(string $html): string {
        $detection = $this->detectCaptcha($html);
        
        switch ($detection['type']) {
            case 'none':
                return 'No captcha detected';
                
            case 'math':
                $q = $detection['data']['question'] ?? '';
                $a = $this->solveMath($q);
                return "Math captcha: $q = $a";
                
            case 'hcaptcha':
                $key = $detection['data']['sitekey'] ?? 'unknown';
                return "hCaptcha detected (sitekey: $key)";
                
            case 'recaptcha':
                $key = $detection['data']['sitekey'] ?? 'unknown';
                return "reCAPTCHA detected (sitekey: $key)";
                
            case 'text':
                return 'Text/Image captcha detected';
                
            default:
                return 'Unknown captcha type';
        }
    }
}
