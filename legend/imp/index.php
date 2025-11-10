<?php
declare(strict_types=1);

const DASHBOARD_PROXY_FILE = __DIR__ . '/ProxyList.txt';
const DASHBOARD_CONCURRENCY_LIMIT = 200;

/**
 * Parse a proxy definition string into structured details.
 *
 * @param string $line Raw proxy definition line.
 * @return array|null Structured details or null when parsing fails.
 */
function parse_proxy_line(string $line): ?array
{
    $raw = trim($line);
    if ($raw === '' || $raw[0] === '#') {
        return null;
    }

    $type = 'http';
    $body = $raw;

    if (preg_match('#^(?P<scheme>[a-z0-9]+)://(?P<body>.+)$#i', $raw, $match)) {
        $type = strtolower($match['scheme']);
        $body = $match['body'];
    }

    $hasAuth = false;
    $username = '';

    if (strpos($body, '@') !== false) {
        [$auth, $addr] = explode('@', $body, 2);
        $body = $addr;
        $username = $auth;
        $hasAuth = true;
    }

    $parts = array_map('trim', explode(':', $body));
    if (count($parts) < 2 || !is_numeric($parts[1])) {
        return null;
    }

    $host = $parts[0];
    $port = (int) $parts[1];

    if (!$hasAuth && count($parts) >= 4) {
        $hasAuth = true;
        $username = $parts[2];
    }

    return [
        'type' => $type,
        'host' => $host,
        'port' => $port,
        'hasAuth' => $hasAuth,
        'user' => $username,
        'display' => sprintf('%s://%s:%s', $type, $host, $parts[1]),
    ];
}

/**
 * Summarise proxies from ProxyList.txt into dashboard-ready stats.
 *
 * @param string $file Proxy list file path.
 * @return array Summary data.
 */
