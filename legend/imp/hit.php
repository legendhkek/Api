<?php
/**
 * HIT.PHP - Advanced CC Checker with Custom Address & Proxy Rotation
 * 
 * Features:
 * - Custom address input for every check
 * - Automatic proxy rotation like autosh.php
 * - Support for multiple CC checking
 * - Gateway auto-detection
 * - Rate limit handling
 * - Bulk processing
 * - JSON/HTML output
 */

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(300);

// Check cURL extension
if (!extension_loaded('curl')) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'PHP cURL extension is not enabled',
        'fix' => 'Enable extension=curl in php.ini and restart server'
    ]);
    exit;
}

$start_time = microtime(true);

// Load required dependencies
require_once __DIR__ . '/ProxyManager.php';
require_once __DIR__ . '/ho.php';

// Initialize User Agent
$agent = new userAgent();
$ua = $agent->generate('windows');

// Initialize Proxy Manager
$pm = new ProxyManager(__DIR__ . '/hit_proxy_log.txt');
$proxy_count = file_exists(__DIR__ . '/ProxyList.txt') ? $pm->loadFromFile(__DIR__ . '/ProxyList.txt') : 0;

// Configure rate limiting (enabled by default)
$pm->setRateLimitDetection(true);
$pm->setAutoRotateOnRateLimit(true);
$pm->setRateLimitCooldown(60);
$pm->setMaxRateLimitRetries(5);

// Output format
$output_format = isset($_GET['format']) ? strtolower($_GET['format']) : 'html';
$debug = isset($_GET['debug']);

// Default: Enable proxy rotation
$ROTATE_PROXY = !isset($_GET['proxy']) && (!isset($_GET['rotate']) || $_GET['rotate'] !== '0');

// ============================================
// ADDRESS HANDLING
// ============================================

/**
 * Parse address input from various formats
 */
function parseAddressInput($input) {
    if (empty($input)) {
        return null;
    }
    
    // Try JSON format first
    $json = json_decode($input, true);
    if ($json && isset($json['address1'])) {
        return [
            'numd' => $json['numd'] ?? '',
            'address1' => $json['address1'] ?? '',
            'address2' => $json['address2'] ?? '',
            'city' => $json['city'] ?? '',
            'state' => $json['state'] ?? '',
            'zip' => $json['zip'] ?? '',
            'country' => $json['country'] ?? 'US'
        ];
    }
    
    // Try comma-separated format: "123 Main St, New York, NY, 10001"
    $parts = array_map('trim', explode(',', $input));
    if (count($parts) >= 3) {
        return [
            'numd' => '',
            'address1' => $parts[0] ?? '',
            'address2' => '',
            'city' => $parts[1] ?? '',
            'state' => $parts[2] ?? '',
            'zip' => $parts[3] ?? '',
            'country' => 'US'
        ];
    }
    
    // Try pipe-separated format: "123|Main St|New York|NY|10001"
    $parts = array_map('trim', explode('|', $input));
    if (count($parts) >= 4) {
        return [
            'numd' => $parts[0] ?? '',
            'address1' => $parts[1] ?? '',
            'address2' => '',
            'city' => $parts[2] ?? '',
            'state' => $parts[3] ?? '',
            'zip' => $parts[4] ?? '',
            'country' => 'US'
        ];
    }
    
    return null;
}

/**
 * Get random address from add.php
 */
function getRandomAddress() {
    if (file_exists(__DIR__ . '/add.php')) {
        require_once __DIR__ . '/add.php';
        $addr = AddressProvider::getRandomAddress();
        return [
            'numd' => $addr['numd'],
            'address1' => $addr['address1'],
            'address2' => '',
            'city' => $addr['city'],
            'state' => $addr['state'],
            'zip' => $addr['zip'],
            'country' => 'US'
        ];
    }
    
    // Fallback default address
    return [
        'numd' => '350',
        'address1' => '5th Ave',
        'address2' => '',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10118',
        'country' => 'US'
    ];
}

/**
 * Format address for display
 */
function formatAddressDisplay($addr) {
    $line1 = trim($addr['numd'] . ' ' . $addr['address1']);
    $line2 = $addr['address2'] ? $addr['address2'] . ', ' : '';
    return $line1 . ($line2 ? "\n" . $line2 : '') . "\n" . 
           $addr['city'] . ', ' . $addr['state'] . ' ' . $addr['zip'];
}

// Determine address to use
$address = null;
$address_source = 'none';

