<?php
error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(300);

$startTime = microtime(true);

function detect_brand(string $number): string {
    if ($number === '') {
        return 'Unknown';
    }
    $patterns = [
        'Visa' => '/^4[0-9]{6,}$/',
        'Mastercard' => '/^5[1-5][0-9]{5,}$/',
        'Amex' => '/^3[47][0-9]{4,}$/',
        'Discover' => '/^6(?:011|5[0-9]{2})[0-9]{3,}$/',
        'JCB' => '/^(?:2131|1800|35\d{3})\d{3,}$/',
        'Diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{4,}$/',
    ];
    foreach ($patterns as $brand => $regex) {
        if (preg_match($regex, $number)) {
            return $brand;
        }
    }
    return 'Unknown';
}

function validate_luhn(string $number): bool {
    $number = preg_replace('/\D+/', '', $number);
    if ($number === '') {
        return false;
    }
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return ($sum % 10) === 0;
}

function mask_card(string $number): string {
    if ($number === '') {
        return 'N/A';
    }
    $len = strlen($number);
    $first = substr($number, 0, min(6, $len));
    $last = substr($number, max($len - 4, 0));
    $maskedLen = max($len - strlen($first) - strlen($last), 0);
    return $first . str_repeat('*', $maskedLen) . $last;
}

function parse_cards(string $input): array {
    $lines = preg_split('/[\r\n;]+/', $input);
    $cards = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line);
        if (count($parts) < 4) {
            $number = preg_replace('/\D+/', '', $parts[0] ?? '');
            $cards[] = [
                'raw_input' => $line,
                'raw' => $line,
                'number' => $number,
                'month' => '',
                'year' => '',
                'cvv' => '',
                'brand' => detect_brand($number),
                'valid_luhn' => false,
                'error' => 'format',
            ];
            continue;
        }

        $number = preg_replace('/\D+/', '', $parts[0]);
        $month = preg_replace('/\D+/', '', $parts[1]);
        $year = preg_replace('/\D+/', '', $parts[2]);
        $cvv = preg_replace('/\D+/', '', $parts[3]);

        $month = str_pad(substr($month, 0, 2), 2, '0', STR_PAD_LEFT);
        if (strlen($year) === 2) {
            $year = '20' . $year;
        }
        $year = substr($year, 0, 4);

        $rawNormalized = $number . '|' . $month . '|' . $year . '|' . $cvv;

        $cards[] = [
            'raw_input' => $line,
            'raw' => $rawNormalized,
            'number' => $number,
            'month' => $month,
            'year' => $year,
            'cvv' => $cvv,
            'brand' => detect_brand($number),
            'valid_luhn' => $number !== '' ? validate_luhn($number) : false,
            'error' => null,
        ];
    }
    return $cards;
}

function build_autosh_endpoint(): ?string {
    if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
        return null;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = str_replace('\\', '/', dirname($scriptName));
    if ($basePath === '\\' || $basePath === '/') {
        $basePath = '';
    }
    if ($basePath === '.' || $basePath === './') {
        $basePath = '';
    }
    $basePath = rtrim($basePath, '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/autosh.php';
}

function call_autosh(string $endpoint, array $params, int $timeout = 90): array {
    $start = microtime(true);
    $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'HIT-AutoSh/2.0',
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_TCP_FASTOPEN => true,
        CURLOPT_DNS_CACHE_TIMEOUT => 180,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = $errno ? curl_error($ch) : null;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $duration = round((microtime(true) - $start) * 1000, 2);

    if ($body === false || $errno !== 0) {
        return [
            'ok' => false,
            'http_code' => $httpCode ?: 0,
            'payload' => null,
            'raw' => null,
            'error' => $error ?: 'Unknown cURL error',
            'duration_ms' => $duration,
        ];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'http_code' => $httpCode ?: 0,
            'payload' => null,
            'raw' => $body,
            'error' => 'Unexpected response from autosh.php',
            'duration_ms' => $duration,
        ];
    }

    return [
        'ok' => ($httpCode >= 200 && $httpCode < 400),
        'http_code' => $httpCode ?: 0,
        'payload' => $decoded,
        'raw' => $body,
        'error' => null,
        'duration_ms' => $duration,
    ];
}

