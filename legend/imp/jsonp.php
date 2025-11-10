<?php
/**
 * Advanced JSON Payment Gateway Detection & Processing API
 * 
 * Features:
 * - Advanced gateway detection (50+ gateways)
 * - Multi-payment method support
 * - Credit card processing
 * - Real-time gateway detection
 * - Comprehensive JSON responses
 * - Payment method validation
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(60);

require_once 'autosh.php';

// Get URL parameter
$url = $_GET['url'] ?? $_POST['url'] ?? '';

if (empty($url)) {
    echo json_encode([
        'error' => true,
        'message' => 'URL parameter is required',
        'usage' => 'jsonp.php?url=https://example.com'
    ]);
    exit;
}

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode([
        'error' => true,
        'message' => 'Invalid URL format',
        'url' => $url
    ]);
    exit;
}

// Fetch page content
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, flow_user_agent());
curl_setopt($ch, CURLOPT_ENCODING, '');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: gzip, deflate, br',
    'Connection: keep-alive',
    'Upgrade-Insecure-Requests: 1'
]);

// Apply proxy if available
apply_proxy_if_used($ch, $url);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'error' => true,
        'message' => 'Failed to fetch URL',
        'url' => $url,
        'curl_error' => $curlError,
        'http_code' => $httpCode
    ]);
    exit;
}

// Detect gateways
$primaryGateway = GatewayDetector::detect($response, $url);
$allGateways = GatewayDetector::detectAll($response, $url);
$supportedMethods = GatewayDetector::getSupportedMethods($primaryGateway);

// Extract additional payment information
$paymentInfo = [
    'has_credit_card_form' => (stripos($response, 'card number') !== false || 
                               stripos($response, 'card-number') !== false ||
                               stripos($response, 'cc-number') !== false ||
                               stripos($response, 'credit-card') !== false),
    'has_cvv_field' => (stripos($response, 'cvv') !== false || 
                       stripos($response, 'cvc') !== false ||
                       stripos($response, 'security code') !== false),
    'has_expiry_field' => (stripos($response, 'expiry') !== false || 
                          stripos($response, 'expiration') !== false ||
                          stripos($response, 'exp-date') !== false),
    'has_name_field' => (stripos($response, 'cardholder') !== false || 
                        stripos($response, 'card holder') !== false ||
                        stripos($response, 'name on card') !== false),
    'has_zip_field' => (stripos($response, 'zip code') !== false || 
                       stripos($response, 'postal code') !== false ||
                       stripos($response, 'billing zip') !== false),
];

// Check for specific payment method indicators
$paymentMethods = [];
if (stripos($response, 'visa') !== false) $paymentMethods[] = 'visa';
if (stripos($response, 'mastercard') !== false || stripos($response, 'master card') !== false) $paymentMethods[] = 'mastercard';
if (stripos($response, 'american express') !== false || stripos($response, 'amex') !== false) $paymentMethods[] = 'amex';
if (stripos($response, 'discover') !== false) $paymentMethods[] = 'discover';
if (stripos($response, 'jcb') !== false) $paymentMethods[] = 'jcb';
if (stripos($response, 'diners club') !== false) $paymentMethods[] = 'diners_club';
if (stripos($response, 'apple pay') !== false || stripos($response, 'applepay') !== false) $paymentMethods[] = 'apple_pay';
if (stripos($response, 'google pay') !== false || stripos($response, 'googlepay') !== false || stripos($response, 'gpay') !== false) $paymentMethods[] = 'google_pay';
if (stripos($response, 'paypal') !== false) $paymentMethods[] = 'paypal';
if (stripos($response, 'amazon pay') !== false || stripos($response, 'amazonpay') !== false) $paymentMethods[] = 'amazon_pay';
if (stripos($response, 'klarna') !== false) $paymentMethods[] = 'klarna';
if (stripos($response, 'afterpay') !== false) $paymentMethods[] = 'afterpay';
if (stripos($response, 'affirm') !== false) $paymentMethods[] = 'affirm';
if (stripos($response, 'alipay') !== false) $paymentMethods[] = 'alipay';
if (stripos($response, 'wechat pay') !== false || stripos($response, 'wechatpay') !== false) $paymentMethods[] = 'wechat_pay';
if (stripos($response, 'upi') !== false) $paymentMethods[] = 'upi';
if (stripos($response, 'netbanking') !== false || stripos($response, 'net banking') !== false) $paymentMethods[] = 'netbanking';

// Extract API keys and tokens if present
$apiKeys = [];
if (preg_match('/pk_live_([a-zA-Z0-9_]+)/i', $response, $matches)) {
    $apiKeys['stripe_publishable_key'] = 'pk_live_' . $matches[1];
}
if (preg_match('/pk_test_([a-zA-Z0-9_]+)/i', $response, $matches)) {
    $apiKeys['stripe_test_key'] = 'pk_test_' . $matches[1];
}
if (preg_match('/rzp_([a-zA-Z0-9_]+)/i', $response, $matches)) {
    $apiKeys['razorpay_key'] = 'rzp_' . $matches[1];
}

// Build comprehensive response
$result = [
    'success' => true,
    'url' => $url,
    'http_code' => $httpCode,
    'timestamp' => time(),
    'gateway' => [
        'primary' => $primaryGateway,
        'all_detected' => $allGateways,
        'confidence' => count($allGateways) > 1 ? 'high' : (count($allGateways) === 1 ? 'medium' : 'low')
    ],
    'payment_methods' => [
        'supported' => array_unique(array_merge($supportedMethods, $paymentMethods)),
        'detected' => array_unique($paymentMethods),
        'credit_card_support' => $paymentInfo['has_credit_card_form']
    ],
    'form_fields' => $paymentInfo,
    'api_keys' => $apiKeys,
    'capabilities' => [
        'credit_card' => $paymentInfo['has_credit_card_form'] && 
                        $paymentInfo['has_cvv_field'] && 
                        $paymentInfo['has_expiry_field'],
        'debit_card' => $paymentInfo['has_credit_card_form'],
        'digital_wallets' => in_array('apple_pay', $paymentMethods) || 
                           in_array('google_pay', $paymentMethods) ||
                           in_array('paypal', $paymentMethods),
        'buy_now_pay_later' => in_array('klarna', $paymentMethods) || 
                              in_array('afterpay', $paymentMethods) ||
                              in_array('affirm', $paymentMethods),
        'bank_transfer' => in_array('netbanking', $paymentMethods) || 
                         in_array('upi', $paymentMethods)
    ],
    'processing_info' => [
        'can_process_credit_cards' => $paymentInfo['has_credit_card_form'] && 
                                     $paymentInfo['has_cvv_field'] && 
                                     $paymentInfo['has_expiry_field'],
        'requires_name' => $paymentInfo['has_name_field'],
        'requires_zip' => $paymentInfo['has_zip_field'],
        'gateway_ready' => $primaryGateway !== 'unknown'
    ]
];

// Add gateway-specific information
if ($primaryGateway !== 'unknown') {
    $result['gateway_details'] = [
        'name' => $primaryGateway,
        'supports_credit_card' => GatewayDetector::supportsMethod($primaryGateway, 'credit_card'),
        'supports_debit_card' => GatewayDetector::supportsMethod($primaryGateway, 'debit_card'),
        'supports_apple_pay' => GatewayDetector::supportsMethod($primaryGateway, 'apple_pay'),
        'supports_google_pay' => GatewayDetector::supportsMethod($primaryGateway, 'google_pay'),
        'all_supported_methods' => GatewayDetector::getSupportedMethods($primaryGateway)
    ];
}

// Add recommendations
$recommendations = [];
if ($primaryGateway === 'unknown') {
    $recommendations[] = 'Gateway could not be detected. Manual inspection recommended.';
}
if (!$paymentInfo['has_credit_card_form']) {
    $recommendations[] = 'No credit card form detected. Payment may be processed off-site.';
}
if ($paymentInfo['has_credit_card_form'] && !$paymentInfo['has_cvv_field']) {
    $recommendations[] = 'Credit card form detected but CVV field not found.';
}
if (count($allGateways) > 1) {
    $recommendations[] = 'Multiple gateways detected. Site may support multiple payment options.';
}

if (!empty($recommendations)) {
    $result['recommendations'] = $recommendations;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
