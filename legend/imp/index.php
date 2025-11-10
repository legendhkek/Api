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
            --border: #e2e8f0;
            --border-soft: rgba(226, 232, 240, 0.6);
            --surface: rgba(255, 255, 255, 0.95);
            --surface-strong: rgba(255, 255, 255, 0.98);
            --bg-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --transition-base: 0.35s ease;
            --card-glow: rgba(99, 102, 241, 0.2);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: var(--bg-gradient);
            min-height: 100vh;
            padding: 20px;
            color: var(--dark);
            line-height: 1.6;
            position: relative;
            overflow-x: hidden;
            transition: background 0.6s ease, color var(--transition-base);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .header {
            background: var(--surface-strong);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.28);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(12px);
            display: flex;
            flex-direction: column;
            gap: 20px;
            border: 1px solid var(--border-soft);
        }

        .header::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1), transparent);
            pointer-events: none;
        }

        h1 {
            color: #3730a3;
            margin-bottom: 15px;
            font-size: 36px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        h1 span {
            font-size: 42px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .subtitle {
            color: #475569;
            font-size: 16px;
            line-height: 1.7;
            max-width: 900px;
            margin-bottom: 10px;
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

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.14);
            overflow-x: auto;
            position: sticky;
            top: 20px;
            z-index: 5;
            backdrop-filter: blur(12px);
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
            transition: all var(--transition-base);
            white-space: nowrap;
            backdrop-filter: blur(4px);
        }

        .tab:hover,
        .tab:focus {
            background: rgba(99, 102, 241, 0.08);
            border-color: var(--primary);
            color: var(--primary);
        }

        .tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 18px rgba(99, 102, 241, 0.35);
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
            box-shadow: 0 15px 40px rgba(15, 23, 42, 0.18);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border-soft);
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
            box-shadow: 0 22px 55px rgba(15, 23, 42, 0.28), 0 0 0 1px rgba(255, 255, 255, 0.2);
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

        .info-box {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-left: 4px solid var(--info);
            padding: 20px;
            margin-top: 25px;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
        }

        .info-box h3 {
            color: #1e40af;
            margin-bottom: 12px;
            font-size: 17px;
            font-weight: 700;
        }

        .info-box p {
            color: #1e293b;
            font-size: 14px;
            margin: 6px 0;
            line-height: 1.6;
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
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 14px 34px rgba(99, 102, 241, 0.32);
            position: relative;
            overflow: hidden;
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
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-top: 15px;
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

        .header-top {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .header-actions {
            margin-left: auto;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .glass-button {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            background: rgba(255, 255, 255, 0.45);
            color: #1e293b;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all var(--transition-base);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.15);
            backdrop-filter: blur(18px);
        }

        .glass-button .btn-icon {
            font-size: 16px;
        }

        .glass-button .glass-label {
            font-size: 14px;
        }

        .glass-button .glass-spinner {
            display: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid rgba(30, 41, 59, 0.2);
            border-top-color: var(--primary);
            animation: spin 1s linear infinite;
        }

        .glass-button.loading {
            pointer-events: none;
            opacity: 0.8;
            cursor: wait;
        }

        .glass-button.loading .glass-spinner {
            display: inline-block;
        }

        .glass-button:hover,
        .glass-button:focus-visible {
            transform: translateY(-2px);
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.22);
            border-color: rgba(99, 102, 241, 0.4);
        }

        .theme-toggle {
            width: 42px;
            height: 42px;
            padding: 0;
            justify-content: center;
        }

        .theme-toggle .theme-icon {
            font-size: 20px;
            transition: opacity 0.4s ease, transform 0.4s ease;
        }

        .theme-toggle .moon {
            position: absolute;
            opacity: 0;
            transform: translateY(10px) scale(0.9);
        }

        .theme-toggle .sun {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .insights-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .insight-card {
            display: flex;
            gap: 20px;
            align-items: center;
            padding: 24px;
            background: rgba(255, 255, 255, 0.92);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.2);
            border: 1px solid rgba(148, 163, 184, 0.18);
            position: relative;
            overflow: hidden;
        }

        .insight-card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.15), transparent 55%);
            opacity: 0;
            transition: opacity var(--transition-base);
        }

        .insight-card:hover::after {
            opacity: 1;
        }

        .progress-ring {
            --value: 0;
            width: 108px;
            height: 108px;
            border-radius: 50%;
            background: conic-gradient(var(--primary) calc(var(--value) * 1%), rgba(99, 102, 241, 0.12) 0);
            display: grid;
            place-items: center;
            position: relative;
        }

        .progress-ring::after {
            content: "";
            position: absolute;
            inset: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: inset 0 4px 10px rgba(15, 23, 42, 0.12);
        }

        .progress-value {
            position: relative;
            font-weight: 700;
            font-size: 22px;
            color: var(--dark);
        }

        .insight-details h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 6px;
        }

        .insight-details p {
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 10px;
        }

        .insight-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .insight-pills .pill {
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .insight-pills .pill strong {
            font-size: 13px;
        }

        .insight-gauge {
            flex: 0 0 110px;
            height: 110px;
            display: grid;
            place-items: center;
        }

        .gauge-track {
            width: 90px;
            height: 12px;
            border-radius: 999px;
            background: rgba(99, 102, 241, 0.12);
            position: relative;
            overflow: hidden;
        }

        .gauge-bar {
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--success), var(--primary));
            width: calc(var(--value, 0) * 1%);
            transition: width 0.6s ease;
        }

        .insight-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
            display: grid;
            place-items: center;
            font-size: 26px;
            color: var(--primary);
            box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.18);
        }

        .floating-panel {
            position: fixed;
            right: 28px;
            bottom: 28px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px;
            border-radius: 20px;
            background: rgba(15, 23, 42, 0.82);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(18px);
            z-index: 50;
        }

        .floating-panel button {
            border: none;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            background: transparent;
            color: rgba(248, 250, 252, 0.85);
            cursor: pointer;
            transition: all var(--transition-base);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .floating-panel button:hover,
        .floating-panel button.active {
            background: rgba(99, 102, 241, 0.18);
            color: #ffffff;
        }

        .background-aurora {
            position: fixed;
            width: 620px;
            height: 620px;
            background: radial-gradient(circle at center, rgba(99, 102, 241, 0.28), transparent 60%);
            filter: blur(70px);
            opacity: 0.8;
            z-index: 0;
            pointer-events: none;
            animation: drift 22s linear infinite;
        }

        .background-aurora--alt {
            top: auto;
            bottom: -220px;
            right: -160px;
            left: auto;
            background: radial-gradient(circle at center, rgba(139, 92, 246, 0.28), transparent 60%);
            animation-duration: 26s;
            animation-direction: reverse;
        }

        @keyframes drift {
            0% { transform: translate(-10%, -5%) scale(1); }
            50% { transform: translate(6%, 10%) scale(1.05); }
            100% { transform: translate(-10%, -5%) scale(1); }
        }

        ::-webkit-scrollbar {
            width: 10px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.05);
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(99, 102, 241, 0.45);
            border-radius: 999px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(99, 102, 241, 0.65);
        }

        body.theme-dark {
            --bg-gradient: radial-gradient(circle at top left, rgba(15, 23, 42, 0.88), rgba(76, 29, 149, 0.85) 45%, rgba(30, 58, 138, 0.9) 100%);
            --gray: #94a3b8;
            --border: rgba(148, 163, 184, 0.25);
            --border-soft: rgba(99, 102, 241, 0.22);
            --light: rgba(15, 23, 42, 0.85);
            --surface: rgba(15, 23, 42, 0.78);
            --surface-strong: rgba(15, 23, 42, 0.85);
            --dark: #f1f5f9;
            color: #e2e8f0;
        }

        body.theme-dark h1 {
            color: #c7d2fe;
        }

        body.theme-dark .subtitle {
            color: #cbd5f5;
        }

        body.theme-dark .header {
            background: rgba(15, 23, 42, 0.85);
            border-color: rgba(99, 102, 241, 0.25);
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.45);
        }

        body.theme-dark .header::before {
            background: radial-gradient(circle, rgba(99, 102, 241, 0.2), transparent 70%);
        }

        body.theme-dark .tabs {
            background: rgba(15, 23, 42, 0.82);
            box-shadow: 0 12px 34px rgba(15, 23, 42, 0.45);
        }

        body.theme-dark .tab {
            border-color: rgba(148, 163, 184, 0.2);
            color: #cbd5f5;
        }

        body.theme-dark .tab:hover,
        body.theme-dark .tab:focus {
            background: rgba(99, 102, 241, 0.18);
            color: #f8fafc;
        }

        body.theme-dark .tab.active {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.95), rgba(76, 29, 149, 0.85));
            box-shadow: 0 8px 22px rgba(99, 102, 241, 0.5);
        }

        body.theme-dark .card {
            background: rgba(15, 23, 42, 0.78);
            border-color: rgba(99, 102, 241, 0.22);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.5);
        }

        body.theme-dark .card h2,
        body.theme-dark .metric strong {
            color: #f8fafc;
        }

        body.theme-dark .card p,
        body.theme-dark .metric span,
        body.theme-dark .insight-details p {
            color: #94a3b8;
        }

        body.theme-dark .stats-card {
            box-shadow: 0 18px 45px rgba(59, 130, 246, 0.45);
        }

        body.theme-dark .log-box {
            background: rgba(2, 6, 23, 0.95);
            color: #e2e8f0;
        }

        body.theme-dark .code-block {
            background: rgba(15, 23, 42, 0.9);
            color: #e2e8f0;
        }

        body.theme-dark .gateway-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.9));
            border-color: rgba(99, 102, 241, 0.2);
            color: #e2e8f0;
        }

        body.theme-dark .gateway-card .category {
            color: #94a3b8;
        }

        body.theme-dark form {
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(99, 102, 241, 0.25);
        }

        body.theme-dark form label {
            color: #e2e8f0;
        }

        body.theme-dark form input,
        body.theme-dark form select {
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, 0.3);
        }

        body.theme-dark form input:focus,
        body.theme-dark form select:focus {
            border-color: rgba(99, 102, 241, 0.7);
        }

        body.theme-dark .insight-card {
            background: rgba(15, 23, 42, 0.78);
            border-color: rgba(99, 102, 241, 0.3);
            box-shadow: 0 22px 55px rgba(15, 23, 42, 0.55);
        }

        body.theme-dark .progress-ring::after {
            background: rgba(15, 23, 42, 0.85);
            box-shadow: inset 0 4px 12px rgba(2, 6, 23, 0.45);
        }

        body.theme-dark .progress-value,
        body.theme-dark .insight-details h3 {
            color: #f8fafc;
        }

        body.theme-dark .insight-pills .pill {
            background: rgba(99, 102, 241, 0.22);
            color: #e0e7ff;
        }

        body.theme-dark .gauge-track {
            background: rgba(99, 102, 241, 0.25);
        }

        body.theme-dark .glass-button {
            background: rgba(30, 41, 59, 0.55);
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, 0.25);
            box-shadow: 0 14px 38px rgba(2, 6, 23, 0.55);
        }

        body.theme-dark .glass-button .glass-spinner {
            border-color: rgba(148, 163, 184, 0.3);
            border-top-color: #c7d2fe;
        }

        body.theme-dark .floating-panel {
            background: rgba(15, 23, 42, 0.92);
            box-shadow: 0 26px 60px rgba(2, 6, 23, 0.65);
        }

        body.theme-dark .floating-panel button {
            color: rgba(226, 232, 240, 0.8);
        }

        body.theme-dark .floating-panel button:hover,
        body.theme-dark .floating-panel button.active {
            background: rgba(99, 102, 241, 0.25);
            color: #ffffff;
        }

        body.theme-dark .info-box {
            background: rgba(15, 23, 42, 0.75);
            color: #e2e8f0;
        }

        body.theme-dark .info-box.success {
            background: rgba(6, 78, 59, 0.35);
            border-left-color: rgba(16, 185, 129, 0.6);
        }

        body.theme-dark .info-box.warning {
            background: rgba(120, 53, 15, 0.4);
            border-left-color: rgba(245, 158, 11, 0.7);
        }

        body.theme-dark .theme-toggle .sun {
            opacity: 0;
            transform: translateY(-10px) scale(0.85);
        }

        body.theme-dark .theme-toggle .moon {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        body.theme-dark ::-webkit-scrollbar-track {
            background: rgba(2, 6, 23, 0.55);
        }

        body.theme-dark ::-webkit-scrollbar-thumb {
            background: rgba(99, 102, 241, 0.55);
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

            .tabs {
                overflow-x: scroll;
            }

            .insights-row {
                grid-template-columns: 1fr;
            }

            .insight-card {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .glass-button {
                width: auto;
            }

            .floating-panel {
                display: none;
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
<body class="theme-light">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.php').catch(function () { /* ignore */ });
    });
}
</script>
<div class="background-aurora"></div>
<div class="background-aurora background-aurora--alt"></div>
<div class="container">
    <div class="header">
        <div class="header-top">
            <h1><span>🎯</span> Advanced Payment & Proxy Intelligence Hub</h1>
            <div class="header-actions">
                <button type="button" class="glass-button" id="refresh-now" onclick="refreshDashboard(true)">
                    <span class="btn-icon">🔄</span>
                    <span class="glass-label">Sync Now</span>
                    <span class="glass-spinner" aria-hidden="true"></span>
                </button>
                <button type="button" class="glass-button theme-toggle" id="theme-toggle" aria-label="Toggle theme" onclick="toggleTheme()">
                    <span class="theme-icon sun">☀️</span>
                    <span class="theme-icon moon">🌙</span>
                </button>
            </div>
        </div>
        <p class="subtitle">
            Enterprise-grade proxy rotation system with multi-gateway payment reconnaissance. 
            Supports <strong>50+ payment gateways</strong> including Stripe, PayPal, Razorpay, PayU, WooCommerce, 
            Shopify and more. Features automated proxy scraping from <strong>12+ sources</strong>, 
            concurrent testing at <strong>200× parallelism</strong>, and real-time health monitoring.
        </p>
        <div class="owner-badge">
            👑 Powered by @LEGEND_BL
        </div>
        <div class="status-row">
            <span class="status">● Server Active</span>
            <span class="status orange">⚡ 40-70% Faster</span>
            <span class="status teal">⇄ 200x Concurrency</span>
            <span class="status blue">🌐 50+ Gateways</span>
            <span class="status purple" id="last-update">⏱️ <?= h(date('H:i:s')) ?></span>
        </div>
    </div>

    <?php
    $proxyTotalRaw = (int) $dashboard['proxies']['total'];
    $proxyTotalBase = max(1, $proxyTotalRaw);
    $uniquePercent = $proxyTotalRaw > 0 ? (int) round(($dashboard['proxies']['unique'] / $proxyTotalBase) * 100) : 0;
    $authPercent = $proxyTotalRaw > 0 ? (int) round(($dashboard['proxies']['withAuth'] / $proxyTotalBase) * 100) : 0;
    $lastUpdatedEpoch = $dashboard['proxies']['lastUpdatedEpoch'];
    $freshnessPercent = 0;
    if ($lastUpdatedEpoch) {
        $ageMinutes = max(0, (time() - $lastUpdatedEpoch) / 60);
        $freshnessPercent = (int) round(max(0, min(100, 100 - min(100, $ageMinutes * 2.5))));
    }
    $concurrencyCap = (int) $dashboard['system']['concurrencyCap'];
    $coverageRatio = DASHBOARD_CONCURRENCY_LIMIT > 0 ? min(1, $concurrencyCap / DASHBOARD_CONCURRENCY_LIMIT) : 0;
    $healthScore = (int) round(min(100, ($uniquePercent * 0.55) + ($authPercent * 0.25) + ($freshnessPercent * 0.2) + ($coverageRatio * 20)));
    $gatewayTotal = count($dashboard['gateways']);
    $protocolCount = count($dashboard['proxies']['byType']);
    $sampleCount = count($dashboard['proxies']['sample']);
    ?>
    <div class="insights-row">
        <article class="insight-card">
            <div class="progress-ring" id="insight-health-ring" style="--value: <?= $healthScore ?>">
                <span class="progress-value" id="insight-health-value"><?= $healthScore ?>%</span>
            </div>
            <div class="insight-details">
                <h3>Proxy Health Index</h3>
                <p>Uniqueness and stability derived from current inventory.</p>
                <div class="insight-pills">
                    <span class="pill">Unique <strong id="insight-unique-ratio"><?= $uniquePercent ?></strong>%</span>
                    <span class="pill">Auth <strong id="insight-auth-ratio"><?= $authPercent ?></strong>%</span>
                </div>
            </div>
        </article>
        <article class="insight-card">
            <div class="insight-gauge" aria-hidden="true">
                <div class="gauge-track">
                    <div class="gauge-bar" id="insight-freshness-bar" style="--value: <?= $freshnessPercent ?>"></div>
                </div>
            </div>
            <div class="insight-details">
                <h3>Data Freshness</h3>
                <p>Last update <strong id="insight-last-updated"><?= h($dashboard['proxies']['lastUpdatedHuman']) ?></strong></p>
                <div class="insight-pills">
                    <span class="pill">Protocols <strong id="insight-protocol-count"><?= $protocolCount ?></strong></span>
                    <span class="pill">Sample <strong id="insight-sample-count"><?= $sampleCount ?></strong></span>
                    <span class="pill">Fresh <strong id="insight-freshness-value"><?= $freshnessPercent ?></strong>%</span>
                </div>
            </div>
        </article>
        <article class="insight-card">
            <div class="insight-icon">🤖</div>
            <div class="insight-details">
                <h3>Automation Snapshot</h3>
                <p>
                    Monitoring <strong id="insight-endpoint-count"><?= count($dashboard['endpoints']) ?></strong> endpoints with
                    <strong><?= h($concurrencyCap) ?>×</strong> concurrency cap.
                </p>
                <div class="insight-pills">
                    <span class="pill">Gateways <strong><?= $gatewayTotal ?></strong></span>
                    <span class="pill">Health <strong id="insight-health-check"><?= h($dashboard['system']['lastHealthCheckHuman']) ?></strong></span>
                    <span class="pill">Server <strong><?= h($dashboard['system']['serverSoftware']) ?></strong></span>
                </div>
            </div>
        </article>
    </div>

    <!-- Navigation Tabs -->
    <div class="tabs">
        <button class="tab active" type="button" data-tab-target="dashboard" onclick="switchTab('dashboard', this)">📊 Dashboard</button>
        <button class="tab" type="button" data-tab-target="gateways" onclick="switchTab('gateways', this)">💳 Payment Gateways</button>
        <button class="tab" type="button" data-tab-target="proxies" onclick="switchTab('proxies', this)">🌐 Proxy Manager</button>
        <button class="tab" type="button" data-tab-target="tools" onclick="switchTab('tools', this)">🛠️ Tools & Tests</button>
        <button class="tab" type="button" data-tab-target="logs" onclick="switchTab('logs', this)">📈 Logs & Analytics</button>
        <button class="tab" type="button" data-tab-target="docs" onclick="switchTab('docs', this)">📚 Documentation</button>
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

<div class="floating-panel" id="quick-nav" aria-label="Quick navigation">
    <button type="button" data-quick-tab="dashboard" onclick="navigateToTab('dashboard')">📊 Dashboard</button>
    <button type="button" data-quick-tab="gateways" onclick="navigateToTab('gateways')">💳 Gateways</button>
    <button type="button" data-quick-tab="proxies" onclick="navigateToTab('proxies')">🌐 Proxies</button>
    <button type="button" data-quick-tab="tools" onclick="navigateToTab('tools')">🛠️ Tools</button>
    <button type="button" data-quick-tab="logs" onclick="navigateToTab('logs')">📈 Logs</button>
    <button type="button" data-quick-tab="docs" onclick="navigateToTab('docs')">📚 Docs</button>
</div>

<script>
const dashboardState = <?= json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const DASHBOARD_CONCURRENCY_LIMIT = <?= DASHBOARD_CONCURRENCY_LIMIT ?>;
const THEME_STORAGE_KEY = 'legend-dashboard-theme';

function setTextContent(id, value) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }
    el.textContent = value;
}