function summarize_autosh_payload(?array $payload): array {
    if (!is_array($payload)) {
        return [
            'status' => 'ERROR',
            'message' => 'No gateway response (request skipped)',
            'gateway' => '',
            'amount' => null,
            'currency' => null,
            'proxy_status' => null,
            'meta_duration' => null,
            'effective_status' => 'ERROR',
        ];
    }

    $status = $payload['Status'] ?? $payload['status'] ?? null;
    if ($status === null && isset($payload['success'])) {
        $status = $payload['success'] ? 'LIVE' : 'DECLINED';
    }
    if ($status === null) {
        $status = 'UNKNOWN';
    }
    $status = strtoupper((string)$status);

    $message = $payload['Response'] ?? $payload['message'] ?? ($payload['Message'] ?? '');
    if ($message === '' && isset($payload['error'])) {
        $message = (string)$payload['error'];
    }

    $gateway = $payload['Gateway'] ?? null;
    if (!$gateway && isset($payload['gateway']['primary']) && is_array($payload['gateway']['primary'])) {
        $primary = $payload['gateway']['primary'];
        if (!empty($primary['label'])) {
            $gateway = $primary['label'];
        } elseif (!empty($primary['name'])) {
            $gateway = $primary['name'];
        }
    }

    $amount = null;
    if (isset($payload['payment']['amounts']['total'])) {
        $amount = $payload['payment']['amounts']['total'];
    } elseif (isset($payload['payment']['raw']['total'])) {
        $amount = $payload['payment']['raw']['total'];
    } elseif (isset($payload['Total'])) {
        $amount = $payload['Total'];
    }

    $currency = $payload['payment']['currency'] ?? ($payload['Currency'] ?? null);
    $proxyStatus = $payload['ProxyStatus'] ?? ($payload['proxy']['status'] ?? null);
    $metaDuration = $payload['_meta']['duration_ms'] ?? null;

    return [
        'status' => $status,
        'message' => $message,
        'gateway' => $gateway,
        'amount' => $amount,
        'currency' => $currency,
        'proxy_status' => $proxyStatus,
        'meta_duration' => $metaDuration,
        'effective_status' => $status,
    ];
}

function status_badge_class(string $status): string {
    $status = strtoupper($status);
    switch ($status) {
        case 'LIVE':
        case 'APPROVED':
        case 'SUCCESS':
        case 'HIT':
            return 'status-success';
        case 'DECLINED':
        case 'DEAD':
        case 'FAILED':
            return 'status-declined';
        case 'INVALID':
        case 'RATE_LIMITED':
        case 'RETRY':
        case 'PENDING':
            return 'status-warning';
        case 'ERROR':
            return 'status-error';
        default:
            return 'status-info';
    }
}

function card_state_class(string $status): string {
    $status = strtoupper($status);
    switch ($status) {
        case 'LIVE':
        case 'APPROVED':
        case 'SUCCESS':
        case 'HIT':
            return 'success';
        case 'DECLINED':
        case 'DEAD':
        case 'FAILED':
            return 'declined';
        case 'INVALID':
        case 'RATE_LIMITED':
        case 'RETRY':
        case 'PENDING':
            return 'warning';
        case 'ERROR':
            return 'error';
        default:
            return 'info';
    }
}

$input = array_merge($_GET, $_POST);
$ccRawInput = trim($input['cc'] ?? '');
$siteInput = trim($input['site'] ?? '');
$format = strtolower($input['format'] ?? 'html');
if (!in_array($format, ['html', 'json'], true)) {
    $format = 'html';
}
$formSubmitted = ($_SERVER['REQUEST_METHOD'] === 'POST') || $ccRawInput !== '' || $siteInput !== '';

$customer = [
    'first_name' => trim($input['first_name'] ?? ''),
    'last_name' => trim($input['last_name'] ?? ''),
    'cardholder_name' => trim($input['cardholder_name'] ?? ''),
    'email' => trim($input['email'] ?? ''),
    'phone' => trim($input['phone'] ?? ''),
    'street_address' => trim($input['street_address'] ?? ''),
    'street_address2' => trim($input['street_address2'] ?? ''),
    'city' => trim($input['city'] ?? ''),
    'state' => strtoupper(trim($input['state'] ?? '')),
    'postal_code' => trim($input['postal_code'] ?? ''),
    'country' => strtoupper(trim($input['country'] ?? 'US')),
    'currency' => strtoupper(trim($input['currency'] ?? 'USD')),
];
if ($customer['cardholder_name'] === '' && ($customer['first_name'] !== '' || $customer['last_name'] !== '')) {
    $customer['cardholder_name'] = trim($customer['first_name'] . ' ' . $customer['last_name']);
}