if (isset($_GET['address']) && !empty($_GET['address'])) {
    // Custom address provided
    $address = parseAddressInput($_GET['address']);
    $address_source = 'custom';
} elseif (isset($_POST['address']) && !empty($_POST['address'])) {
    // Custom address from POST
    $address = parseAddressInput($_POST['address']);
    $address_source = 'custom';
} elseif (isset($_GET['state']) && !empty($_GET['state'])) {
    // Get address by state
    if (file_exists(__DIR__ . '/add.php')) {
        require_once __DIR__ . '/add.php';
        $addr = AddressProvider::getAddressByState($_GET['state']);
        if ($addr) {
            $address = [
                'numd' => $addr['numd'],
                'address1' => $addr['address1'],
                'address2' => '',
                'city' => $addr['city'],
                'state' => $addr['state'],
                'zip' => $addr['zip'],
                'country' => 'US'
            ];
            $address_source = 'state:' . $_GET['state'];
        }
    }
}

// If no address specified and CC provided, generate random
if (!$address && (isset($_GET['cc']) || isset($_POST['cc']))) {
    $address = getRandomAddress();
    $address_source = 'random';
}

// ============================================
// CREDIT CARD HANDLING
// ============================================

/**
 * Parse credit card input
 */
function parseCreditCard($input) {
    $input = trim($input);
    
    // Format: number|month|year|cvv
    $parts = explode('|', $input);
    if (count($parts) >= 4) {
        $number = preg_replace('/\s+/', '', $parts[0]);
        return [
            'number' => $number,
            'month' => str_pad($parts[1], 2, '0', STR_PAD_LEFT),
            'year' => $parts[2],
            'cvv' => $parts[3],
            'brand' => detectCardBrand($number)
        ];
    }
    
    return null;
}

/**
 * Detect card brand from number
 */
function detectCardBrand($number) {
    $number = preg_replace('/\s+/', '', $number);
    
    if (preg_match('/^4/', $number)) return 'Visa';
    if (preg_match('/^5[1-5]/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]/', $number)) return 'Amex';
    if (preg_match('/^6(?:011|5)/', $number)) return 'Discover';
    if (preg_match('/^35/', $number)) return 'JCB';
    
    return 'Unknown';
}

/**
 * Validate credit card using Luhn algorithm
 */
function validateCardLuhn($number) {
    $number = preg_replace('/\s+/', '', $number);
    $sum = 0;
    $alt = false;
    
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = intval($number[$i]);
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    
    return ($sum % 10 === 0);
}

// Get card input
$cards = [];
$cc_source = 'none';

if (isset($_GET['cc'])) {
    $cc_input = $_GET['cc'];
    // Support multiple cards separated by newline or semicolon
    $cc_lines = preg_split('/[\r\n;]+/', $cc_input);
    foreach ($cc_lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $card = parseCreditCard($line);
        if ($card) {
            $cards[] = $card;
        }
    }
    $cc_source = 'get';
} elseif (isset($_POST['cc'])) {
    $cc_input = $_POST['cc'];
    $cc_lines = preg_split('/[\r\n;]+/', $cc_input);
    foreach ($cc_lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $card = parseCreditCard($line);
        if ($card) {
            $cards[] = $card;
        }
    }
    $cc_source = 'post';
}

// ============================================
// SITE/GATEWAY HANDLING
// ============================================

$site = isset($_GET['site']) ? trim($_GET['site']) : (isset($_POST['site']) ? trim($_POST['site']) : '');

// Parse site URL
if ($site && !preg_match('/^https?:\/\//i', $site)) {
    $site = 'https://' . $site;
}

// ============================================
// MAIN CHECKING FUNCTION
// ============================================

/**
 * Perform CC check with address and proxy rotation
 */
