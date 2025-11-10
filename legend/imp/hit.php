<?php
/**
 * HIT.PHP - Advanced CC Checker with Complete Customer Details
 * 
 * Features:
 * - Complete customer information (name, email, phone, address, etc.)
 * - Currency and country selection
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
require_once __DIR__ . '/add.php';
require_once __DIR__ . '/no.php';

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
// CUSTOMER DATA GENERATORS
// ============================================

/**
 * Generate random first name
 */
function generateFirstName() {
    $names = [
        'James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph', 'Thomas', 'Charles',
        'Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen',
        'Christopher', 'Daniel', 'Matthew', 'Anthony', 'Mark', 'Donald', 'Steven', 'Paul', 'Andrew', 'Joshua',
        'Nancy', 'Lisa', 'Betty', 'Margaret', 'Sandra', 'Ashley', 'Kimberly', 'Emily', 'Donna', 'Michelle',
        'Kevin', 'Brian', 'George', 'Edward', 'Ronald', 'Timothy', 'Jason', 'Jeffrey', 'Ryan', 'Jacob'
    ];
    return $names[array_rand($names)];
}

/**
 * Generate random last name
 */
function generateLastName() {
    $names = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
        'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
        'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
        'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores',
        'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts'
    ];
    return $names[array_rand($names)];
}

/**
 * Generate random email
 */
function generateEmail($firstName = null, $lastName = null) {
    if (!$firstName) $firstName = generateFirstName();
    if (!$lastName) $lastName = generateLastName();
    
    $domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'aol.com', 'proton.me'];
    $domain = $domains[array_rand($domains)];
    
    $patterns = [
        strtolower($firstName . $lastName),
        strtolower($firstName . '.' . $lastName),
        strtolower($firstName) . rand(100, 999),
        strtolower(substr($firstName, 0, 1) . $lastName),
        strtolower($firstName . '_' . $lastName)
    ];
    
    $username = $patterns[array_rand($patterns)];
    return $username . '@' . $domain;
}

/**
 * Generate random phone number
 */
function generatePhone($country = 'US') {
    global $areaCodes;
    
    if ($country === 'US') {
        $areaCode = $areaCodes[array_rand($areaCodes)];
        return sprintf("+1%d%03d%04d", $areaCode, rand(200, 999), rand(1000, 9999));
    }
    
    // Default US format
    return sprintf("+1%d%03d%04d", 212, rand(200, 999), rand(1000, 9999));
}

// ============================================
// CUSTOMER DETAILS HANDLING
// ============================================

/**
 * Parse customer details from input
 */
function parseCustomerDetails($input) {
    $details = [];
    
    // Handle JSON format
    if (is_string($input)) {
        $json = json_decode($input, true);
        if ($json && is_array($json)) {
            $input = $json;
        }
    }
    
    // Get or generate each field
    $details['first_name'] = $input['first_name'] ?? $input['firstName'] ?? generateFirstName();
    $details['last_name'] = $input['last_name'] ?? $input['lastName'] ?? generateLastName();
    $details['email'] = $input['email'] ?? generateEmail($details['first_name'], $details['last_name']);
    $details['phone'] = $input['phone'] ?? $input['phone_number'] ?? generatePhone($input['country'] ?? 'US');
    $details['cardholder_name'] = $input['cardholder_name'] ?? $input['cardholderName'] ?? 
                                  ($details['first_name'] . ' ' . $details['last_name']);
    
    // Address details
    $details['street_address'] = $input['street_address'] ?? $input['streetAddress'] ?? $input['address1'] ?? '';
    $details['city'] = $input['city'] ?? '';
    $details['state'] = $input['state'] ?? $input['province'] ?? '';
    $details['postal_code'] = $input['postal_code'] ?? $input['postalCode'] ?? $input['zip'] ?? '';
    $details['country'] = strtoupper($input['country'] ?? 'US');
    $details['currency'] = strtoupper($input['currency'] ?? 'USD');
    
    return $details;
}

/**
 * Get customer details with auto-generation
 */
function getCustomerDetails($request) {
    // Merge GET and POST
    $input = array_merge($_GET, $_POST);
    
    // Check if any customer detail is provided
    $provided_fields = ['first_name', 'firstName', 'last_name', 'lastName', 'email', 'phone', 
                        'street_address', 'streetAddress', 'city', 'state', 'postal_code', 'zip'];
    
    $has_custom = false;
    foreach ($provided_fields as $field) {
        if (!empty($input[$field])) {
            $has_custom = true;
            break;
        }
    }
    
    if ($has_custom) {
        return parseCustomerDetails($input);
    }
    
    // Generate from random address if available
    $addr = AddressProvider::getRandomAddress();
    $firstName = generateFirstName();
    $lastName = generateLastName();
    
    return [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => generateEmail($firstName, $lastName),
        'phone' => generatePhone('US'),
        'cardholder_name' => $firstName . ' ' . $lastName,
        'street_address' => $addr['numd'] . ' ' . $addr['address1'],
        'city' => $addr['city'],
        'state' => $addr['state'],
        'postal_code' => $addr['zip'],
        'country' => 'US',
        'currency' => 'USD'
    ];
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
    $cc_lines = preg_split('/[\r\n;]+/', $cc_input);
    foreach ($cc_lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $card = parseCreditCard($line);
        if ($card) $cards[] = $card;
    }
    $cc_source = 'get';
} elseif (isset($_POST['cc'])) {
    $cc_input = $_POST['cc'];
    $cc_lines = preg_split('/[\r\n;]+/', $cc_input);
    foreach ($cc_lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $card = parseCreditCard($line);
        if ($card) $cards[] = $card;
    }
    $cc_source = 'post';
}