function summarize_proxy_list(string $file): array
{
    $summary = [
        'total' => 0,
        'unique' => 0,
        'withAuth' => 0,
        'byType' => [],
        'sample' => [],
        'lastUpdated' => is_file($file) ? filemtime($file) : null,
    ];

    if (!is_file($file)) {
        return $summary;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $seenHashes = [];

    foreach ($lines as $line) {
        $parsed = parse_proxy_line($line);
        if ($parsed === null) {
            continue;
        }

        $summary['total']++;

        $hash = strtolower($parsed['type'] . '://' . $parsed['host'] . ':' . $parsed['port']);
        if (!isset($seenHashes[$hash])) {
            $summary['unique']++;
            $seenHashes[$hash] = true;
        }

        if (!isset($summary['byType'][$parsed['type']])) {
            $summary['byType'][$parsed['type']] = 0;
        }
        $summary['byType'][$parsed['type']]++;

        if ($parsed['hasAuth']) {
            $summary['withAuth']++;
        }

        if (count($summary['sample']) < 5) {
            $summary['sample'][] = $parsed['display'];
        }
    }

    ksort($summary['byType']);

    return $summary;
}

/**
 * Return the last N lines from a log file as an array.
 *
 * @param string $file File to tail.
 * @param int $lines Number of lines to return.
 * @return array<string> Tail lines (oldest to newest).
 */
function tail_file(string $file, int $lines = 10): array
{
    if (!is_file($file)) {
        return [];
    }

    $content = file($file, FILE_IGNORE_NEW_LINES);
    if ($content === false) {
        return [];
    }

    return array_slice($content, -$lines);
}

/**
 * Convert a timestamp into a human-readable relative string.
 *
 * @param int $timestamp Unix timestamp.
 * @return string Human-readable relative time.
 */
function format_relative_time(int $timestamp): string
{
    $diff = time() - $timestamp;
    if ($diff < 0) {
        return 'in the future';
    }
    if ($diff < 60) {
        return $diff . 's ago';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    return floor($diff / 86400) . 'd ago';
}

/**
 * Compute the base URL of the dashboard.
 *
 * @return string Base URL ending with a trailing slash.
 */
function dashboard_base_url(): string
{
    $scheme = 'http';
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['PHP_SELF'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '.') {
        $dir = '';
    }

    return rtrim($scheme . '://' . $host . ($dir ? '/' . ltrim($dir, '/') : ''), '/') . '/';
}

/**
 * Get supported payment gateways list
 */
function get_supported_gateways(): array
{
    return [
        ['id' => 'stripe', 'name' => 'Stripe', 'icon' => '💳', 'category' => 'major'],
        ['id' => 'paypal', 'name' => 'PayPal / Braintree', 'icon' => '💰', 'category' => 'major'],
        ['id' => 'razorpay', 'name' => 'Razorpay', 'icon' => '⚡', 'category' => 'major'],
        ['id' => 'payu', 'name' => 'PayU', 'icon' => '🏦', 'category' => 'major'],
        ['id' => 'woocommerce', 'name' => 'WooCommerce', 'icon' => '🛒', 'category' => 'platform'],
        ['id' => 'shopify', 'name' => 'Shopify', 'icon' => '🛍️', 'category' => 'platform'],
        ['id' => 'magento', 'name' => 'Magento', 'icon' => '🏪', 'category' => 'platform'],
        ['id' => 'bigcommerce', 'name' => 'BigCommerce', 'icon' => '🏬', 'category' => 'platform'],
        ['id' => 'adyen', 'name' => 'Adyen', 'icon' => '🔐', 'category' => 'enterprise'],
        ['id' => 'checkout_com', 'name' => 'Checkout.com', 'icon' => '✅', 'category' => 'enterprise'],
        ['id' => 'authorize_net', 'name' => 'Authorize.Net', 'icon' => '🔒', 'category' => 'traditional'],
        ['id' => 'square', 'name' => 'Square', 'icon' => '⬛', 'category' => 'pos'],
        ['id' => 'cashfree', 'name' => 'Cashfree', 'icon' => '💸', 'category' => 'regional'],
        ['id' => 'instamojo', 'name' => 'Instamojo', 'icon' => '📱', 'category' => 'regional'],
        ['id' => 'ccavenue', 'name' => 'CCAvenue', 'icon' => '🏛️', 'category' => 'regional'],
        ['id' => 'paytm', 'name' => 'Paytm', 'icon' => '📲', 'category' => 'regional'],
        ['id' => 'phonepe', 'name' => 'PhonePe', 'icon' => '📞', 'category' => 'regional'],
        ['id' => 'flutterwave', 'name' => 'Flutterwave', 'icon' => '🦋', 'category' => 'regional'],
        ['id' => 'paystack', 'name' => 'Paystack', 'icon' => '📚', 'category' => 'regional'],
        ['id' => 'mollie', 'name' => 'Mollie', 'icon' => '🇳🇱', 'category' => 'regional'],
        ['id' => 'mercadopago', 'name' => 'Mercado Pago', 'icon' => '🇧🇷', 'category' => 'regional'],
        ['id' => 'klarna', 'name' => 'Klarna', 'icon' => '🔷', 'category' => 'bnpl'],
        ['id' => 'afterpay', 'name' => 'Afterpay', 'icon' => '🔶', 'category' => 'bnpl'],
        ['id' => 'affirm', 'name' => 'Affirm', 'icon' => '🔸', 'category' => 'bnpl'],
        ['id' => 'coinbase_commerce', 'name' => 'Coinbase Commerce', 'icon' => '₿', 'category' => 'crypto'],
        ['id' => 'bitpay', 'name' => 'BitPay', 'icon' => '🪙', 'category' => 'crypto'],
    ];
}

/**
 * Build the dashboard dataset used by the UI and the JSON endpoint.
 *
 * @return array Dashboard data.
 */
function get_dashboard_data(): array
{
    $proxySummary = summarize_proxy_list(DASHBOARD_PROXY_FILE);
    $healthCache = __DIR__ . '/proxy_check_time.txt';
    $lastHealthEpoch = is_file($healthCache) ? (int) trim((string) file_get_contents($healthCache)) : null;

    $base = dashboard_base_url();

    return [
        'generatedAt' => date(DATE_ATOM),
        'proxies' => [
            'total' => $proxySummary['total'],
            'unique' => $proxySummary['unique'],
            'withAuth' => $proxySummary['withAuth'],
            'withoutAuth' => max(0, $proxySummary['total'] - $proxySummary['withAuth']),
            'byType' => $proxySummary['byType'],
            'sample' => $proxySummary['sample'],
            'lastUpdatedEpoch' => $proxySummary['lastUpdated'],
            'lastUpdated' => $proxySummary['lastUpdated'] ? date(DATE_ATOM, $proxySummary['lastUpdated']) : null,
            'lastUpdatedHuman' => $proxySummary['lastUpdated'] ? format_relative_time($proxySummary['lastUpdated']) : 'Never',
        ],
        'system' => [
            'phpVersion' => PHP_VERSION,
            'serverSoftware' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
            'cwd' => __DIR__,
            'concurrencyCap' => DASHBOARD_CONCURRENCY_LIMIT,
            'lastHealthCheckEpoch' => $lastHealthEpoch,
            'lastHealthCheck' => $lastHealthEpoch ? date(DATE_ATOM, $lastHealthEpoch) : null,
            'lastHealthCheckHuman' => $lastHealthEpoch ? format_relative_time($lastHealthEpoch) : 'Never',
        ],
        'logs' => [
            'proxy' => tail_file(__DIR__ . '/proxy_log.txt', 8),
            'rotation' => tail_file(__DIR__ . '/proxy_rotation.log', 8),
        ],
        'endpoints' => [
            'fetch' => $base . 'fetch_proxies.php',
            'fetchApi' => $base . 'fetch_proxies.php?api=1',
            'health' => $base . 'health.php',
            'autosh' => $base . 'autosh.php',
            'proxyExample' => $base . 'proxy_example.php',
        ],
        'gateways' => get_supported_gateways(),
    ];
}

if (isset($_GET['stats'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(get_dashboard_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$dashboard = get_dashboard_data();

$proxyTotal = $dashboard['proxies']['total'];
$uniqueCoverage = $proxyTotal > 0 ? (int) round(($dashboard['proxies']['unique'] / $proxyTotal) * 100) : 0;
$authCoverage = $proxyTotal > 0 ? (int) round(($dashboard['proxies']['withAuth'] / $proxyTotal) * 100) : 0;
$noAuthCoverage = $proxyTotal > 0 ? max(0, 100 - $authCoverage) : 0;
$concurrencyCap = max(1, (int) ($dashboard['system']['concurrencyCap'] ?? DASHBOARD_CONCURRENCY_LIMIT));
$availabilityScore = $dashboard['proxies']['unique'] > 0 ? (int) min(100, round(($dashboard['proxies']['unique'] / $concurrencyCap) * 100)) : 0;
$generatedTimestamp = strtotime($dashboard['generatedAt'] ?? '') ?: time();
$generatedTimeShort = date('H:i:s', $generatedTimestamp);

$topProxyType = null;
$topProxyTypeCount = 0;
$topProxyTypeShare = 0;
if (!empty($dashboard['proxies']['byType'])) {
    $sortedTypes = $dashboard['proxies']['byType'];
    arsort($sortedTypes);
    $topProxyType = (string) key($sortedTypes);
    $topProxyTypeCount = (int) current($sortedTypes);
    if ($proxyTotal > 0) {
        $topProxyTypeShare = (int) round(($topProxyTypeCount / max(1, $proxyTotal)) * 100);
    }
}

/**
 * HTML-escape helper.
 *
 * @param mixed $value Value to escape.
 * @return string Escaped string.
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎯 Advanced Payment & Proxy Intelligence Hub | LEGEND_BL</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #6366f1;
            --primary-dark: #4338ca;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
            --gray: #64748b;
            --muted: #64748b;
            --border: rgba(226, 232, 240, 0.7);
            --border-strong: rgba(148, 163, 184, 0.4);
            --surface: rgba(255, 255, 255, 0.96);
            --surface-soft: rgba(255, 255, 255, 0.75);
            --surface-strong: rgba(255, 255, 255, 1);
            --chip-bg: rgba(99, 102, 241, 0.12);
            --chip-border: rgba(99, 102, 241, 0.35);
            --page-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --shadow-soft: 0 20px 60px rgba(15, 23, 42, 0.16);
            --shadow-strong: 0 25px 80px rgba(15, 23, 42, 0.35);
            --glass-blur: 14px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: var(--page-gradient);
            background-attachment: fixed;
            min-height: 100vh;
            padding: 24px;
            color: var(--dark);
            line-height: 1.6;
            transition: background 0.6s ease, color 0.4s ease;
            position: relative;
            color-scheme: light;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(148, 163, 255, 0.25) 0%, rgba(255, 255, 255, 0) 55%),
                        radial-gradient(circle at bottom right, rgba(99, 102, 241, 0.2) 0%, rgba(12, 6, 105, 0.1) 45%, rgba(12, 6, 105, 0) 65%);
            pointer-events: none;
            z-index: 0;
            transition: opacity 0.6s ease;
        }

        body[data-theme="dark"] {
            --page-gradient: radial-gradient(circle at 20% 20%, #312e81 0%, #0b1120 55%, #020617 100%);
            --surface: rgba(17, 24, 39, 0.9);
            --surface-soft: rgba(30, 41, 59, 0.55);
            --surface-strong: rgba(15, 23, 42, 0.88);
            --dark: #f8fafc;
            --gray: #94a3b8;
            --muted: #a5b4fc;
            --light: #0f172a;
            --border: rgba(148, 163, 184, 0.25);
            --border-strong: rgba(99, 102, 241, 0.35);
            --shadow-soft: 0 20px 60px rgba(2, 6, 23, 0.5);
            --shadow-strong: 0 30px 80px rgba(2, 6, 23, 0.75);
            --chip-bg: rgba(129, 140, 248, 0.18);
            --chip-border: rgba(129, 140, 248, 0.4);
            color-scheme: dark;
        }

        body[data-theme="dark"]::before {
            opacity: 0.55;
            background: radial-gradient(circle at 20% 20%, rgba(79, 70, 229, 0.3) 0%, rgba(12, 10, 35, 0.15) 50%, rgba(12, 10, 35, 0) 70%),
                        radial-gradient(circle at 80% 80%, rgba(244, 114, 182, 0.25) 0%, rgba(2, 6, 23, 0.6) 60%, rgba(2, 6, 23, 0) 80%);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .header {
            background: var(--surface);
            border: 1px solid var(--border-strong);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 32px;
            box-shadow: var(--shadow-strong);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(var(--glass-blur));
            transition: background 0.4s ease, border-color 0.4s ease, box-shadow 0.4s ease;
        }

        .header::before {
            content: "";
            position: absolute;
            inset: -120px auto auto 55%;
            width: 460px;
            height: 460px;
            background: radial-gradient(circle at center, rgba(99, 102, 241, 0.35), rgba(99, 102, 241, 0.08) 45%, transparent 70%);
            filter: blur(0);
            pointer-events: none;
            transition: opacity 0.4s ease;
        }

        h1 {
            color: var(--primary-dark);
            margin-bottom: 12px;
            font-size: 38px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            letter-spacing: -0.02em;
        }

        h1 span {
            font-size: 42px;
            animation: pulse 2s ease-in-out infinite;
        }

        .header-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 10px;
        }

        .toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .toolbar-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--chip-bg);
            color: var(--primary-dark);
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid var(--chip-border);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
        }

        body[data-theme="dark"] .toolbar-chip {
            color: #e0e7ff;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            position: relative;
            box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.25);
        }

        .pulse-dot::after {
            content: "";
            position: absolute;
            inset: -6px;
            border-radius: inherit;
            border: 2px solid rgba(16, 185, 129, 0.25);
            animation: ripple 2.4s infinite ease-out;
        }

        @keyframes ripple {
            0% { transform: scale(0.6); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        .toolbar-btn {
            position: relative;
            border: 1px solid var(--border-strong);
            background: var(--surface-strong);
            border-radius: 999px;
            padding: 8px 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 10px 25px rgba(15, 23, 42, 0.16);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.4s ease, color 0.4s ease;
        }

        .toolbar-btn:hover {
            transform: translateY(-2px);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 15px 35px rgba(99, 102, 241, 0.25);
        }

        .toolbar-btn .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: transform 0.4s ease, opacity 0.3s ease;
        }

        .toolbar-btn .moon {
            position: absolute;
            right: 16px;
            opacity: 0;
            transform: translateY(8px);
        }

        body[data-theme="dark"] .toolbar-btn {
            color: #e2e8f0;
        }

        body[data-theme="dark"] .toolbar-btn .sun {
            opacity: 0;
            transform: translateY(-8px);
        }

        body[data-theme="dark"] .toolbar-btn .moon {
            opacity: 1;
            transform: translateY(0);
        }

        .toolbar-label {
            padding-left: 24px;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .subtitle {
            color: var(--muted);
            font-size: 16px;
            line-height: 1.7;
            max-width: 900px;
            margin-bottom: 16px;
        }

        .owner-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 14px;
            margin-top: 15px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .owner-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .owner-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-soft);
            color: var(--gray);
            padding: 8px 16px;
            border-radius: 18px;
            font-size: 13px;
            border: 1px solid var(--border);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: var(--success);
            color: white;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            transition: transform 0.2s ease;
        }

        .status:hover {
            transform: translateY(-2px);
        }

        .status.orange { background: var(--warning); box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3); }
        .status.teal { background: #14b8a6; box-shadow: 0 4px 15px rgba(20, 184, 166, 0.3); }
        .status.blue { background: var(--info); box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); }
        .status.purple { background: var(--secondary); box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3); }

        .telemetry {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 12px;
            margin-top: 20px;
        }

        .telemetry-item {
            flex: 1 1 220px;
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 18px;
            position: relative;
            overflow: hidden;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
        }

        .telemetry-item::after {
            content: "";
            position: absolute;
            inset: -60% 60% auto -40%;
            height: 160%;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.18), rgba(139, 92, 246, 0.05));
            opacity: 0.4;
            pointer-events: none;
            transform: rotate(12deg);
        }

        .telemetry-item .label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--gray);
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
        }

        .telemetry-item .value {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            position: relative;
            z-index: 1;
        }

        .telemetry-item .sub {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: var(--gray);
            position: relative;
            z-index: 1;
        }

        .micro-bar {
            position: relative;
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: rgba(99, 102, 241, 0.2);
            margin-top: 12px;
            overflow: hidden;
            z-index: 1;
        }

        .micro-bar::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            width: var(--progress, 0%);
            max-width: 100%;
            transition: width 0.5s ease;
        }

        .header-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-top: 24px;
        }

        .gauge-card {
            flex: 1 1 260px;
            background: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 20px;
            position: relative;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
            overflow: hidden;
        }

        .gauge-card::after {
            content: "";
            position: absolute;
            inset: auto -60px -120px 40%;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle at center, rgba(129, 140, 248, 0.18), transparent 65%);
            pointer-events: none;
        }

        .radial-gauge {
            --value: 64;
            width: 140px;
            aspect-ratio: 1 / 1;
            border-radius: 50%;
            background: conic-gradient(var(--primary) calc(var(--value) * 1%), rgba(99, 102, 241, 0.15) 0);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            position: relative;
            box-shadow: 0 12px 30px rgba(99, 102, 241, 0.18);
        }

        .radial-gauge::before {
            content: "";
            position: absolute;
            inset: 12px;
            background: var(--surface);
            border-radius: 50%;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }

        .radial-inner {
            position: relative;
            text-align: center;
        }

        .radial-inner span {
            font-size: 26px;
            font-weight: 800;
            color: var(--primary-dark);
            display: block;
        }

        .radial-inner small {
            display: block;
            font-size: 12px;
            color: var(--gray);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .gauge-caption {
            text-align: center;
            font-size: 13px;
            color: var(--gray);
            margin-top: 6px;
        }

        .gauge-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .meta-label {
            display: block;
            font-size: 13px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .meta-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
        }

        .meta-pill {
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            color: var(--success);
            font-weight: 600;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }

        .meta-pill.secondary {
            background: rgba(139, 92, 246, 0.18);
            color: var(--secondary);
            border: 1px solid rgba(139, 92, 246, 0.45);
        }

        .insight-progress {
            position: relative;
            height: 12px;
            border-radius: 12px;
            background: rgba(99, 102, 241, 0.12);
            overflow: hidden;
        }

        .insight-progress::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            width: var(--progress, 0%);
            max-width: 100%;
            transition: width 0.5s ease;
        }

        .gauge-split {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            font-size: 13px;
            color: var(--gray);
        }

        .split-label {
            display: block;
            font-weight: 600;
            color: var(--gray);
        }

        .split-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 15px;
            border-radius: 15px;
            box-shadow: var(--shadow-soft);
            overflow-x: auto;
            backdrop-filter: blur(var(--glass-blur));
        }

        .tab {
            padding: 12px 24px;
            background: transparent;
            border: 2px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .tab:hover {
            background: var(--light);
            border-color: var(--primary);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .card {
            background: var(--surface);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
        }

        .card h2 {
            color: var(--dark);
            margin-bottom: 18px;
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card p {
            color: var(--gray);
            margin-bottom: 16px;
            font-size: 14px;
            line-height: 1.6;
        }

        .metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .metric strong {
            color: var(--dark);
            font-size: 14px;
        }

        .metric span {
            color: var(--primary);
            font-weight: 700;
            font-size: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
            margin-right: 10px;
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }

        .btn-warning:hover {
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 12px;
            font-family: 'Fira Code', 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin-top: 12px;
            line-height: 1.7;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        body[data-theme="dark"] .code-block {
            background: #020617;
            color: #e2e8f0;
            box-shadow: inset 0 2px 12px rgba(15, 23, 42, 0.7);
        }

        .info-box {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-left: 4px solid var(--info);
            padding: 20px;
            margin-top: 25px;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
        }

        body[data-theme="dark"] .info-box {
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.55), rgba(30, 64, 175, 0.25));
            color: #e2e8f0;
            box-shadow: 0 8px 25px rgba(30, 64, 175, 0.35);
        }

        .info-box h3 {
            color: #1e40af;
            margin-bottom: 12px;
            font-size: 17px;
            font-weight: 700;
        }

        body[data-theme="dark"] .info-box h3 {
            color: #bfdbfe;
        }

        .info-box p {
            color: #1e293b;
            font-size: 14px;
            margin: 6px 0;
            line-height: 1.6;
        }

        body[data-theme="dark"] .info-box p {
            color: #e2e8f0;
        }

        .info-box.success {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-left-color: var(--success);
        }

        .info-box.warning {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            border-left-color: var(--warning);
        }

        .info-box.warning h3 {
            color: #92400e;
        }

        .log-box {
            background: #0f172a;
            color: #cbd5e1;
            padding: 20px;
            border-radius: 12px;
            font-family: 'Fira Code', 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.7;
            height: 250px;
            overflow-y: auto;
            margin-top: 15px;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .gateway-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .gateway-card {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .gateway-card:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.2);
        }

        .gateway-card .icon {
            font-size: 36px;
            margin-bottom: 8px;
        }

        .gateway-card .name {
            font-size: 13px;
            font-weight: 600;
            color: var(--dark);
        }

        .gateway-card .category {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            margin-top: 5px;
        }

        .search-box {
            width: 100%;
            padding: 14px 20px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .feature-list {
            list-style: none;
            padding: 0;
        }

        .feature-list li {
            padding: 10px 0;
            color: var(--gray);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .feature-list li:before {
            content: "✓";
            color: var(--success);
            font-weight: bold;
            font-size: 18px;
        }

        .stats-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);
        }

        .stats-card h3 {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stats-card p {
            font-size: 14px;
            opacity: 0.9;
            color: white;
        }

        form {
            background: var(--surface-soft);
            padding: 20px;
            border-radius: 12px;
            margin-top: 15px;
            border: 1px solid var(--border);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        form label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 6px;
        }

        form input, form select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
            transition: border-color 0.3s ease;
        }

        form input:focus, form select:focus {
            outline: none;
            border-color: var(--primary);
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .gateway-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }

            h1 {
                font-size: 28px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .toolbar {
                width: 100%;
                justify-content: space-between;
            }

            .owner-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-metrics {
                flex-direction: column;
            }

            .tabs {
                overflow-x: scroll;
            }
        }

        .fab {
            position: fixed;
            right: 32px;
            bottom: 32px;
            width: 58px;
            height: 58px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            cursor: pointer;
            box-shadow: var(--shadow-strong);
            transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.4s ease;
            z-index: 50;
        }

        .fab:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 25px 70px rgba(99, 102, 241, 0.4);
        }

        .fab:active {
            transform: scale(0.96);
        }

        .fab .fab-icon {
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .fab .fab-icon.loading {
            opacity: 0;
            transform: scale(0.8);
        }

        .fab.is-loading .fab-icon.default {
            opacity: 0;
            transform: scale(0.8);
        }

        .fab.is-loading .fab-icon.loading {
            opacity: 1;
            transform: scale(1);
        }

        @media (max-width: 768px) {
            .fab {
                right: 24px;
                bottom: 24px;
            }
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.php').catch(function () { /* ignore */ });
    });
}
</script>
<div class="container">
    <div class="header">
        <div class="header-top">
            <h1><span>🎯</span> Advanced Payment & Proxy Intelligence Hub</h1>
            <div class="toolbar">
                <div class="toolbar-chip">
                    <span class="pulse-dot"></span>
                    Live Telemetry
                </div>
                <button type="button" class="toolbar-btn" id="theme-toggle" aria-label="Toggle theme" aria-pressed="false">
                    <span class="icon sun">☀️</span>
                    <span class="icon moon">🌙</span>
                    <span class="toolbar-label" id="theme-toggle-label">Light</span>
                </button>
            </div>
        </div>
        <p class="subtitle">
            Enterprise-grade proxy rotation system with multi-gateway payment reconnaissance. 
            Supports <strong>50+ payment gateways</strong> including Stripe, PayPal, Razorpay, PayU, WooCommerce, 
            Shopify and more. Features automated proxy scraping from <strong>12+ sources</strong>, 
            concurrent testing at <strong>200× parallelism</strong>, and real-time health monitoring.
        </p>
        <div class="owner-row">
            <div class="owner-badge">
                👑 Powered by @LEGEND_BL
            </div>
            <div class="owner-chip">
                <span>🖥️</span>
                <span><?= h($dashboard['system']['serverSoftware']) ?></span>
            </div>
            <div class="owner-chip">
                <span>📂</span>
                <span><?= h(basename((string) $dashboard['system']['cwd'])) ?></span>
            </div>
        </div>
        <div class="telemetry">
            <div class="telemetry-item">
                <span class="label">Last Sync</span>
                <span class="value" id="telemetry-generated"><?= h($generatedTimeShort) ?></span>
                <span class="sub">Auto refresh every 15s</span>
            </div>
            <div class="telemetry-item">
                <span class="label">Auth Coverage</span>
                <span class="value"><span id="auth-coverage-value"><?= h($authCoverage) ?>%</span></span>
                <div class="micro-bar" id="auth-coverage-bar" style="--progress: <?= $authCoverage ?>%;"></div>
                <span class="sub">Authenticated proxies in pool</span>
            </div>
            <div class="telemetry-item">
                <span class="label">Top Protocol</span>
                <span class="value" id="top-proxy-type"><?= $topProxyType ? h(strtoupper($topProxyType)) : '—' ?></span>
                <span class="sub" id="top-proxy-share">
                    <?php if ($topProxyType): ?>
                        <?= h($topProxyTypeShare . '% share • ' . $topProxyTypeCount . ' endpoints') ?>
                    <?php else: ?>
                        No data available
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="status-row">
            <span class="status">● Server Active</span>
            <span class="status orange">⚡ 40-70% Faster</span>
            <span class="status teal">⇄ <?= h($concurrencyCap) ?>x Concurrency</span>
            <span class="status blue">🌐 50+ Gateways</span>
            <span class="status purple" id="coverage-pill">🛡️ <?= h($uniqueCoverage) ?>% Coverage</span>
            <span class="status purple" id="last-update">⏱️ <?= h(date('H:i:s')) ?></span>
        </div>
        <div class="header-metrics">
            <div class="gauge-card">
                <div class="radial-gauge" data-gauge="availability" style="--value: <?= $availabilityScore ?>;">
                    <div class="radial-inner">
                        <span id="availability-score"><?= h($availabilityScore) ?>%</span>
                        <small>Availability</small>
                    </div>
                </div>
                <p class="gauge-caption">Unique proxy coverage vs <?= h($concurrencyCap) ?> concurrency cap</p>
            </div>
            <div class="gauge-card">
                <div class="gauge-meta">
                    <div>
                        <span class="meta-label">Unique Coverage</span>
                        <span class="meta-value" id="unique-coverage-value"><?= h($uniqueCoverage) ?>%</span>
                    </div>
                    <div class="meta-pill">Operational</div>
                </div>
                <div class="insight-progress" id="unique-coverage-bar" style="--progress: <?= $uniqueCoverage ?>%;"></div>
                <div class="gauge-split">
                    <div>
                        <span class="split-label">Auth</span>
                        <span class="split-value" id="auth-split"><?= h($authCoverage) ?>%</span>
                    </div>
                    <div>
                        <span class="split-label">Open</span>
                        <span class="split-value" id="noauth-split"><?= h($noAuthCoverage) ?>%</span>
                    </div>
                </div>
            </div>
            <div class="gauge-card">
                <div class="gauge-meta">
                    <div>
                        <span class="meta-label">Top Protocol</span>
                        <span class="meta-value" id="gauge-top-protocol"><?= $topProxyType ? h(strtoupper($topProxyType)) : 'N/A' ?></span>
                    </div>
                    <div class="meta-pill secondary">Signal</div>
                </div>
                <div class="insight-progress" id="top-protocol-bar" style="--progress: <?= $topProxyTypeShare ?>%;"></div>
                <p class="gauge-caption" id="top-protocol-caption">
                    <?php if ($topProxyType): ?>
                        <?= h($topProxyTypeShare . '% of inventory • ' . $topProxyTypeCount . ' endpoints') ?>
                    <?php else: ?>
                        Upload proxy list to unlock insights
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="tabs">
        <button class="tab active" onclick="switchTab('dashboard', this)">📊 Dashboard</button>
        <button class="tab" onclick="switchTab('gateways', this)">💳 Payment Gateways</button>
        <button class="tab" onclick="switchTab('proxies', this)">🌐 Proxy Manager</button>
        <button class="tab" onclick="switchTab('tools', this)">🛠️ Tools & Tests</button>
        <button class="tab" onclick="switchTab('logs', this)">📈 Logs & Analytics</button>
        <button class="tab" onclick="switchTab('docs', this)">📚 Documentation</button>
    </div>

    <!-- Dashboard Tab -->
    <div id="dashboard-tab" class="tab-content active">
        <div class="grid">
            <!-- Stats Cards -->
            <div class="stats-card">
                <h3 id="proxy-total-stat"><?= h($dashboard['proxies']['total']) ?></h3>
                <p>Total Proxies Loaded</p>
            </div>
            <div class="stats-card" style="background: linear-gradient(135deg, var(--success), #059669);">
                <h3 id="proxy-unique-stat"><?= h($dashboard['proxies']['unique']) ?></h3>
                <p>Unique Proxy Endpoints</p>
            </div>
            <div class="stats-card" style="background: linear-gradient(135deg, var(--warning), #d97706);">
                <h3>50+</h3>
                <p>Supported Gateways</p>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>🌐 Proxy Inventory</h2>
                <p>Live snapshot of ProxyList.txt with distribution by protocol and authentication.</p>
                <div class="metric">
                    <strong>Total Entries</strong>
                    <span id="proxy-total"><?= h($dashboard['proxies']['total']) ?></span>
                </div>
                <div class="metric">
                    <strong>Unique Targets</strong>
                    <span id="proxy-unique"><?= h($dashboard['proxies']['unique']) ?></span>
                </div>
                <div class="metric">
                    <strong>With Auth</strong>
                    <span id="proxy-auth"><?= h($dashboard['proxies']['withAuth']) ?></span>
                </div>
                <div class="metric">
                    <strong>Without Auth</strong>
                    <span id="proxy-noauth"><?= h($dashboard['proxies']['withoutAuth']) ?></span>
                </div>
                <div class="metric">
                    <strong>Last Update</strong>
                    <span id="proxy-updated"><?= h($dashboard['proxies']['lastUpdatedHuman']) ?></span>
                </div>
                <div id="proxy-types" style="margin-top: 15px; font-size: 13px;">
                    <?php if (!empty($dashboard['proxies']['byType'])): ?>
                        <?php foreach ($dashboard['proxies']['byType'] as $type => $count): ?>
                            <div style="margin: 5px 0;"><strong><?= h(strtoupper($type)) ?>:</strong> <?= h($count) ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div>No proxies loaded.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h2>⚙️ System Health</h2>
                <p>Real-time system status and configuration details.</p>
                <div class="metric">
                    <strong>PHP Version</strong>
                    <span><?= h($dashboard['system']['phpVersion']) ?></span>
                </div>
                <div class="metric">
                    <strong>Concurrency Limit</strong>
                    <span><?= h($dashboard['system']['concurrencyCap']) ?>×</span>
                </div>
                <div class="metric">
                    <strong>Last Health Check</strong>
                    <span id="health-last"><?= h($dashboard['system']['lastHealthCheckHuman']) ?></span>
                </div>
                <div class="metric">
                    <strong>Server Software</strong>
                    <span style="font-size: 12px;"><?= h($dashboard['system']['serverSoftware']) ?></span>
                </div>
                <a href="health.php" class="btn btn-success" target="_blank">🩻 Full Health Report</a>
                <a href="proxy_cache_refresh.php?auto=1" class="btn btn-warning" target="_blank">♻️ Force Health Check</a>
            </div>

            <div class="card">
                <h2>🚀 Quick Actions</h2>
                <p>Common operations and frequently used tools.</p>
                <a href="fetch_proxies.php" class="btn btn-success" target="_blank">🌐 Fetch Proxies</a>
                <a href="autosh.php?cc=4111111111111111|12|2027|123&site=https://example.myshopify.com" class="btn btn-warning" target="_blank">💳 Test Gateway</a>
                <a href="proxy_example.php" class="btn" target="_blank">📖 View Examples</a>
                <a href="test_proxy_system.php" class="btn" target="_blank">🧪 Run Tests</a>
                <a href="download_proxies.php" class="btn" target="_blank">📥 Download List</a>
            </div>
        </div>
    </div>

    <!-- Payment Gateways Tab -->
    <div id="gateways-tab" class="tab-content">
        <div class="card">
            <h2>💳 Supported Payment Gateways & Platforms</h2>
            <p>Comprehensive support for 50+ payment gateways and e-commerce platforms worldwide.</p>
            
            <input type="text" id="gateway-search" class="search-box" placeholder="🔍 Search gateways..." onkeyup="filterGateways()">
            
            <div class="gateway-grid" id="gateway-grid">
                <?php foreach ($dashboard['gateways'] as $gateway): ?>
                    <div class="gateway-card" data-name="<?= h(strtolower($gateway['name'])) ?>" data-category="<?= h($gateway['category']) ?>">
                        <div class="icon"><?= h($gateway['icon']) ?></div>
                        <div class="name"><?= h($gateway['name']) ?></div>
                        <div class="category"><?= h($gateway['category']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="info-box success">
            <h3>🎯 Gateway Detection Features</h3>
            <p><strong>Automatic Detection:</strong> Intelligently identifies payment gateway from HTML, JavaScript, and network requests.</p>
            <p><strong>Multi-Gateway Support:</strong> Detects multiple gateways on the same page (e.g., WooCommerce with Stripe + PayPal).</p>
            <p><strong>Platform Recognition:</strong> Identifies e-commerce platforms (Shopify, WooCommerce, Magento, BigCommerce, etc.).</p>
            <p><strong>Rich Metadata:</strong> Returns card networks, 3DS support, features, and funding types for each gateway.</p>
            <p><strong>Confidence Scoring:</strong> Provides confidence level (0-1) for each detected gateway based on signals.</p>
        </div>

        <div class="card">
            <h2>🧪 Test Gateway Detection</h2>
            <p>Test the gateway detection system with a live URL.</p>
            <form action="autosh.php" method="get" target="_blank">
                <label for="test-site">Website URL</label>
                <input type="url" id="test-site" name="site" placeholder="https://example.myshopify.com" required>
                
                <label for="test-cc">Card Number (for testing)</label>
                <input type="text" id="test-cc" name="cc" placeholder="4111111111111111|12|2027|123" value="4111111111111111|12|2027|123">
                
                <button type="submit" class="btn btn-success" style="width: 100%;">🚀 Test Detection</button>
            </form>
        </div>
    </div>

    <!-- Proxy Manager Tab -->
    <div id="proxies-tab" class="tab-content">
        <div class="card">
            <h2>🌐 Proxy Fetcher & Tester</h2>
            <p>Automatically scrape and test proxies from 12+ sources with 200× parallel testing.</p>
            
            <form action="fetch_proxies.php" method="get" target="_blank">
                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <label>Protocols</label>
                        <select name="protocols">
                            <option value="http,https,socks4,socks5">All Protocols</option>
                            <option value="http,https">HTTP + HTTPS</option>
                            <option value="socks4,socks5">SOCKS4 + SOCKS5</option>
                            <option value="http">HTTP only</option>
                            <option value="https">HTTPS only</option>
                            <option value="socks4">SOCKS4 only</option>
                            <option value="socks5">SOCKS5 only</option>
                        </select>
                    </div>
                    <div>
                        <label>Sources</label>
                        <select name="sources">
                            <option value="builtin,github,proxyscrape">All Sources (12+)</option>
                            <option value="builtin,github">Built-in + GitHub</option>
                            <option value="builtin">Built-in Only</option>
                            <option value="github">GitHub Only</option>
                            <option value="proxyscrape">ProxyScrape Only</option>
                        </select>
                    </div>
                    <div>
                        <label>Scrape Limit</label>
                        <input type="number" name="scrapeLimit" placeholder="0 = unlimited" min="0">
                    </div>
                    <div>
                        <label>Target Working</label>
                        <input type="number" name="count" placeholder="e.g. 100" min="0">
                    </div>
                    <div>
                        <label>Timeout (seconds)</label>
                        <input type="number" name="timeout" value="3" min="1" max="30">
                    </div>
                    <div>
                        <label>Concurrency</label>
                        <input type="number" name="concurrency" value="200" min="1" max="200">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 10px;">
                    🚀 Start Fetching & Testing
                </button>
                <button type="submit" name="api" value="1" class="btn" style="width: 100%; background: #374151;">
                    📊 Run in API Mode (JSON)
                </button>
            </form>
        </div>

        <div class="info-box">
            <h3>📡 Proxy Sources (12+ Sources)</h3>
            <p><strong>ProxyScrape API:</strong> HTTP, SOCKS4, SOCKS5 proxies with geo-location.</p>
            <p><strong>GitHub Sources:</strong> TheSpeedX, clarketm, ShiftyTR, monosans, jetkai, hookzof (6 sources).</p>
            <p><strong>GeoNode API:</strong> 500+ proxies with country filtering.</p>
            <p><strong>Proxy-List.download:</strong> HTTP, HTTPS, SOCKS4, SOCKS5 proxies.</p>
            <p><strong>Free-Proxy-List.net:</strong> US, worldwide, and SOCKS proxies.</p>
            <p><strong>ProxyNova & Spys.one:</strong> Advanced proxy databases.</p>
        </div>

        <div class="card">
            <h2>📋 Proxy Sample</h2>
            <p>Preview of currently loaded proxies from ProxyList.txt.</p>
            <div class="code-block" id="proxy-sample">
                <?php if (!empty($dashboard['proxies']['sample'])): ?>
                    <?php foreach ($dashboard['proxies']['sample'] as $sample): ?>
                        <?= h($sample) ?><br>
                    <?php endforeach; ?>
                <?php else: ?>
                    # No proxies loaded. Click "Fetch Proxies" to get started.<br>
                <?php endif; ?>
            </div>
            <a href="download_proxies.php" class="btn btn-warning" target="_blank">📥 Download Full List (TXT)</a>
            <a href="download_proxies.php?format=json" class="btn" target="_blank">📄 Download JSON</a>
        </div>
    </div>

    <!-- Tools & Tests Tab -->
    <div id="tools-tab" class="tab-content">
        <div class="grid">
            <div class="card">
                <h2>🧪 Test Specific Proxy</h2>
                <p>Diagnose connection issues and find the best configuration for your proxy.</p>
                <form action="test_specific_proxy.php" method="get" target="_blank">
                    <label>Proxy Address</label>
                    <input type="text" name="proxy" placeholder="4.156.78.45:80" required>
                    <button type="submit" class="btn btn-success" style="width: 100%;">🔍 Test Proxy</button>
                </form>
            </div>

            <div class="card">
                <h2>💳 Gateway Detection Test</h2>
                <p>Test automatic payment gateway detection on any website.</p>
                <a href="test_proxy_system.php" class="btn btn-success" target="_blank">🚀 Run Gateway Tests</a>
                <a href="test_improvements.php" class="btn" target="_blank">🧪 Test New Features</a>
            </div>

            <div class="card">
                <h2>📊 System Diagnostics</h2>
                <p>Run comprehensive system health and performance tests.</p>
                <a href="health.php" class="btn btn-success" target="_blank">🩻 Health Check</a>
                <a href="proxy_example.php" class="btn" target="_blank">📖 Usage Examples</a>
                <a href="test_proxy_debug.php" class="btn" target="_blank">🐛 Debug Mode</a>
            </div>
        </div>

        <div class="card">
            <h2>🚀 Main Script (autosh.php)</h2>
            <p>Execute card testing flows with automatic gateway detection, proxy rotation, and rate limit handling.</p>
            <form action="autosh.php" method="get" target="_blank">
                <label>Card Details (format: number|month|year|cvv)</label>
                <input type="text" name="cc" placeholder="4111111111111111|12|2027|123" required>
                
                <label>Target Website</label>
                <input type="url" name="site" placeholder="https://example.myshopify.com" required>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label>Proxy Rotation (Auto-enabled)</label>
                        <select name="rotate">
                            <option value="">Auto (Default)</option>
                            <option value="0">Disable</option>
                        </select>
                    </div>
                    <div>
                        <label>Country</label>
                        <input type="text" name="country" placeholder="us" maxlength="2">
                    </div>
                    <div>
                        <label>Rate Limit Detection</label>
                        <select name="rate_limit_detection">
                            <option value="1">Enabled (Default)</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                    <div>
                        <label>Auto-Rotate on Rate Limit</label>
                        <select name="auto_rotate_rate_limit">
                            <option value="1">Enabled (Default)</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                    <div>
                        <label>Rate Limit Cooldown (seconds)</label>
                        <input type="number" name="rate_limit_cooldown" placeholder="60" min="10" max="600" value="60">
                    </div>
                    <div>
                        <label>Max Rate Limit Retries</label>
                        <input type="number" name="max_rate_limit_retries" placeholder="5" min="1" max="20" value="5">
                    </div>
                    <div>
                        <label>Response Format</label>
                        <select name="format">
                            <option value="html">HTML</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div>
                        <label>Debug Mode</label>
                        <select name="debug">
                            <option value="">Off</option>
                            <option value="1">On</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 10px;">
                    ⚙️ Execute Script
                </button>
            </form>
        </div>
        
        <div class="info-box success">
            <h3>🛡️ Advanced Proxy Features</h3>
            <p><strong>Auto-Rotation:</strong> Automatic proxy rotation enabled by default - no configuration needed!</p>
            <p><strong>All Proxy Types:</strong> HTTP, HTTPS, SOCKS4/5, Residential, Rotating, Datacenter, Mobile, ISP proxies.</p>
            <p><strong>Rate Limit Protection:</strong> Detects HTTP 429/503 and automatically switches proxies.</p>
            <p><strong>Exponential Backoff:</strong> 1s, 2s, 5s, 10s, 20s delays between retries.</p>
            <p><strong>Smart Cooldown:</strong> Rate-limited proxies temporarily skipped (60s default).</p>
            <p><strong>Rotating Proxy Support:</strong> Detects and handles rotating proxy gateways automatically.</p>
        </div>

        <div class="info-box warning">
            <h3>🧭 Using autosh.php</h3>
            <p><strong>Basic usage (auto-rotation enabled):</strong> autosh.php?cc=CARD&site=URL</p>
            <p><strong>With country filter:</strong> autosh.php?cc=CARD&site=URL&country=us</p>
            <p><strong>Disable rotation:</strong> autosh.php?cc=CARD&site=URL&rotate=0</p>
            <p><strong>Advanced tuning:</strong> autosh.php?cc=CARD&site=URL&cto=4&to=20&format=json</p>
            <p><strong>All proxy types supported:</strong> HTTP, HTTPS, SOCKS4/5, Residential, Rotating, Datacenter, Mobile, ISP</p>
        </div>
    </div>

    <!-- Logs & Analytics Tab -->
    <div id="logs-tab" class="tab-content">
        <div class="card">
            <h2>📈 Proxy Logs</h2>
            <p>Real-time proxy connection and error logs.</p>
            <div class="log-box" id="proxy-log">
                <?php if (!empty($dashboard['logs']['proxy'])): ?>
                    <?php foreach ($dashboard['logs']['proxy'] as $line): ?>
                        <?= h($line) ?><br>
                    <?php endforeach; ?>
                <?php else: ?>
                    No log entries yet.<br>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>🔄 Rotation Logs</h2>
            <p>Proxy rotation and failover event logs.</p>
            <div class="log-box" id="rotation-log">
                <?php if (!empty($dashboard['logs']['rotation'])): ?>
                    <?php foreach ($dashboard['logs']['rotation'] as $line): ?>
                        <?= h($line) ?><br>
                    <?php endforeach; ?>
                <?php else: ?>
                    No rotation events captured.<br>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-box">
            <h3>📊 Log Files</h3>
            <p><strong>proxy_log.txt:</strong> Connection attempts, successes, failures, and error messages.</p>
            <p><strong>proxy_rotation.log:</strong> Proxy rotation events, health checks, and performance metrics.</p>
            <p><strong>proxy_debug.log:</strong> Detailed debugging information when debug mode is enabled.</p>
        </div>
    </div>

    <!-- Documentation Tab -->
    <div id="docs-tab" class="tab-content">
        <div class="card">
            <h2>📚 API Endpoints</h2>
            <p>REST API endpoints for automation and integration.</p>
            <div class="code-block">
# Fetch proxies programmatically<br>
curl "<?= h($dashboard['endpoints']['fetchApi']) ?>"<br>
<br>
# Health check<br>
curl "<?= h($dashboard['endpoints']['health']) ?>"<br>
<br>
# Dashboard stats (JSON)<br>
curl "<?= h(dashboard_base_url()) ?>index.php?stats=1"<br>
<br>
# Execute autosh with parameters<br>
curl "<?= h($dashboard['endpoints']['autosh']) ?>?cc=...&site=..."
            </div>
        </div>

        <div class="card">
            <h2>✨ Key Features</h2>
            <ul class="feature-list">
                <li>50+ payment gateways and e-commerce platforms supported</li>
                <li>Automatic gateway detection with confidence scoring</li>
                <li>🔄 Auto-rotation enabled by default - zero configuration!</li>
                <li>🌐 All proxy types: HTTP, HTTPS, SOCKS4/5, Residential, Rotating, Datacenter, Mobile, ISP</li>
                <li>🛡️ Rate limiting detection and intelligent proxy rotation</li>
                <li>⚡ Exponential backoff strategy for rate limits</li>
                <li>12+ proxy sources with automatic scraping</li>
                <li>200× concurrent proxy testing for maximum speed</li>
                <li>Intelligent proxy rotation with health monitoring</li>
                <li>Real-time health checking and dead proxy removal</li>
                <li>Advanced captcha solving without external APIs</li>
                <li>Temporary proxy cooldown for rate-limited proxies</li>
                <li>Comprehensive logging and analytics</li>
                <li>JSON API for automation and integration</li>
                <li>40-70% faster response times with optimizations</li>
                <li>Support for authenticated proxies (user:pass)</li>
            </ul>
        </div>

        <div class="info-box success">
            <h3>🌐 Supported Platforms</h3>
            <p><strong>E-commerce:</strong> Shopify, WooCommerce, Magento, BigCommerce, PrestaShop, OpenCart</p>
            <p><strong>Major Gateways:</strong> Stripe, PayPal, Razorpay, PayU, Adyen, Checkout.com, Authorize.Net</p>
            <p><strong>Regional:</strong> Paytm, PhonePe, Cashfree, Instamojo, CCAvenue, BillDesk, Flutterwave, Paystack</p>
            <p><strong>BNPL:</strong> Klarna, Afterpay/Clearpay, Affirm</p>
            <p><strong>Crypto:</strong> Coinbase Commerce, BitPay</p>
            <p><strong>Others:</strong> Square, Mollie, Mercado Pago, Amazon Pay, and 20+ more</p>
        </div>

        <div class="info-box warning">
            <h3>⚙️ Server Setup</h3>
            <p><strong>Windows:</strong> Double-click START_SERVER.bat</p>
            <p><strong>Linux/macOS:</strong> Run ./start_server.sh or php -S 0.0.0.0:8000</p>
            <p><strong>Requirements:</strong> PHP 7.4+ with cURL extension enabled</p>
            <p><strong>Port:</strong> Default 8000 (configurable)</p>
        </div>

        <div class="card">
            <h2>📁 File Structure</h2>
            <p style="font-family: monospace; font-size: 13px; line-height: 1.8;">
                <strong>ProxyManager.php</strong> - Core proxy rotation engine<br>
                <strong>autosh.php</strong> - Main script with 50+ gateway support<br>
                <strong>fetch_proxies.php</strong> - Proxy scraper and tester<br>
                <strong>ProxyList.txt</strong> - Active proxy list (auto-updated)<br>
                <strong>health.php</strong> - System health monitoring<br>
                <strong>index.php</strong> - This advanced dashboard<br>
                <strong>proxy_cache_refresh.php</strong> - Scheduled health checks<br>
                <strong>test_*.php</strong> - Various testing tools<br>
                <strong>*.log, *.txt</strong> - Log files and data stores
            </p>
        </div>
    </div>
</div>

<button type="button" id="refresh-fab" class="fab" aria-label="Refresh dashboard">
    <span class="fab-icon default">⟳</span>
    <span class="fab-icon loading"><span class="loading"></span></span>
</button>

<script>
const dashboardState = <?= json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const themeToggle = document.getElementById('theme-toggle');
const themeLabel = document.getElementById('theme-toggle-label');
const refreshFab = document.getElementById('refresh-fab');
const FALLBACK_CONCURRENCY = <?= $concurrencyCap ?>;

const ENTITY_MAP = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
};

function escapeHtml(value) {
    const str = value === undefined || value === null ? '' : String(value);
    return str.replace(/[&<>"']/g, char => ENTITY_MAP[char] || char);
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value;
    }
}

function switchTab(tabName, button) {
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(btn => btn.classList.remove('active'));
    const targetTab = document.getElementById(tabName + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
    }
    if (button) {
        button.classList.add('active');
        button.blur();
    }
}

function filterGateways() {
    const input = document.getElementById('gateway-search');
    const searchTerm = input ? input.value.toLowerCase() : '';
    document.querySelectorAll('.gateway-card').forEach(card => {
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const category = (card.getAttribute('data-category') || '').toLowerCase();
        const matches = !searchTerm || name.includes(searchTerm) || category.includes(searchTerm);
        card.style.display = matches ? '' : 'none';
    });
}

function deriveInsights(data) {
    const proxies = data && data.proxies ? data.proxies : {};
    const system = data && data.system ? data.system : {};
    const total = Number(proxies.total || 0);
    const unique = Number(proxies.unique || 0);
    const withAuth = Number(proxies.withAuth || 0);
    const concurrency = Number(system.concurrencyCap || FALLBACK_CONCURRENCY) || 1;
    const uniqueCoverage = total > 0 ? Math.round((unique / total) * 100) : 0;
    const authCoverage = total > 0 ? Math.round((withAuth / total) * 100) : 0;
    const noAuthCoverage = total > 0 ? Math.max(0, 100 - authCoverage) : 0;
    const availability = unique > 0 ? Math.min(100, Math.round((unique / concurrency) * 100)) : 0;

    const byType = proxies.byType || {};
    const entries = Object.keys(byType).map(key => [key, Number(byType[key])]);
    entries.sort((a, b) => b[1] - a[1]);

    const top = entries.length > 0 ? entries[0] : null;
    const topProtocol = top ? top[0] : null;
    const topProtocolCount = top ? top[1] : 0;
    const topProtocolShare = total > 0 ? Math.round((topProtocolCount / total) * 100) : 0;

    return {
        total,
        unique,
        withAuth,
        uniqueCoverage,
        authCoverage,
        noAuthCoverage,
        availability,
        topProtocol,
        topProtocolCount,
        topProtocolShare,
        concurrency
    };
}

function updateInsights(data) {
    const insights = deriveInsights(data);
    setText('auth-coverage-value', insights.authCoverage + '%');
    const authBar = document.getElementById('auth-coverage-bar');
    if (authBar) {
        authBar.style.setProperty('--progress', insights.authCoverage + '%');
    }

    setText('unique-coverage-value', insights.uniqueCoverage + '%');
    const uniqueBar = document.getElementById('unique-coverage-bar');
    if (uniqueBar) {
        uniqueBar.style.setProperty('--progress', insights.uniqueCoverage + '%');
    }

    setText('auth-split', insights.authCoverage + '%');
    setText('noauth-split', insights.noAuthCoverage + '%');

    const radial = document.querySelector('[data-gauge="availability"]');
    if (radial) {
        radial.style.setProperty('--value', insights.availability);
    }
    setText('availability-score', insights.availability + '%');

    setText('top-proxy-type', insights.topProtocol ? insights.topProtocol.toUpperCase() : '—');
    const topShareEl = document.getElementById('top-proxy-share');
    if (topShareEl) {
        topShareEl.textContent = insights.topProtocol
            ? insights.topProtocolShare + '% share • ' + insights.topProtocolCount + ' endpoints'
            : 'No data available';
    }

    setText('gauge-top-protocol', insights.topProtocol ? insights.topProtocol.toUpperCase() : 'N/A');
    const topBar = document.getElementById('top-protocol-bar');
    if (topBar) {
        topBar.style.setProperty('--progress', insights.topProtocolShare + '%');
    }
    const topCaption = document.getElementById('top-protocol-caption');
    if (topCaption) {
        topCaption.textContent = insights.topProtocol
            ? insights.topProtocolShare + '% of inventory • ' + insights.topProtocolCount + ' endpoints'
            : 'Upload proxy list to unlock insights';
    }

    const coveragePill = document.getElementById('coverage-pill');
    if (coveragePill) {
        coveragePill.textContent = '🛡️ ' + insights.uniqueCoverage + '% Coverage';
    }
}

function updateMetrics(data) {
    const proxies = data && data.proxies ? data.proxies : {};
    const system = data && data.system ? data.system : {};

    setText('proxy-total', proxies.total || 0);
    setText('proxy-total-stat', proxies.total || 0);
    setText('proxy-unique', proxies.unique || 0);
    setText('proxy-unique-stat', proxies.unique || 0);
    setText('proxy-auth', proxies.withAuth || 0);
    setText('proxy-noauth', proxies.withoutAuth || 0);
    setText('proxy-updated', proxies.lastUpdatedHuman || 'Never');
    setText('health-last', system.lastHealthCheckHuman || 'Never');

    const typesContainer = document.getElementById('proxy-types');
    if (typesContainer) {
        typesContainer.innerHTML = '';
        const byType = proxies.byType || {};
        const sortedTypes = Object.keys(byType).sort();
        if (sortedTypes.length === 0) {
            typesContainer.textContent = 'No proxies loaded.';
        } else {
            sortedTypes.forEach(type => {
                const div = document.createElement('div');
                div.style.margin = '5px 0';
                div.innerHTML = '<strong>' + escapeHtml(type.toUpperCase()) + ':</strong> ' + escapeHtml(byType[type]);
                typesContainer.appendChild(div);
            });
        }
    }

    const sampleContainer = document.getElementById('proxy-sample');
    if (sampleContainer) {
        const sample = proxies.sample || [];
        if (sample.length === 0) {
            sampleContainer.textContent = '# No proxies loaded. Click "Fetch Proxies" to get started.';
        } else {
            sampleContainer.innerHTML = sample.map(item => escapeHtml(item)).join('<br>');
        }
    }
}

function updateTelemetry(data) {
    const generatedAt = data && data.generatedAt ? new Date(data.generatedAt) : null;
    if (generatedAt && !Number.isNaN(generatedAt.getTime())) {
        setText('telemetry-generated', generatedAt.toLocaleTimeString());
    } else {
        setText('telemetry-generated', new Date().toLocaleTimeString());
    }
}

function updateLog(elementId, lines) {
    const target = document.getElementById(elementId);
    if (!target) return;
    target.innerHTML = '';
    if (!Array.isArray(lines) || lines.length === 0) {
        target.textContent = 'No data available.';
        return;
    }
    target.innerHTML = lines.map(line => escapeHtml(line) + '<br>').join('');
}

function setFabLoading(state) {
    if (!refreshFab) return;
    if (state) {
        refreshFab.classList.add('is-loading');
        refreshFab.setAttribute('aria-busy', 'true');
        refreshFab.disabled = true;
    } else {
        refreshFab.classList.remove('is-loading');
        refreshFab.removeAttribute('aria-busy');
        refreshFab.disabled = false;
    }
}

let currentState = dashboardState;

function applyDashboardState(data) {
    currentState = data;
    updateMetrics(data);
    updateInsights(data);
    updateTelemetry(data);
    const logs = data && data.logs ? data.logs : {};
    updateLog('proxy-log', logs.proxy);
    updateLog('rotation-log', logs.rotation);
    setText('last-update', '⏱️ ' + new Date().toLocaleTimeString());
}

function refreshDashboard() {
    setFabLoading(true);
    fetch('index.php?stats=1', { cache: 'no-store' })
        .then(resp => resp.json())
        .then(data => {
            applyDashboardState(data);
        })
        .catch(() => {
            console.log('Dashboard refresh failed, will retry...');
        })
        .finally(() => {
            setTimeout(() => setFabLoading(false), 200);
        });
}

const prefersDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function setTheme(mode, persist) {
    const normalized = mode === 'dark' ? 'dark' : 'light';
    document.body.setAttribute('data-theme', normalized);
    if (themeToggle) {
        themeToggle.setAttribute('aria-pressed', normalized === 'dark' ? 'true' : 'false');
    }
    if (themeLabel) {
        themeLabel.textContent = normalized === 'dark' ? 'Dark' : 'Light';
    }
    if (persist) {
        try {
            localStorage.setItem('legend-dashboard-theme', normalized);
        } catch (err) {
            // Safari private mode can throw
        }
    }
}

let storedTheme = null;
try {
    storedTheme = localStorage.getItem('legend-dashboard-theme');
} catch (err) {
    storedTheme = null;
}

if (prefersDark) {
    const initialTheme = storedTheme || (prefersDark.matches ? 'dark' : 'light');
    setTheme(initialTheme, false);
    const handlePreferenceChange = function (event) {
        let persistedTheme = null;
        try {
            persistedTheme = localStorage.getItem('legend-dashboard-theme');
        } catch (err) {
            persistedTheme = null;
        }
        if (!persistedTheme) {
            setTheme(event.matches ? 'dark' : 'light', false);
        }
    };
    if (typeof prefersDark.addEventListener === 'function') {
        prefersDark.addEventListener('change', handlePreferenceChange);
    } else if (typeof prefersDark.addListener === 'function') {
        prefersDark.addListener(handlePreferenceChange);
    }
} else {
    setTheme(storedTheme || 'light', false);
}

if (themeToggle) {
    themeToggle.addEventListener('click', () => {
        const current = document.body.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        setTheme(next, true);
    });
}

applyDashboardState(currentState);

if (refreshFab) {
    refreshFab.addEventListener('click', () => {
        refreshDashboard();
    });
}

setInterval(refreshDashboard, 15000);

setInterval(() => {
    const lastUpdateEl = document.getElementById('last-update');
    if (lastUpdateEl) {
        lastUpdateEl.textContent = '⏱️ ' + new Date().toLocaleTimeString();
    }
}, 1000);

console.log('%c🎯 Advanced Payment & Proxy Intelligence Hub', 'font-size: 20px; font-weight: bold; color: #6366f1;');
console.log('%cPowered by @LEGEND_BL', 'font-size: 14px; color: #8b5cf6;');
console.log('%cDashboard ready. Use the bottom-right button for an instant refresh.', 'color: #0ea5e9;');
</script>
</body>
</html>