function performCheck($card, $address, $site, $pm, $ua, $rotate_proxy, $debug) {
    global $ROTATE_PROXY;
    
    $result = [
        'success' => false,
        'card' => $card['number'],
        'brand' => $card['brand'],
        'address' => formatAddressDisplay($address),
        'site' => $site,
        'message' => '',
        'gateway' => 'Unknown',
        'proxy_used' => null,
        'response_time' => 0,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $start = microtime(true);
    
    try {
        // Validate card
        if (!validateCardLuhn($card['number'])) {
            $result['message'] = 'Invalid card number (Luhn check failed)';
            $result['status'] = 'INVALID';
            return $result;
        }
        
        if (empty($site)) {
            $result['message'] = 'No site URL provided';
            $result['status'] = 'ERROR';
            return $result;
        }
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $site,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
                'Cache-Control: max-age=0'
            ]
        ]);
        
        // Apply proxy if rotation enabled
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) {
                $pm->applyCurlProxy($ch, $proxy);
                $result['proxy_used'] = $proxy['string'];
                
                if ($debug) {
                    error_log("[HIT] Using proxy: {$proxy['string']}");
                }
            }
        }
        
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        $result['response_time'] = round((microtime(true) - $start) * 1000);
        $result['http_code'] = $http_code;
        
        if ($response === false) {
            $result['message'] = "Connection failed: $curl_error";
            $result['status'] = 'ERROR';
            return $result;
        }
        
        // Detect gateway from response
        $gateway = detectGateway($response, $site);
        $result['gateway'] = $gateway['name'] ?? 'Unknown';
        
        // Analyze response for card status
        $analysis = analyzeResponse($response, $http_code);
        $result['success'] = $analysis['success'];
        $result['status'] = $analysis['status'];
        $result['message'] = $analysis['message'];
        
        if ($debug) {
            $result['debug'] = [
                'http_code' => $http_code,
                'response_length' => strlen($response),
                'gateway_details' => $gateway
            ];
        }
        
    } catch (Exception $e) {
        $result['message'] = 'Exception: ' . $e->getMessage();
        $result['status'] = 'ERROR';
        $result['response_time'] = round((microtime(true) - $start) * 1000);
    }
    
    return $result;
}

/**
 * Detect payment gateway from response
 */
function detectGateway($html, $url) {
    $html_lower = strtolower($html);
    $url_lower = strtolower($url);
    
    $gateways = [
        'Shopify' => ['shopify', 'myshopify.com', 'checkout.shopify'],
        'Stripe' => ['stripe.com', 'stripe.js', 'stripe-key', 'pk_live', 'pk_test'],
        'PayPal' => ['paypal.com', 'paypal.js', 'paypal-button'],
        'WooCommerce' => ['woocommerce', 'wc-', 'wp-content/plugins/woo'],
        'Razorpay' => ['razorpay', 'rzp_live', 'rzp_test'],
        'Square' => ['squareup.com', 'square.js', 'sq-'],
        'Authorize.Net' => ['authorize.net', 'authorizenet'],
        'Braintree' => ['braintree', 'braintreegateway'],
        'Adyen' => ['adyen.com', 'adyen.js'],
        'PayU' => ['payu.in', 'payu.com'],
    ];
    
    foreach ($gateways as $name => $patterns) {
        foreach ($patterns as $pattern) {
            if (strpos($html_lower, $pattern) !== false || strpos($url_lower, $pattern) !== false) {
                return ['name' => $name, 'confidence' => 0.8];
            }
        }
    }
    
    return ['name' => 'Unknown', 'confidence' => 0];
}

/**
 * Analyze response for card status
 */
function analyzeResponse($response, $http_code) {
    $response_lower = strtolower($response);
    
    // Success indicators
    $success_patterns = [
        'payment successful', 'payment received', 'order placed',
        'thank you for your order', 'order confirmed', 'payment complete',
        'transaction approved', 'success', 'payment accepted'
    ];
    
    foreach ($success_patterns as $pattern) {
        if (strpos($response_lower, $pattern) !== false) {
            return [
                'success' => true,
                'status' => 'LIVE',
                'message' => 'Payment successful'
            ];
        }
    }
    
    // Decline indicators
    $decline_patterns = [
        'card declined' => 'Card declined',
        'payment declined' => 'Payment declined',
        'insufficient funds' => 'Insufficient funds',
        'invalid card' => 'Invalid card number',
        'card expired' => 'Card expired',
        'incorrect cvc' => 'Incorrect CVV',
        'do not honor' => 'Do not honor',
        'authentication required' => 'Authentication required (3DS)',
    ];
    
    foreach ($decline_patterns as $pattern => $msg) {
        if (strpos($response_lower, $pattern) !== false) {
            return [
                'success' => false,
                'status' => 'DECLINED',
                'message' => $msg
            ];
        }
    }
    
    // Rate limit check
    if ($http_code === 429 || strpos($response_lower, 'rate limit') !== false || 
        strpos($response_lower, 'too many requests') !== false) {
        return [
            'success' => false,
            'status' => 'RATE_LIMITED',
            'message' => 'Rate limited - rotating proxy'
        ];
    }
    
    // Connection issues
    if ($http_code >= 500) {
        return [
            'success' => false,
            'status' => 'SERVER_ERROR',
            'message' => "Server error (HTTP $http_code)"
        ];
    }
    
    // Default: unknown
    return [
        'success' => false,
        'status' => 'UNKNOWN',
        'message' => "Unable to determine card status (HTTP $http_code)"
    ];
}

// ============================================
// EXECUTE CHECKS
// ============================================