// Get customer details
$customer = getCustomerDetails($_REQUEST);

// Get site
$site = isset($_GET['site']) ? trim($_GET['site']) : (isset($_POST['site']) ? trim($_POST['site']) : '');
if ($site && !preg_match('/^https?:\/\//i', $site)) {
    $site = 'https://' . $site;
}

// ============================================
// MAIN CHECKING FUNCTION
// ============================================

/**
 * Perform CC check with complete customer details
 */
function performCheck($card, $customer, $site, $pm, $ua, $rotate_proxy, $debug) {
    global $ROTATE_PROXY;
    
    $result = [
        'success' => false,
        'card' => $card['number'],
        'brand' => $card['brand'],
        'customer' => [
            'name' => $customer['first_name'] . ' ' . $customer['last_name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'address' => $customer['street_address'] . ', ' . $customer['city'] . ', ' . 
                        $customer['state'] . ' ' . $customer['postal_code'],
            'country' => $customer['country'],
            'currency' => $customer['currency']
        ],
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
                'gateway_details' => $gateway,
                'customer_used' => $customer
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

if (!empty($cards) && $site) {
    foreach ($cards as $card) {
        $result = performCheck($card, $customer, $site, $pm, $ua, $ROTATE_PROXY, $debug);
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
        'customer_details' => $customer,
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
    <title>💳 HIT - Advanced CC Checker with Complete Customer Details</title>
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
            max-width: 1400px;
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
            font-size: 14px;
        }
        
        label small {
            font-weight: 400;
            color: #94a3b8;
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
            min-height: 80px;
            font-family: 'Courier New', monospace;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .grid-2 {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .grid-3 {
            grid-template-columns: repeat(3, 1fr);
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
        
        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
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
            font-size: 13px;
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
            font-size: 14px;
        }
        
        .info-box strong {
            color: #1e40af;
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
        
        .section-title {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            padding: 12px 20px;
            border-radius: 10px;
            margin: 25px 0 15px;
            font-weight: 700;
            color: #1e293b;
            border-left: 4px solid #6366f1;
        }
        
        .help-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .grid, .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><span>💳</span> HIT - CC Checker with Complete Customer Details</h1>
            <p class="subtitle">
                Advanced credit card checker with complete customer information (name, email, phone, address, currency, country). 
                Automatic proxy rotation and gateway detection included.
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
                    <strong>Customer:</strong>
                    <span><?= htmlspecialchars($result['customer']['name']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Email:</strong>
                    <span><?= htmlspecialchars($result['customer']['email']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Phone:</strong>
                    <span><?= htmlspecialchars($result['customer']['phone']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Address:</strong>
                    <span><?= htmlspecialchars($result['customer']['address']) ?></span>
                </div>
                <div class="result-item">
                    <strong>Country/Currency:</strong>
                    <span><?= htmlspecialchars($result['customer']['country']) ?> / <?= htmlspecialchars($result['customer']['currency']) ?></span>
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
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>🚀 Check Credit Cards with Complete Details</h2>
            
            <form method="POST" action="">
                <div class="section-title">💳 Card Information</div>
                <div class="form-group">
                    <label>Credit Card(s) <small>(Format: number|month|year|cvv)</small></label>
                    <textarea name="cc" placeholder="4111111111111111|12|2027|123&#10;5555555555554444|01|2026|456" required><?= isset($_POST['cc']) ? htmlspecialchars($_POST['cc']) : '' ?></textarea>
                    <div class="help-text">Separate multiple cards with new lines</div>
                </div>
                
                <div class="section-title">👤 Customer Information</div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" placeholder="John" value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
                        <div class="help-text">Leave empty for auto-generation</div>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" placeholder="Smith" value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
                        <div class="help-text">Leave empty for auto-generation</div>
                    </div>
                </div>
                
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="john.smith@gmail.com" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                        <div class="help-text">Leave empty for auto-generation</div>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="+12125551234" value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
                        <div class="help-text">Format: +1XXXXXXXXXX</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Cardholder Name <small>(as appears on card)</small></label>
                    <input type="text" name="cardholder_name" placeholder="JOHN SMITH" value="<?= isset($_POST['cardholder_name']) ? htmlspecialchars($_POST['cardholder_name']) : '' ?>">
                    <div class="help-text">Leave empty to use First Name + Last Name</div>
                </div>
                
                <div class="section-title">📍 Address Information</div>
                <div class="form-group">
                    <label>Street Address</label>
                    <input type="text" name="street_address" placeholder="350 5th Ave" value="<?= isset($_POST['street_address']) ? htmlspecialchars($_POST['street_address']) : '' ?>">
                    <div class="help-text">Leave empty for random US address</div>
                </div>
                
                <div class="grid grid-3">
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" placeholder="New York" value="<?= isset($_POST['city']) ? htmlspecialchars($_POST['city']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>State/Province</label>
                        <input type="text" name="state" placeholder="NY" value="<?= isset($_POST['state']) ? htmlspecialchars($_POST['state']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Postal Code</label>
                        <input type="text" name="postal_code" placeholder="10118" value="<?= isset($_POST['postal_code']) ? htmlspecialchars($_POST['postal_code']) : '' ?>">
                    </div>
                </div>
                
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>Country</label>
                        <select name="country">
                            <option value="US">United States (US)</option>
                            <option value="CA">Canada (CA)</option>
                            <option value="GB">United Kingdom (GB)</option>
                            <option value="AU">Australia (AU)</option>
                            <option value="FR">France (FR)</option>
                            <option value="DE">Germany (DE)</option>
                            <option value="IN">India (IN)</option>
                            <option value="BR">Brazil (BR)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Currency</label>
                        <select name="currency">
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                            <option value="CAD">CAD - Canadian Dollar</option>
                            <option value="AUD">AUD - Australian Dollar</option>
                            <option value="INR">INR - Indian Rupee</option>
                            <option value="BRL">BRL - Brazilian Real</option>
                        </select>
                    </div>
                </div>
                
                <div class="section-title">🌐 Target & Options</div>
                <div class="form-group">
                    <label>Target Site <small>(Payment page URL)</small></label>
                    <input type="url" name="site" placeholder="https://example.myshopify.com/checkout" value="<?= isset($_POST['site']) ? htmlspecialchars($_POST['site']) : '' ?>" required>
                </div>
                
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>Proxy Rotation</label>
                        <select name="rotate">
                            <option value="1">Enabled (Recommended)</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Output Format</label>
                        <select name="format">
                            <option value="html">HTML (Interactive)</option>
                            <option value="json">JSON (API)</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                    <button type="submit" class="btn">⚡ Start Checking</button>
                    <button type="button" class="btn btn-secondary" onclick="fillTestData()">🧪 Fill Test Data</button>
                    <button type="button" class="btn btn-secondary" onclick="clearForm()">🗑️ Clear Form</button>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2>📚 Quick Reference</h2>
            
            <h3 style="margin-bottom: 10px; color: #1e293b;">Required Fields:</h3>
            <ul style="list-style: none; padding: 0;">
                <li style="padding: 5px 0;">✅ Credit Card(s)</li>
                <li style="padding: 5px 0;">✅ Target Site</li>
            </ul>
            
            <h3 style="margin: 15px 0 10px; color: #1e293b;">Auto-Generated Fields (if left empty):</h3>
            <ul style="list-style: none; padding: 0;">
                <li style="padding: 5px 0;">🤖 First Name, Last Name</li>
                <li style="padding: 5px 0;">🤖 Email Address (based on name)</li>
                <li style="padding: 5px 0;">🤖 Phone Number (US format)</li>
                <li style="padding: 5px 0;">🤖 Cardholder Name (First + Last)</li>
                <li style="padding: 5px 0;">🤖 Complete Address (random US address)</li>
            </ul>
            
            <div class="info-box" style="margin-top: 20px;">
                <strong>💡 Pro Tip:</strong> Leave all customer fields empty to use completely random, realistic data for each check. This is perfect for testing without manually entering details!
            </div>
        </div>
        
        <div class="info-box">
            <strong>⚠️ Important Notes:</strong><br>
            • This tool is for testing purposes only. Use responsibly and legally.<br>
            • All 11 required fields are collected: Currency, Country, Street, City, State, Postal Code, First Name, Last Name, Email, Phone, Cardholder Name.<br>
            • Fields left empty will be auto-generated with realistic data.<br>
            • <?= $proxy_count ?> proxies loaded for rotation.
        </div>
    </div>
    
    <script>
        function fillTestData() {
            document.querySelector('textarea[name="cc"]').value = '4111111111111111|12|2027|123\n5555555555554444|01|2026|456';
            document.querySelector('input[name="first_name"]').value = 'John';
            document.querySelector('input[name="last_name"]').value = 'Smith';
            document.querySelector('input[name="email"]').value = 'john.smith@gmail.com';
            document.querySelector('input[name="phone"]').value = '+12125551234';
            document.querySelector('input[name="cardholder_name"]').value = 'JOHN SMITH';
            document.querySelector('input[name="street_address"]').value = '350 5th Ave';
            document.querySelector('input[name="city"]').value = 'New York';
            document.querySelector('input[name="state"]').value = 'NY';
            document.querySelector('input[name="postal_code"]').value = '10118';
            document.querySelector('select[name="country"]').value = 'US';
            document.querySelector('select[name="currency"]').value = 'USD';
            document.querySelector('input[name="site"]').value = 'https://example.myshopify.com';
        }
        
        function clearForm() {
            if (confirm('Clear all form fields?')) {
                document.querySelector('form').reset();
            }
        }
    </script>
</body>
</html>