$requiredFields = ['first_name','last_name','email','phone','street_address','city','state','postal_code','country','currency'];
$fieldLabels = [
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'email' => 'Email',
    'phone' => 'Phone',
    'street_address' => 'Street Address',
    'city' => 'City',
    'state' => 'State / Region',
    'postal_code' => 'Postal Code',
    'country' => 'Country',
    'currency' => 'Currency',
];
$missingFields = [];
if ($formSubmitted) {
    foreach ($requiredFields as $field) {
        if ($customer[$field] === '') {
            $missingFields[] = $field;
        }
    }
}

$cards = $ccRawInput !== '' ? parse_cards($ccRawInput) : [];

$site = $siteInput;
if ($site !== '' && !preg_match('/^https?:\/\//i', $site)) {
    $site = 'https://' . $site;
}
$siteError = null;
if ($formSubmitted) {
    if ($site === '') {
        $siteError = 'Target site URL is required.';
    } elseif (!filter_var($site, FILTER_VALIDATE_URL)) {
        $siteError = 'Invalid site URL provided.';
    }
}

$proxyMode = $input['proxy_mode'] ?? 'auto';
if (!in_array($proxyMode, ['auto','none','custom'], true)) {
    $proxyMode = 'auto';
}
$customProxy = trim($input['custom_proxy'] ?? '');
$rotateSetting = isset($input['rotate']) ? (string)$input['rotate'] : '1';
$rotateProxies = $rotateSetting !== '0';
$debugRequested = isset($input['debug']) && in_array(strtolower((string)$input['debug']), ['1','true','on'], true);

$formErrors = [];
if ($formSubmitted) {
    if ($ccRawInput === '') {
        $formErrors[] = 'Please provide at least one credit card (number|month|year|cvv).';
    }
    if (!empty($missingFields)) {
        $prettyMissing = array_map(function (string $field) use ($fieldLabels): string {
            return $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field));
        }, $missingFields);
        $formErrors[] = 'Missing required fields: ' . implode(', ', $prettyMissing);
    }
    if ($siteError !== null) {
        $formErrors[] = $siteError;
    }
}

$autoshEndpoint = build_autosh_endpoint();
if ($formSubmitted && $autoshEndpoint === null) {
    $formErrors[] = 'Unable to resolve autosh.php endpoint from this context. Serve via HTTP to enable live checks.';
}

$proxyListPath = __DIR__ . '/ProxyList.txt';
$proxyCount = 0;
if (is_file($proxyListPath)) {
    $lines = file($proxyListPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        $proxyCount = count($lines);
    }
}

$results = [];
$stats = [
    'total' => 0,
    'live' => 0,
    'declined' => 0,
    'error' => 0,
    'invalid' => 0,
    'unknown' => 0,
];

