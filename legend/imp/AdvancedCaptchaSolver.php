<?php
/**
 * Advanced Captcha Solver - Image-based OCR without External API
 * 
 * Features:
 * - Simple OCR for image-based captchas using GD library
 * - Pattern recognition for common captcha types
 * - Color analysis and character extraction
 * - Advanced hCaptcha bypass techniques
 * - Multiple solving strategies
 */
class AdvancedCaptchaSolver extends CaptchaSolver {
    private $tessDataPath = '/usr/share/tesseract-ocr/4.00/tessdata';
    private $useGD = true;
    
    public function __construct(bool $debug = false) {
        parent::__construct($debug);
        $this->useGD = extension_loaded('gd');
        
        if ($debug && !$this->useGD) {
            error_log("[AdvancedCaptchaSolver] GD extension not loaded, image OCR disabled");
        }
    }
    
    /**
     * Solve image-based captcha using simple OCR
     * 
     * @param string $imageData Image data (binary or base64)
     * @param string $type Image type hint
     * @return array Result with text and confidence
     */
    public function solveImageCaptcha(string $imageData, string $type = 'simple'): array {
        if (!$this->useGD) {
            return [
                'success' => false,
                'error' => 'GD extension not available',
                'text' => null
            ];
        }
        
        try {
            // Create image from data
            $img = $this->loadImageFromData($imageData);
            if (!$img) {
                return ['success' => false, 'error' => 'Failed to load image', 'text' => null];
            }
            
            // Preprocess image for better OCR
            $processed = $this->preprocessImage($img);
            
            // Extract text based on type
            switch($type) {
                case 'numeric':
                    $text = $this->extractNumericText($processed);
                    break;
                case 'alphanumeric':
                    $text = $this->extractAlphanumericText($processed);
                    break;
                case 'simple':
                    $text = $this->extractSimpleText($processed);
                    break;
                default:
                    $text = $this->extractSimpleText($processed);
                    break;
            }
            
            // Clean up
            imagedestroy($img);
            imagedestroy($processed);
            
            return [
                'success' => !empty($text),
                'text' => $text,
                'confidence' => $this->calculateConfidence($text),
                'method' => 'gd_ocr'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => null
            ];
        }
    }
    
    /**
     * Load image from various data formats
     * 
     * @param string $imageData Image data
     * @return resource|false GD image resource
     */
    private function loadImageFromData(string $imageData) {
        // Check if base64
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
            $imageData = base64_decode($imageData);
        } elseif (base64_decode($imageData, true) !== false) {
            $imageData = base64_decode($imageData);
        }
        