function syncQuickNav(tabName) {
    document.querySelectorAll('[data-quick-tab]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.quickTab === tabName);
    });
}

function switchTab(tabName, trigger) {
    const targetId = `${tabName}-tab`;
    const tabPanel = document.getElementById(targetId);
    if (!tabPanel) {
        return;
    }

    document.querySelectorAll('.tab-content').forEach(panel => panel.classList.remove('active'));
    tabPanel.classList.add('active');

    document.querySelectorAll('.tab').forEach(button => {
        button.classList.toggle('active', button.dataset.tabTarget === tabName);
    });

    syncQuickNav(tabName);

    if (trigger && typeof trigger.blur === 'function') {
        trigger.blur();
    }
}

function navigateToTab(tabName) {
    const tabTrigger = document.querySelector(`.tab[data-tab-target="${tabName}"]`);
    switchTab(tabName, tabTrigger);
    const panel = document.getElementById(`${tabName}-tab`);
    if (panel) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function filterGateways() {
    const searchInput = document.getElementById('gateway-search');
    const searchTerm = (searchInput ? searchInput.value : '').toLowerCase();
    document.querySelectorAll('.gateway-card').forEach(card => {
        const name = card.dataset.name || '';
        const category = card.dataset.category || '';
        const matches = !searchTerm || name.includes(searchTerm) || category.includes(searchTerm);
        card.style.display = matches ? '' : 'none';
    });
}

function setRefreshLoading(isLoading) {
    const button = document.getElementById('refresh-now');
    if (!button) {
        return;
    }
    button.classList.toggle('loading', isLoading);
    button.disabled = isLoading;
}

function computeInsightMetrics(data) {
    const total = Math.max(1, Number(data?.proxies?.total) || 0);
    const unique = Number(data?.proxies?.unique) || 0;
    const withAuth = Number(data?.proxies?.withAuth) || 0;
    const uniquePercent = total ? Math.round((unique / total) * 100) : 0;
    const authPercent = total ? Math.round((withAuth / total) * 100) : 0;
    const lastUpdatedEpoch = Number(data?.proxies?.lastUpdatedEpoch) || 0;
    let freshnessPercent = 0;
    if (lastUpdatedEpoch) {
        const ageMinutes = Math.max(0, (Date.now() / 1000 - lastUpdatedEpoch) / 60);
        freshnessPercent = Math.round(Math.max(0, Math.min(100, 100 - Math.min(100, ageMinutes * 2.5))));
    }
    const concurrencyCap = Number(data?.system?.concurrencyCap) || 0;
    const coverageRatio = DASHBOARD_CONCURRENCY_LIMIT > 0 ? Math.min(1, concurrencyCap / DASHBOARD_CONCURRENCY_LIMIT) : 0;
    const healthScore = Math.round(Math.min(100, (uniquePercent * 0.55) + (authPercent * 0.25) + (freshnessPercent * 0.2) + (coverageRatio * 20)));

    return {
        healthScore,
        uniquePercent,
        authPercent,
        freshnessPercent,
        protocolCount: Object.keys(data?.proxies?.byType || {}).length,
        sampleCount: (data?.proxies?.sample || []).length,
        lastUpdatedHuman: data?.proxies?.lastUpdatedHuman || 'Never',
        endpointCount: Object.keys(data?.endpoints || {}).length,
        healthCheckHuman: data?.system?.lastHealthCheckHuman || 'Never',
    };
}

function updateInsightsFromData(data) {
    const metrics = computeInsightMetrics(data);
    const healthRing = document.getElementById('insight-health-ring');
    if (healthRing) {
        healthRing.style.setProperty('--value', metrics.healthScore);
    }
    setTextContent('insight-health-value', `${metrics.healthScore}%`);
    setTextContent('insight-unique-ratio', metrics.uniquePercent);
    setTextContent('insight-auth-ratio', metrics.authPercent);

    const freshnessBar = document.getElementById('insight-freshness-bar');
    if (freshnessBar) {
        freshnessBar.style.setProperty('--value', metrics.freshnessPercent);
    }
    setTextContent('insight-freshness-value', metrics.freshnessPercent);
    setTextContent('insight-last-updated', metrics.lastUpdatedHuman);
    setTextContent('insight-protocol-count', metrics.protocolCount);
    setTextContent('insight-sample-count', metrics.sampleCount);
    setTextContent('insight-endpoint-count', metrics.endpointCount);
    setTextContent('insight-health-check', metrics.healthCheckHuman);
}

function refreshDashboard(manual = false) {
    if (manual) {
        setRefreshLoading(true);
    }

    return fetch('index.php?stats=1', { cache: 'no-store' })
        .then(resp => resp.json())
        .then(data => {
            const updateMetric = (id, value) => setTextContent(id, value);

            updateMetric('proxy-total', data.proxies.total);
            updateMetric('proxy-total-stat', data.proxies.total);
            updateMetric('proxy-unique', data.proxies.unique);
            updateMetric('proxy-unique-stat', data.proxies.unique);
            updateMetric('proxy-auth', data.proxies.withAuth);
            updateMetric('proxy-noauth', data.proxies.withoutAuth);
            updateMetric('proxy-updated', data.proxies.lastUpdatedHuman);
            updateMetric('health-last', data.system.lastHealthCheckHuman);
            updateMetric('last-update', '⏱️ ' + new Date().toLocaleTimeString());

            const typesContainer = document.getElementById('proxy-types');
            if (typesContainer && data.proxies.byType) {
                typesContainer.innerHTML = '';
                Object.keys(data.proxies.byType).sort().forEach(type => {
                    const div = document.createElement('div');
                    div.style.margin = '5px 0';
                    div.innerHTML = `<strong>${type.toUpperCase()}:</strong> ${data.proxies.byType[type]}`;
                    typesContainer.appendChild(div);
                });
            }

            const sampleContainer = document.getElementById('proxy-sample');
            if (sampleContainer && data.proxies.sample) {
                sampleContainer.innerHTML = '';
                if (data.proxies.sample.length === 0) {
                    sampleContainer.textContent = '# No proxies loaded. Click "Fetch Proxies" to get started.';
                } else {
                    data.proxies.sample.forEach(item => {
                        sampleContainer.innerHTML += `${item}<br>`;
                    });
                }
            }

            updateLog('proxy-log', data.logs.proxy);
            updateLog('rotation-log', data.logs.rotation);
            updateInsightsFromData(data);

            Object.assign(dashboardState, data);
        })
        .catch(() => {
            console.log('Dashboard refresh failed, will retry...');
        })
        .finally(() => {
            if (manual) {
                setRefreshLoading(false);
            }
        });
}

function updateLog(elementId, lines) {
    const target = document.getElementById(elementId);
    if (!target) {
        return;
    }

    target.innerHTML = '';
    if (!lines || lines.length === 0) {
        target.textContent = 'No data available.';
        return;
    }

    lines.forEach(line => {
        target.innerHTML += `${line}<br>`;
    });
}

function applyTheme(theme) {
    const next = theme === 'dark' ? 'dark' : 'light';
    document.body.classList.remove('theme-light', 'theme-dark');
    document.body.classList.add(`theme-${next}`);
    try {
        localStorage.setItem(THEME_STORAGE_KEY, next);
    } catch (_) {
        // Ignore storage errors
    }
}

function toggleTheme() {
    const isDark = document.body.classList.contains('theme-dark');
    applyTheme(isDark ? 'light' : 'dark');
}

function initTheme() {
    try {
        const stored = localStorage.getItem(THEME_STORAGE_KEY);
        if (stored === 'light' || stored === 'dark') {
            applyTheme(stored);
            return;
        }
    } catch (_) {
        // Ignore storage access issues
    }

    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(prefersDark ? 'dark' : 'light');
}

const updateClock = () => {
    setTextContent('last-update', '⏱️ ' + new Date().toLocaleTimeString());
};

if (window.matchMedia && typeof window.matchMedia === 'function') {
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const handleThemeChange = event => {
        try {
            const stored = localStorage.getItem(THEME_STORAGE_KEY);
            if (stored === 'light' || stored === 'dark') {
                return;
            }
        } catch (_) {
            return;
        }
        applyTheme(event.matches ? 'dark' : 'light');
    };
    if (mediaQuery.addEventListener) {
        mediaQuery.addEventListener('change', handleThemeChange);
    } else if (mediaQuery.addListener) {
        mediaQuery.addListener(handleThemeChange);
    }
}

function initDashboard() {
    initTheme();
    syncQuickNav('dashboard');
    updateInsightsFromData(dashboardState);
    updateClock();
}

initDashboard();
refreshDashboard();

setInterval(refreshDashboard, 15000);
setInterval(updateClock, 1000);

console.log('%c🎯 Advanced Payment & Proxy Intelligence Hub', 'font-size: 20px; font-weight: bold; color: #6366f1;');
console.log('%cPowered by @LEGEND_BL', 'font-size: 14px; color: #8b5cf6;');
console.log('%cDashboard loaded successfully. Auto-refresh enabled.', 'color: #10b981;');
</script>
</body>
</html>