$results = [];
$execution_time = 0;

if (!empty($cards) && $address && $site) {
    foreach ($cards as $card) {
        $result = performCheck($card, $address, $site, $pm, $ua, $ROTATE_PROXY, $debug);
        $results[] = $result;
        
        // Small delay between checks to avoid rate limiting
        if (count($cards) > 1) {
            usleep(500000); // 0.5 second delay
        }
    }
}

$execution_time = round((microtime(true) - $start_time) * 1000);

// ============================================
// OUTPUT RESULTS
// ============================================

if ($output_format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => !empty($results),
        'count' => count($results),
        'results' => $results,
        'address_used' => $address ? formatAddressDisplay($address) : null,
        'address_source' => $address_source,
        'proxy_rotation' => $ROTATE_PROXY,
        'proxies_loaded' => $proxy_count,
        'execution_time_ms' => $execution_time,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// HTML INTERFACE
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💳 HIT - Advanced CC Checker with Custom Address</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #1e293b;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        h1 {
            color: #4338ca;
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .subtitle {
            color: #64748b;
            font-size: 16px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .card h2 {
            color: #1e293b;
            margin-bottom: 20px;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #334155;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #6366f1;
        }
        
        textarea {
            min-height: 100px;
            font-family: 'Courier New', monospace;
        }
        
        .btn {
            background: linear-gradient(135deg, #6366f1, #4338ca);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .result-card {
            background: #f8fafc;
            border-left: 4px solid #94a3b8;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .result-card.success {
            border-left-color: #10b981;
            background: #ecfdf5;
        }
        
        .result-card.declined {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        
        .result-card.error {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }
        
        .result-header {
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .result-item {
            padding: 6px 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
        }
        
        .result-item:last-child {
            border-bottom: none;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .status-live {
            background: #10b981;
            color: white;
        }
        
        .status-declined {
            background: #ef4444;
            color: white;
        }
        
        .status-error {
            background: #f59e0b;
            color: white;
        }
        
        .info-box {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-box strong {
            color: #1e40af;
        }
        
        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin-top: 10px;
        }
        
        .stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat {
            background: linear-gradient(135deg, #6366f1, #4338ca);
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            text-align: center;
            flex: 1;
            min-width: 150px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><span>💳</span> HIT - CC Checker with Custom Address</h1>
            <p class="subtitle">
                Advanced credit card checker with custom address input and automatic proxy rotation. 
                Check multiple cards with real addresses on any payment gateway.
            </p>
        </div>
        
        <?php if (!empty($results)): ?>
        <div class="card">
            <h2>📊 Check Results</h2>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?= count($results) ?></div>
                    <div class="stat-label">Cards Checked</div>
                </div>
                <div class="stat" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <div class="stat-value"><?= count(array_filter($results, fn($r) => $r['status'] === 'LIVE')) ?></div>
                    <div class="stat-label">Live Cards</div>
                </div>
                <div class="stat" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                    <div class="stat-value"><?= count(array_filter($results, fn($r) => $r['status'] === 'DECLINED')) ?></div>
                    <div class="stat-label">Declined</div>
                </div>
                <div class="stat" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <div class="stat-value"><?= $execution_time ?>ms</div>
                    <div class="stat-label">Execution Time</div>
                </div>
            </div>
            
            <?php foreach ($results as $result): ?>
            <div class="result-card <?= $result['status'] === 'LIVE' ? 'success' : ($result['status'] === 'DECLINED' ? 'declined' : 'error') ?>">
                <div class="result-header">
                    Card: <?= htmlspecialchars(substr($result['card'], 0, 6)) ?>******<?= htmlspecialchars(substr($result['card'], -4)) ?>
                    <span class="status-badge status-<?= strtolower($result['status']) ?>"><?= htmlspecialchars($result['status']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Brand:</strong>
                    <span><?= htmlspecialchars($result['brand']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Message:</strong>
                    <span><?= htmlspecialchars($result['message']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Gateway:</strong>
                    <span><?= htmlspecialchars($result['gateway']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Response Time:</strong>
                    <span><?= $result['response_time'] ?>ms</span>
                </div>
                <?php if ($result['proxy_used']): ?>
                <div class="result-item">
                    <strong>Proxy:</strong>
                    <span><?= htmlspecialchars($result['proxy_used']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($result['http_code'])): ?>
                <div class="result-item">
                    <strong>HTTP Code:</strong>
                    <span><?= $result['http_code'] ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <div class="info-box">
                <strong>Address Used:</strong><br>
                <?= nl2br(htmlspecialchars($address ? formatAddressDisplay($address) : 'No address')) ?><br>
                <small>Source: <?= htmlspecialchars($address_source) ?></small>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>🚀 Check Credit Cards</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>💳 Credit Card(s) <small>(Format: number|month|year|cvv)</small></label>
                    <textarea name="cc" placeholder="4111111111111111|12|2027|123&#10;5555555555554444|01|2026|456" required><?= isset($_POST['cc']) ? htmlspecialchars($_POST['cc']) : '' ?></textarea>
                    <small style="color: #64748b;">Separate multiple cards with new lines. Test cards shown above for demo.</small>
                </div>
                
                <div class="form-group">
                    <label>📍 Address <small>(Required for most gateways)</small></label>
                    <textarea name="address" placeholder="350, 5th Ave, New York, NY, 10118&#10;OR&#10;350|5th Ave|New York|NY|10118" required><?= isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '' ?></textarea>
                    <small style="color: #64748b;">
                        Formats: <br>
                        • Comma-separated: "Street, City, State, ZIP"<br>
                        • Pipe-separated: "Number|Street|City|State|ZIP"<br>
                        • Leave empty for random US address
                    </small>
                </div>
                
                <div class="form-group">
                    <label>🌐 Target Site <small>(Payment page URL)</small></label>
                    <input type="url" name="site" placeholder="https://example.myshopify.com/checkout" value="<?= isset($_POST['site']) ? htmlspecialchars($_POST['site']) : '' ?>" required>
                </div>
                
                <div class="grid">
                    <div class="form-group">
                        <label>🔄 Proxy Rotation</label>
                        <select name="rotate">
                            <option value="1">Enabled (Recommended)</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>📤 Output Format</label>
                        <select name="format">
                            <option value="html">HTML (Interactive)</option>
                            <option value="json">JSON (API)</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn">⚡ Start Checking</button>
                <button type="button" class="btn btn-secondary" onclick="fillTestData()">🧪 Fill Test Data</button>
            </form>
        </div>
        
        <div class="card">
            <h2>📚 API Documentation</h2>
            
            <h3 style="margin-bottom: 10px;">GET Request:</h3>
            <div class="code-block">
hit.php?cc=CARD&address=ADDRESS&site=URL&format=json

Examples:
hit.php?cc=4111111111111111|12|2027|123&address=350,5th Ave,New York,NY,10118&site=https://shop.com

# Multiple cards (URL encode newlines as %0A)
hit.php?cc=4111111111111111|12|2027|123%0A5555555555554444|01|2026|456&address=...&site=...

# Use state-based address
hit.php?cc=CARD&state=CA&site=URL

# Random address (auto-generated)
hit.php?cc=CARD&site=URL
            </div>
            
            <h3 style="margin: 20px 0 10px;">Address Formats:</h3>
            <div class="code-block">
# Comma-separated
address=350, 5th Ave, New York, NY, 10118

# Pipe-separated
address=350|5th Ave|New York|NY|10118

# By state (uses random address from that state)
state=CA

# Random (omit address parameter)
            </div>
            
            <h3 style="margin: 20px 0 10px;">Features:</h3>
            <ul style="list-style: none; padding: 0; margin-top: 15px;">
                <li style="padding: 8px 0;">✓ Custom address for every check</li>
                <li style="padding: 8px 0;">✓ Automatic proxy rotation with rate limit handling</li>
                <li style="padding: 8px 0;">✓ Bulk CC checking (multiple cards at once)</li>
                <li style="padding: 8px 0;">✓ Gateway auto-detection</li>
                <li style="padding: 8px 0;">✓ Luhn validation</li>
                <li style="padding: 8px 0;">✓ JSON & HTML output</li>
                <li style="padding: 8px 0;">✓ Response time tracking</li>
                <li style="padding: 8px 0;">✓ <?= $proxy_count ?> proxies loaded</li>
            </ul>
        </div>
        
        <div class="info-box">
            <strong>⚠️ Important Notes:</strong><br>
            • This tool is for testing purposes only. Use responsibly and legally.<br>
            • Requires ProxyList.txt with working proxies for rotation.<br>
            • Address format affects gateway compatibility - use real formats.<br>
            • Some gateways require specific address validation (ZIP, state match, etc.).
        </div>
    </div>
    
    <script>
        function fillTestData() {
            document.querySelector('textarea[name="cc"]').value = '4111111111111111|12|2027|123\n5555555555554444|01|2026|456';
            document.querySelector('textarea[name="address"]').value = '350, 5th Ave, New York, NY, 10118';
            document.querySelector('input[name="site"]').value = 'https://example.myshopify.com';
        }
    </script>
</body>
</html>