if ($formSubmitted && empty($formErrors) && !empty($cards) && $autoshEndpoint !== null && $siteError === null) {
    foreach ($cards as $card) {
        if ($card['error'] === 'format') {
            $summary = [
                'status' => 'INVALID',
                'message' => 'Card format should be number|month|year|cvv',
                'gateway' => '',
                'amount' => null,
                'currency' => null,
                'proxy_status' => null,
                'meta_duration' => null,
                'effective_status' => 'INVALID',
            ];
            $autoResult = [
                'ok' => false,
                'http_code' => 0,
                'payload' => null,
                'raw' => null,
                'error' => 'Skipped due to invalid format',
                'duration_ms' => 0,
            ];
        } elseif ($card['number'] === '' || strlen($card['number']) < 12) {
            $summary = [
                'status' => 'INVALID',
                'message' => 'Card number missing or too short - skipped gateway call',
                'gateway' => '',
                'amount' => null,
                'currency' => null,
                'proxy_status' => null,
                'meta_duration' => null,
                'effective_status' => 'INVALID',
            ];
            $autoResult = [
                'ok' => false,
                'http_code' => 0,
                'payload' => null,
                'raw' => null,
                'error' => 'Skipped due to invalid card number',
                'duration_ms' => 0,
            ];
        } elseif (!$card['valid_luhn']) {
            $summary = [
                'status' => 'INVALID',
                'message' => 'Failed Luhn validation - skipped gateway call',
                'gateway' => '',
                'amount' => null,
                'currency' => null,
                'proxy_status' => null,
                'meta_duration' => null,
                'effective_status' => 'INVALID',
            ];
            $autoResult = [
                'ok' => false,
                'http_code' => 0,
                'payload' => null,
                'raw' => null,
                'error' => 'Skipped due to invalid Luhn',
                'duration_ms' => 0,
            ];
        } else {
            $params = [
                'cc' => $card['raw'],
                'site' => $site,
                'rotate' => $rotateProxies ? '1' : '0',
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'cardholder_name' => $customer['cardholder_name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'street_address' => $customer['street_address'],
                'street_address2' => $customer['street_address2'],
                'city' => $customer['city'],
                'state' => $customer['state'],
                'postal_code' => $customer['postal_code'],
                'country' => $customer['country'],
                'currency' => $customer['currency'],
                'pretty' => '1',
            ];
            if ($proxyMode === 'none') {
                $params['noproxy'] = '1';
            } elseif ($proxyMode === 'custom' && $customProxy !== '') {
                $params['proxy'] = $customProxy;
            }
            if ($debugRequested) {
                $params['debug'] = '1';
            }

            $autoResult = call_autosh($autoshEndpoint, $params);
            $summary = summarize_autosh_payload($autoResult['payload']);

            if (!$autoResult['ok']) {
                $summary['message'] = $autoResult['error'] ?? $summary['message'];
                $summary['status'] = 'ERROR';
                $summary['effective_status'] = 'ERROR';
            } else {
                $summary['effective_status'] = $summary['status'] ?? 'UNKNOWN';
            }
        }

        $results[] = [
            'card' => $card,
            'summary' => $summary,
            'autosh' => $autoResult,
        ];

        $effectiveStatus = strtoupper($summary['effective_status'] ?? 'UNKNOWN');
        switch ($effectiveStatus) {
            case 'LIVE':
            case 'APPROVED':
            case 'SUCCESS':
            case 'HIT':
                $stats['live']++;
                break;
            case 'DECLINED':
            case 'DEAD':
            case 'FAILED':
                $stats['declined']++;
                break;
            case 'INVALID':
                $stats['invalid']++;
                break;
            case 'ERROR':
                $stats['error']++;
                break;
            default:
                $stats['unknown']++;
                break;
        }

        if (count($cards) > 1) {
            usleep(250000);
        }
    }
}
$stats['total'] = count($results);
$executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

$jsonResults = array_map(function (array $entry): array {
    $card = $entry['card'];
    $summary = $entry['summary'];
    $autosh = $entry['autosh'];
    $proxyString = null;
    if (isset($autosh['payload']['proxy']['string']) && $autosh['payload']['proxy']['string'] !== null) {
        $proxyString = $autosh['payload']['proxy']['string'];
    } elseif (isset($autosh['payload']['ProxyIP'])) {
        $proxyString = $autosh['payload']['ProxyIP'];
    }

    return [
        'card' => [
            'raw_input' => $card['raw_input'],
            'normalized' => $card['raw'],
            'masked' => mask_card($card['number']),
            'brand' => $card['brand'],
            'luhn_valid' => $card['valid_luhn'],
            'expiry' => ($card['month'] !== '' && $card['year'] !== '') ? $card['month'] . '/' . $card['year'] : null,
        ],
        'status' => $summary['effective_status'] ?? $summary['status'] ?? 'UNKNOWN',
        'message' => $summary['message'] ?? '',
        'gateway' => $summary['gateway'] ?? '',
        'amount' => $summary['amount'],
        'currency' => $summary['currency'],
        'proxy_status' => $summary['proxy_status'] ?? null,
        'proxy_string' => $proxyString,
        'meta_duration' => $summary['meta_duration'] ?? null,
        'autosh' => [
            'ok' => $autosh['ok'],
            'http_code' => $autosh['http_code'],
            'error' => $autosh['error'],
            'duration_ms' => $autosh['duration_ms'],
            'payload' => $autosh['payload'],
        ],
    ];
}, $results);

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($formErrors),
        'errors' => $formErrors,
        'missing_fields' => $missingFields,
        'stats' => $stats,
        'request' => [
            'cards_submitted' => $ccRawInput !== '' ? count($cards) : 0,
            'site' => $site,
            'proxy_mode' => $proxyMode,
            'rotate_proxies' => $rotateProxies,
            'autosh_endpoint' => $autoshEndpoint,
            'debug' => $debugRequested,
        ],
        'results' => $jsonResults,
        'execution_time_ms' => $executionTimeMs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$countries = [
    'US' => 'United States',
    'CA' => 'Canada',
    'GB' => 'United Kingdom',
    'AU' => 'Australia',
];
if ($customer['country'] !== '' && !isset($countries[$customer['country']])) {
    $countries[$customer['country']] = $customer['country'];
}
$currencies = [
    'USD' => 'USD',
    'EUR' => 'EUR',
    'GBP' => 'GBP',
    'CAD' => 'CAD',
];
if ($customer['currency'] !== '' && !isset($currencies[$customer['currency']])) {
    $currencies[$customer['currency']] = $customer['currency'];
}
ksort($countries);
ksort($currencies);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIT – AutoSh Gateway Checker</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #3b82f6 100%);
            min-height: 100vh;
            padding: 24px;
            color: #0f172a;
        }
        .container { max-width: 1320px; margin: 0 auto; }
        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 24px;
            padding: 36px;
            margin-bottom: 28px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
        }
        h1 {
            font-size: 32px;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 12px;
        }
        .subtitle {
            color: #475569;
            font-size: 15px;
            line-height: 1.6;
        }
        .card {
            background: rgba(255, 255, 255, 0.94);
            border-radius: 22px;
            padding: 28px;
            margin-bottom: 26px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
        }
        .card h2 {
            font-size: 22px;
            color: #0f172a;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .stats-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 18px;
        }
        .stat {
            background: #f1f5f9;
            border-radius: 16px;
            padding: 18px;
        }
        .stat span {
            display: block;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 6px;
        }
        .stat strong {
            display: block;
            font-size: 26px;
            color: #0f172a;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.92);
            border-left: 5px solid;
        }
        .alert ul { margin-left: 18px; margin-top: 8px; }
        .alert-error { border-color: #ef4444; color: #991b1b; }
        .alert-info { border-color: #3b82f6; color: #1e3a8a; }
        .alert-success { border-color: #10b981; color: #065f46; }
        .result-card {
            border-left: 5px solid transparent;
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 18px;
            background: rgba(248, 250, 252, 0.9);
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.1);
        }
        .result-card.success { border-left-color: #22c55e; background: rgba(236, 253, 245, 0.95); }
        .result-card.declined { border-left-color: #ef4444; background: rgba(254, 242, 242, 0.95); }
        .result-card.warning { border-left-color: #f59e0b; background: rgba(255, 251, 235, 0.95); }
        .result-card.error { border-left-color: #f97316; background: rgba(255, 247, 237, 0.95); }
        .result-card.info { border-left-color: #6366f1; background: rgba(239, 246, 255, 0.95); }
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 16px;
            margin-bottom: 14px;
        }
        .result-header .card-meta {
            font-weight: 700;
            font-size: 18px;
            color: #0f172a;
        }
        .result-subtitle {
            font-size: 14px;
            color: #475569;
            margin-top: 4px;
        }
        .status-badge {
            padding: 6px 16px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .status-success { background: #22c55e; color: #ecfdf5; }
        .status-declined { background: #ef4444; color: #fef2f2; }
        .status-warning { background: #f97316; color: #fff7ed; }
        .status-error { background: #dc2626; color: #fee2e2; }
        .status-info { background: #6366f1; color: #eef2ff; }
        .result-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .result-meta div {
            background: rgba(255, 255, 255, 0.85);
            border-radius: 14px;
            padding: 12px 14px;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.1);
        }
        .result-meta .label {
            display: block;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .result-meta .value { font-size: 14px; color: #0f172a; font-weight: 600; }
        .raw-block {
            margin-top: 16px;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            font-family: 'SFMono-Regular', 'Menlo', 'Monaco', monospace;
            font-size: 12px;
            overflow-x: auto;
            max-height: 260px;
        }
        .raw-block summary { cursor: pointer; color: #38bdf8; margin-bottom: 10px; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
        }
        label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            color: #1f2937;
            margin-bottom: 8px;
        }
        label small { font-weight: 500; color: #94a3b8; }
        input, textarea, select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid rgba(148, 163, 184, 0.4);
            border-radius: 12px;
            font-size: 14px;
            transition: border 0.2s, box-shadow 0.2s;
            background: rgba(255, 255, 255, 0.92);
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        textarea { min-height: 90px; resize: vertical; font-family: 'SFMono-Regular', 'Menlo', 'Monaco', monospace; }
        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }
        .btn {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: #f8fafc;
            border: none;
            border-radius: 12px;
            padding: 14px 26px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 14px 25px rgba(79, 70, 229, 0.32);
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 18px 32px rgba(79, 70, 229, 0.35); }
        .btn-secondary {
            background: linear-gradient(135deg, #ec4899, #d946ef);
            box-shadow: 0 14px 25px rgba(236, 72, 153, 0.35);
        }
        .helper-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
        }
        .footer {
            color: rgba(226, 232, 240, 0.85);
            font-size: 13px;
            text-align: center;
        }
        @media (max-width: 768px) {
            body { padding: 16px; }
            .header, .card { padding: 22px; }
            .result-meta { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn, .btn-secondary { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 HIT – AutoSh Gateway Checker</h1>
            <p class="subtitle">
                Execute live Shopify (and compatible) payment flows via <code>autosh.php</code>, complete with proxy rotation, advanced gateway detection, and full billing profile control.
                <br>
                Proxy pool: <?= (int)$proxyCount ?> • AutoSh endpoint: <?= htmlspecialchars($autoshEndpoint ?? 'Unavailable') ?> • Execution: <?= number_format($executionTimeMs, 2) ?> ms
            </p>
        </div>

        <?php if (!empty($formErrors)): ?>
            <div class="alert alert-error">
                ⚠️ We found <?= count($formErrors) ?> issue<?= count($formErrors) > 1 ? 's' : '' ?>:
                <ul>
                    <?php foreach ($formErrors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
            <div class="card stats-card">
                <div class="stat">
                    <span>Total Checked</span>
                    <strong><?= (int)$stats['total'] ?></strong>
                </div>
                <div class="stat">
                    <span>Live / Approved</span>
                    <strong><?= (int)$stats['live'] ?></strong>
                </div>
                <div class="stat">
                    <span>Declined</span>
                    <strong><?= (int)$stats['declined'] ?></strong>
                </div>
                <div class="stat">
                    <span>Errors</span>
                    <strong><?= (int)$stats['error'] ?></strong>
                </div>
                <div class="stat">
                    <span>Invalid / Skipped</span>
                    <strong><?= (int)$stats['invalid'] ?></strong>
                </div>
            </div>

            <div class="card">
                <h2>📊 Gateway Responses</h2>
                <?php foreach ($results as $entry): ?>
                    <?php
                        $card = $entry['card'];
                        $summary = $entry['summary'];
                        $autosh = $entry['autosh'];
                        $status = strtoupper($summary['effective_status'] ?? 'UNKNOWN');
                        $badgeClass = status_badge_class($status);
                        $cardClass = card_state_class($status);
                        $maskedCard = mask_card($card['number']);
                        $amountDisplay = '—';
                        if ($summary['amount'] !== null && $summary['amount'] !== '') {
                            $amountValue = is_numeric($summary['amount']) ? number_format((float)$summary['amount'], 2) : (string)$summary['amount'];
                            $amountDisplay = $summary['currency'] ? $amountValue . ' ' . $summary['currency'] : $amountValue;
                        }
                        $proxyString = $autosh['payload']['proxy']['string'] ?? ($autosh['payload']['ProxyIP'] ?? 'N/A');
                        $proxyStatus = $summary['proxy_status'] ?? ($autosh['payload']['ProxyStatus'] ?? 'N/A');
                        $callTime = number_format((float)$autosh['duration_ms'], 2) . ' ms';
                        if (!empty($summary['meta_duration'])) {
                            $callTime .= ' • flow ' . number_format((float)$summary['meta_duration'], 2) . ' ms';
                        }
                        $rawPayload = $autosh['payload'];
                        $rawJson = $rawPayload !== null ? json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'null';
                    ?>
                    <div class="result-card <?= htmlspecialchars($cardClass) ?>">
                        <div class="result-header">
                            <div>
                                <div class="card-meta"><?= htmlspecialchars($maskedCard) ?> · <?= htmlspecialchars($card['brand']) ?></div>
                                <div class="result-subtitle"><?= htmlspecialchars($card['month'] . '/' . $card['year']) ?> • Luhn <?= $card['valid_luhn'] ? '✅' : '❌' ?></div>
                            </div>
                            <span class="status-badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                        <div class="result-meta">
                            <div>
                                <span class="label">Message</span>
                                <span class="value"><?= htmlspecialchars($summary['message'] ?? '') ?></span>
                            </div>
                            <div>
                                <span class="label">Gateway</span>
                                <span class="value"><?= htmlspecialchars($summary['gateway'] ?? 'Unknown') ?></span>
                            </div>
                            <div>
                                <span class="label">Amount</span>
                                <span class="value"><?= htmlspecialchars($amountDisplay) ?></span>
                            </div>
                            <div>
                                <span class="label">Proxy</span>
                                <span class="value"><?= htmlspecialchars($proxyStatus) ?><?= $proxyString ? ' · ' . htmlspecialchars($proxyString) : '' ?></span>
                            </div>
                            <div>
                                <span class="label">Response</span>
                                <span class="value"><?= htmlspecialchars($callTime) ?></span>
                            </div>
                            <div>
                                <span class="label">HTTP</span>
                                <span class="value"><?= (int)$autosh['http_code'] ?></span>
                            </div>
                        </div>
                        <?php if (!$autosh['ok'] && $autosh['error']): ?>
                            <div class="helper-text" style="margin-top:12px; color:#b91c1c;">
                                ⚠️ AutoSh error: <?= htmlspecialchars($autosh['error']) ?>
                            </div>
                        <?php endif; ?>
                        <details class="raw-block">
                            <summary>Raw AutoSh Payload</summary>
                            <pre><?= htmlspecialchars($rawJson) ?></pre>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($formSubmitted && empty($formErrors)): ?>
            <div class="card">
                <h2>No Results</h2>
                <p>No card checks were executed. Verify that your card list is in the correct format and try again.</p>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>🚀 Check Credit Cards</h2>
            <form method="post" action="">
                <div class="form-grid">
                    <div>
                        <label>Credit Card(s) <small>Format: number|month|year|cvv</small></label>
                        <textarea name="cc" required placeholder="4111111111111111|12|2027|123"><?= htmlspecialchars($ccRawInput) ?></textarea>
                        <div class="helper-text">Multiple cards supported (newline or semicolon separated).</div>
                    </div>
                    <div>
                        <label>Target Site URL *</label>
                        <input type="url" name="site" value="<?= htmlspecialchars($siteInput) ?>" placeholder="https://example.myshopify.com" required>
                    </div>
                </div>

                <h3 style="margin:24px 0 12px; font-size:16px; color:#1e293b;">📇 Billing Profile</h3>
                <div class="form-grid">
                    <div>
                        <label>First Name *</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($customer['first_name']) ?>" required>
                    </div>
                    <div>
                        <label>Last Name *</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($customer['last_name']) ?>" required>
                    </div>
                    <div>
                        <label>Cardholder Name</label>
                        <input type="text" name="cardholder_name" value="<?= htmlspecialchars($customer['cardholder_name']) ?>" placeholder="Defaults to first + last name">
                    </div>
                    <div>
                        <label>Email *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($customer['email']) ?>" required>
                    </div>
                    <div>
                        <label>Phone *</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($customer['phone']) ?>" required>
                    </div>
                </div>

                <div class="form-grid" style="margin-top:18px;">
                    <div>
                        <label>Street Address *</label>
                        <input type="text" name="street_address" value="<?= htmlspecialchars($customer['street_address']) ?>" required>
                    </div>
                    <div>
                        <label>Address Line 2</label>
                        <input type="text" name="street_address2" value="<?= htmlspecialchars($customer['street_address2']) ?>" placeholder="Apartment, suite, etc.">
                    </div>
                    <div>
                        <label>City *</label>
                        <input type="text" name="city" value="<?= htmlspecialchars($customer['city']) ?>" required>
                    </div>
                    <div>
                        <label>State / Region *</label>
                        <input type="text" name="state" value="<?= htmlspecialchars($customer['state']) ?>" required>
                    </div>
                    <div>
                        <label>Postal Code *</label>
                        <input type="text" name="postal_code" value="<?= htmlspecialchars($customer['postal_code']) ?>" required>
                    </div>
                    <div>
                        <label>Country *</label>
                        <select name="country" required>
                            <?php foreach ($countries as $code => $label): ?>
                                <option value="<?= htmlspecialchars($code) ?>" <?= $customer['country'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Currency *</label>
                        <select name="currency" required>
                            <?php foreach ($currencies as $code => $label): ?>
                                <option value="<?= htmlspecialchars($code) ?>" <?= $customer['currency'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h3 style="margin:24px 0 12px; font-size:16px; color:#1e293b;">🛡️ Execution Options</h3>
                <div class="form-grid">
                    <div>
                        <label>Proxy Mode</label>
                        <select name="proxy_mode">
                            <option value="auto" <?= $proxyMode === 'auto' ? 'selected' : '' ?>>Auto (use ProxyList or auto-detect)</option>
                            <option value="none" <?= $proxyMode === 'none' ? 'selected' : '' ?>>Direct (no proxy)</option>
                            <option value="custom" <?= $proxyMode === 'custom' ? 'selected' : '' ?>>Custom (specify below)</option>
                        </select>
                        <div class="helper-text">Custom mode supports http://user:pass@host:port or socks proxies.</div>
                    </div>
                    <div>
                        <label>Custom Proxy</label>
                        <input type="text" name="custom_proxy" value="<?= htmlspecialchars($customProxy) ?>" placeholder="socks5://user:pass@host:port">
                    </div>
                    <div>
                        <label>Rotate Proxies</label>
                        <select name="rotate">
                            <option value="1" <?= $rotateProxies ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= !$rotateProxies ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                    <div>
                        <label>Output Format</label>
                        <select name="format">
                            <option value="html" <?= $format === 'html' ? 'selected' : '' ?>>HTML</option>
                            <option value="json" <?= $format === 'json' ? 'selected' : '' ?>>JSON API</option>
                        </select>
                    </div>
                    <div>
                        <label>Debug Mode</label>
                        <select name="debug">
                            <option value="0" <?= !$debugRequested ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= $debugRequested ? 'selected' : '' ?>>Enabled</option>
                        </select>
                        <div class="helper-text">Enables verbose logging within autosh.php (slower).</div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">⚡ Run AutoSh Checks</button>
                    <button type="button" class="btn btn-secondary" onclick="fillTestData()">🧪 Fill Test Data</button>
                </div>
            </form>
        </div>

        <p class="footer">
            Generated with AutoSh v2025 • Ensure compliance with local laws. Use against assets you are authorized to test.
        </p>
    </div>

    <script>
        function fillTestData() {
            const setValue = (selector, value) => {
                const el = document.querySelector(selector);
                if (!el) return;
                if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                    el.value = value;
                }
            };
            setValue('[name="cc"]', '4111111111111111|12|2027|123');
            setValue('[name="site"]', 'https://example.myshopify.com');
            setValue('[name="first_name"]', 'John');
            setValue('[name="last_name"]', 'Smith');
            setValue('[name="cardholder_name"]', 'John Smith');
            setValue('[name="email"]', 'john.smith@example.com');
            setValue('[name="phone"]', '+12125551234');
            setValue('[name="street_address"]', '350 5th Ave');
            setValue('[name="street_address2"]', 'Suite 1200');
            setValue('[name="city"]', 'New York');
            setValue('[name="state"]', 'NY');
            setValue('[name="postal_code"]', '10118');
            setValue('[name="country"]', 'US');
            setValue('[name="currency"]', 'USD');
            setValue('[name="proxy_mode"]', 'auto');
            setValue('[name="custom_proxy"]', '');
            setValue('[name="rotate"]', '1');
            setValue('[name="format"]', 'html');
            setValue('[name="debug"]', '0');
        }
    </script>
</body>
</html>