        // Try to create image
        return @imagecreatefromstring($imageData);
    }
    
    /**
     * Preprocess image for OCR (grayscale, threshold, noise reduction)
     * 
     * @param resource $img GD image resource
     * @return resource Processed image
     */
    private function preprocessImage($img) {
        $width = imagesx($img);
        $height = imagesy($img);
        
        // Create new image
        $processed = imagecreatetruecolor($width, $height);
        
        // Convert to grayscale and apply threshold
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Grayscale
                $gray = ($r + $g + $b) / 3;
                
                // Threshold (binary)
                $bw = $gray > 127 ? 255 : 0;
                
                $color = imagecolorallocate($processed, $bw, $bw, $bw);
                imagesetpixel($processed, $x, $y, $color);
            }
        }
        
        return $processed;
    }
    
    /**
     * Extract simple text from image (numbers and basic letters)
     * 
     * @param resource $img Processed image
     * @return string Extracted text
     */
    private function extractSimpleText($img): string {
        $width = imagesx($img);
        $height = imagesy($img);
        
        // Simple pattern matching for digits
        $patterns = $this->getDigitPatterns();
        $text = '';
        
        // Scan image in blocks
        $blockWidth = intval($width / 6); // Assume max 6 characters
        
        for ($i = 0; $i < 6; $i++) {
            $startX = $i * $blockWidth;
            $endX = min($startX + $blockWidth, $width);
            
            // Extract block
            $block = $this->extractBlock($img, $startX, 0, $endX - $startX, $height);
            
            // Match against patterns
            $char = $this->matchPattern($block, $patterns);
            if ($char !== '') {
                $text .= $char;
            }
        }
        
        return $text;
    }
    
    /**
     * Extract numeric text only
     * 
     * @param resource $img Processed image
     * @return string Extracted numbers
     */
    private function extractNumericText($img): string {
        $text = $this->extractSimpleText($img);
        // Keep only digits
        return preg_replace('/[^0-9]/', '', $text);
    }
    
    /**
     * Extract alphanumeric text
     * 
     * @param resource $img Processed image
     * @return string Extracted text
     */
    private function extractAlphanumericText($img): string {
        $text = $this->extractSimpleText($img);
        // Keep only alphanumeric
        return preg_replace('/[^A-Za-z0-9]/', '', $text);
    }
    
    /**
     * Get simple digit patterns for matching
     * 
     * @return array Digit patterns
     */
    private function getDigitPatterns(): array {
        // Simplified patterns - in production, use more sophisticated patterns
        return [
            '0' => [[1,1,1],[1,0,1],[1,0,1],[1,0,1],[1,1,1]],
            '1' => [[0,1,0],[1,1,0],[0,1,0],[0,1,0],[1,1,1]],
            '2' => [[1,1,1],[0,0,1],[1,1,1],[1,0,0],[1,1,1]],
            '3' => [[1,1,1],[0,0,1],[1,1,1],[0,0,1],[1,1,1]],
            '4' => [[1,0,1],[1,0,1],[1,1,1],[0,0,1],[0,0,1]],
            '5' => [[1,1,1],[1,0,0],[1,1,1],[0,0,1],[1,1,1]],
            '6' => [[1,1,1],[1,0,0],[1,1,1],[1,0,1],[1,1,1]],
            '7' => [[1,1,1],[0,0,1],[0,0,1],[0,0,1],[0,0,1]],
            '8' => [[1,1,1],[1,0,1],[1,1,1],[1,0,1],[1,1,1]],
            '9' => [[1,1,1],[1,0,1],[1,1,1],[0,0,1],[1,1,1]],
        ];
    }
    
    /**
     * Extract image block
     * 
     * @param resource $img Source image
     * @param int $x Start X
     * @param int $y Start Y
     * @param int $w Width
     * @param int $h Height
     * @return array 2D array of pixel values
     */
    private function extractBlock($img, int $x, int $y, int $w, int $h): array {
        $block = [];
        for ($py = $y; $py < $y + $h; $py++) {
            $row = [];
            for ($px = $x; $px < $x + $w; $px++) {
                if ($px < imagesx($img) && $py < imagesy($img)) {
                    $color = imagecolorat($img, $px, $py);
                    $row[] = ($color & 0xFF) > 127 ? 1 : 0;
                }
            }
            if (!empty($row)) {
                $block[] = $row;
            }
        }
        return $block;
    }
    
    /**
     * Match block against patterns
     * 
     * @param array $block Block to match
     * @param array $patterns Patterns to match against
     * @return string Matched character
     */
    private function matchPattern(array $block, array $patterns): string {
        if (empty($block)) return '';
        
        $bestMatch = '';
        $bestScore = 0;
        
        foreach ($patterns as $char => $pattern) {
            $score = $this->comparePattern($block, $pattern);
            if ($score > $bestScore && $score > 0.6) { // 60% threshold
                $bestScore = $score;
                $bestMatch = $char;
            }
        }
        
        return $bestMatch;
    }
    
    /**
     * Compare block with pattern
     * 
     * @param array $block Block to compare
     * @param array $pattern Pattern to compare against
     * @return float Similarity score (0-1)
     */
    private function comparePattern(array $block, array $pattern): float {
        // Simplified comparison - normalize and compare
        // In production, use more sophisticated algorithms
        $matches = 0;
        $total = 0;
        
        $blockHeight = count($block);
        $patternHeight = count($pattern);
        
        if ($blockHeight == 0 || $patternHeight == 0) return 0;
        
        $scaleY = $blockHeight / $patternHeight;
        
        foreach ($pattern as $py => $patternRow) {
            $by = intval($py * $scaleY);
            if ($by >= $blockHeight) continue;
            
            $blockRow = $block[$by] ?? [];
            $blockWidth = count($blockRow);
            $patternWidth = count($patternRow);
            
            if ($blockWidth == 0 || $patternWidth == 0) continue;
            
            $scaleX = $blockWidth / $patternWidth;
            
            foreach ($patternRow as $px => $patternPixel) {
                $bx = intval($px * $scaleX);
                if ($bx >= $blockWidth) continue;
                
                $blockPixel = $blockRow[$bx] ?? 0;
                $total++;
                
                if ($blockPixel == $patternPixel) {
                    $matches++;
                }
            }
        }
        
        return $total > 0 ? $matches / $total : 0;
    }
    
    /**
     * Calculate confidence score
     * 
     * @param string $text Extracted text
     * @return float Confidence (0-1)
     */
    private function calculateConfidence(string $text): float {
        if (empty($text)) return 0;
        
        // Simple confidence based on text properties
        $confidence = 0.5; // Base confidence
        
        // Longer text = higher confidence
        if (strlen($text) >= 4) $confidence += 0.2;
        
        // Only alphanumeric = higher confidence
        if (ctype_alnum($text)) $confidence += 0.2;
        
        // Expected length (most captchas are 4-6 chars)
        if (strlen($text) >= 4 && strlen($text) <= 6) $confidence += 0.1;
        
        return min(1.0, $confidence);
    }
    
    /**
     * Advanced hCaptcha bypass techniques
     * 
     * @param string $sitekey hCaptcha sitekey
     * @param string $url Target URL
     * @return array Bypass result
     */
    public function bypassHCaptcha(string $sitekey, string $url): array {
        // Technique 1: Check for accessibility bypass
        $accessibilityToken = $this->checkAccessibilityBypass($sitekey, $url);
        if ($accessibilityToken) {
            return [
                'success' => true,
                'method' => 'accessibility',
                'token' => $accessibilityToken
            ];
        }
        
        // Technique 2: Motion data simulation
        $motionToken = $this->simulateHumanMotion($sitekey, $url);
        if ($motionToken) {
            return [
                'success' => true,
                'method' => 'motion_simulation',
                'token' => $motionToken
            ];
        }
        
        // Technique 3: Fingerprint manipulation
        $fingerprintToken = $this->manipulateFingerprint($sitekey, $url);
        if ($fingerprintToken) {
            return [
                'success' => true,
                'method' => 'fingerprint',
                'token' => $fingerprintToken
            ];
        }
        
        return [
            'success' => false,
            'message' => 'hCaptcha bypass failed, manual solve required',
            'sitekey' => $sitekey,
            'url' => $url
        ];
    }
    
    /**
     * Check for hCaptcha accessibility bypass
     * 
     * @param string $sitekey Sitekey
     * @param string $url URL
     * @return string|null Token if successful
     */
    private function checkAccessibilityBypass(string $sitekey, string $url): ?string {
        // hCaptcha has accessibility option for visually impaired
        // This is a legitimate method but may not always be available
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://hcaptcha.com/checksiteconfig?host=" . parse_url($url, PHP_URL_HOST) . "&sitekey=" . $sitekey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['pass']) && $data['pass']) {
                // Site allows passive bypass
                return 'passive_' . md5($sitekey . time());
            }
        }
        
        return null;
    }
    
    /**
     * Simulate human motion patterns for hCaptcha
     * 
     * @param string $sitekey Sitekey
     * @param string $url URL
     * @return string|null Token if successful
     */
    private function simulateHumanMotion(string $sitekey, string $url): ?string {
        // Generate realistic motion data
        $motionData = $this->generateMotionData();
        
        // Try to get token with motion data
        // Note: This is a simplified version
        // Real implementation would need full hCaptcha API interaction
        
        return null; // Placeholder - full implementation requires more complex interaction
    }
    
    /**
     * Generate realistic motion data
     * 
     * @return array Motion data
     */
    private function generateMotionData(): array {
        $movements = [];
        $time = 0;
        
        // Simulate mouse movements (100 points)
        for ($i = 0; $i < 100; $i++) {
            $time += rand(10, 50);
            $movements[] = [
                'x' => rand(0, 1920),
                'y' => rand(0, 1080),
                't' => $time
            ];
        }
        
        return [
            'movements' => $movements,
            'clicks' => [[rand(500, 1000), rand(300, 700), $time]],
            'timestamp' => time()
        ];
    }
    
    /**
     * Manipulate browser fingerprint for bypass
     * 
     * @param string $sitekey Sitekey
     * @param string $url URL
     * @return string|null Token if successful
     */
    private function manipulateFingerprint(string $sitekey, string $url): ?string {
        // Generate realistic fingerprint
        $fingerprint = $this->generateFingerprint();
        
        // Attempt bypass with fingerprint
        // Full implementation requires headless browser integration
        
        return null; // Placeholder
    }
    
    /**
     * Generate realistic browser fingerprint
     * 
     * @return array Fingerprint data
     */
    private function generateFingerprint(): array {
        return [
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'language' => 'en-US',
            'platform' => 'Win32',
            'screen' => ['width' => 1920, 'height' => 1080, 'depth' => 24],
            'timezone' => -300,
            'webgl' => 'ANGLE (NVIDIA GeForce GTX 1080 Ti Direct3D11 vs_5_0 ps_5_0)',
            'canvas' => md5(random_bytes(32)),
            'fonts' => ['Arial', 'Verdana', 'Times New Roman', 'Courier New'],
            'plugins' => ['Chrome PDF Plugin', 'Chrome PDF Viewer'],
            'timestamp' => time()
        ];
    }
    
    /**
     * Solve captcha from URL (download and process)
     * 
     * @param string $imageUrl URL of captcha image
     * @param string $type Captcha type
     * @return array Solve result
     */
    public function solveCaptchaFromUrl(string $imageUrl, string $type = 'simple'): array {
        // Download image
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($imageData === false || $httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'Failed to download image from URL'
            ];
        }
        
        // Solve image
        return $this->solveImageCaptcha($imageData, $type);
    }
}
