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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #6366f1;
            --primary-dark: #4338ca;
            --primary-light: #818cf8;
            --secondary: #8b5cf6;
            --secondary-dark: #7c3aed;
            --success: #10b981;
            --success-dark: #059669;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --info: #3b82f6;
            --info-dark: #2563eb;
            --dark: #1e293b;
            --light: #f8fafc;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --border: #e2e8f0;
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --shadow: rgba(15, 23, 42, 0.1);
            --shadow-lg: rgba(15, 23, 42, 0.2);
            --backdrop: rgba(255, 255, 255, 0.95);
            
            /* Dark mode colors */
            --dark-bg-primary: #0f172a;
            --dark-bg-secondary: #1e293b;
            --dark-bg-tertiary: #334155;
            --dark-text-primary: #f1f5f9;
            --dark-text-secondary: #cbd5e1;
            --dark-border: #334155;
            --dark-shadow: rgba(0, 0, 0, 0.3);
            --dark-backdrop: rgba(15, 23, 42, 0.95);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: var(--text-primary);
            line-height: 1.6;
            position: relative;
            overflow-x: hidden;
            transition: background 0.5s ease;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(99, 102, 241, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.15) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        body.dark-mode {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }

        body.dark-mode::before {
            background: 
                radial-gradient(circle at 20% 50%, rgba(99, 102, 241, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.08) 0%, transparent 50%);
        }

        /* Animated gradient background */
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Particles container */
        #particles-js {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 0;
            opacity: 0.3;
            pointer-events: none;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* Dark Mode Toggle */
        .theme-toggle {
            position: fixed;
            top: 30px;
            right: 30px;
            z-index: 1000;
            background: var(--backdrop);
            backdrop-filter: blur(20px);
            border: 2px solid var(--border);
            border-radius: 50px;
            padding: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 32px var(--shadow-lg);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dark-mode .theme-toggle {
            background: var(--dark-backdrop);
            border-color: var(--dark-border);
        }

        .theme-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px var(--shadow-lg);
        }

        .theme-toggle-option {
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-secondary);
        }

        .dark-mode .theme-toggle-option {
            color: var(--dark-text-secondary);
        }

        .theme-toggle-option.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        /* Glassmorphism header */
        .header {
            background: var(--backdrop);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px var(--shadow-lg);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideDown 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dark-mode .header {
            background: var(--dark-backdrop);
            border-color: var(--dark-border);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header::before {
            content: "";
            position: absolute;
            top: -50%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15), transparent 70%);
            pointer-events: none;
            animation: float 8s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(-30px, 30px) rotate(180deg); }
        }

        .header::after {
            content: "";
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.12), transparent 70%);
            pointer-events: none;
            animation: float 10s ease-in-out infinite reverse;
        }

        h1 {
            color: #3730a3;
            margin-bottom: 15px;
            font-size: 38px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            background: linear-gradient(135deg, #3730a3 0%, #6366f1 50%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradientFlow 3s ease infinite;
            background-size: 200% 200%;
        }

        .dark-mode h1 {
            background: linear-gradient(135deg, #818cf8 0%, #a78bfa 50%, #c084fc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @keyframes gradientFlow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        h1 span {
            font-size: 42px;
            animation: pulse 2s ease-in-out infinite, rotate 20s linear infinite;
            display: inline-block;
            filter: drop-shadow(0 0 10px rgba(99, 102, 241, 0.5));
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 16px;
            line-height: 1.8;
            max-width: 900px;
            margin-bottom: 10px;
            font-weight: 400;
        }

        .dark-mode .subtitle {
            color: var(--dark-text-secondary);
        }

        .owner-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 14px;
            margin-top: 15px;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
            animation: glow 2s ease-in-out infinite;
            position: relative;
            overflow: hidden;
        }

        .owner-badge::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(255, 255, 255, 0.3) 50%,
                transparent 70%
            );
            animation: shine 3s infinite;
        }

        @keyframes glow {
            0%, 100% {
                box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
            }
            50% {
                box-shadow: 0 8px 32px rgba(102, 126, 234, 0.6), 0 0 20px rgba(102, 126, 234, 0.3);
            }
        }

        @keyframes shine {
            from { transform: translateX(-100%) translateY(-100%); }
            to { transform: translateX(100%) translateY(100%); }
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
            gap: 12px;
            margin-bottom: 30px;
            background: var(--backdrop);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 16px;
            border-radius: 20px;
            box-shadow: 0 10px 30px var(--shadow);
            overflow-x: auto;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideDown 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dark-mode .tabs {
            background: var(--dark-backdrop);
            border-color: var(--dark-border);
        }

        .tabs::-webkit-scrollbar {
            height: 6px;
        }

        .tabs::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 10px;
        }

        .tabs::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        .tab {
            padding: 14px 28px;
            background: transparent;
            border: 2px solid transparent;
            border-radius: 14px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }

        .dark-mode .tab {
            color: var(--dark-text-secondary);
        }

        .tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--light);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .dark-mode .tab::before {
            background: var(--dark-bg-tertiary);
        }

        .tab:hover::before {
            opacity: 1;
        }

        .tab:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .tab.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-color: var(--primary);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            transform: translateY(-2px);
        }

        .tab.active::before {
            opacity: 0;
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
            background: var(--backdrop);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 15px 40px var(--shadow-lg);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) backwards;
        }

        .dark-mode .card {
            background: var(--dark-backdrop);
            border-color: var(--dark-border);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: height 0.3s ease;
        }

        .card:hover::before {
            height: 8px;
        }

        .card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 25px 60px var(--shadow-lg);
        }

        .card h2 {
            color: var(--text-primary);
            margin-bottom: 18px;
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .dark-mode .card h2 {
            color: var(--dark-text-primary);
        }

        .card h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .card:hover h2::after {
            width: 80px;
        }

        .card p {
            color: var(--text-secondary);
            margin-bottom: 16px;
            font-size: 14px;
            line-height: 1.7;
        }

        .dark-mode .card p {
            color: var(--dark-text-secondary);
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
            background: linear-gradient(135deg, var(--light), var(--bg-secondary));
            border: 2px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .dark-mode .gateway-card {
            background: linear-gradient(135deg, var(--dark-bg-secondary), var(--dark-bg-tertiary));
            border-color: var(--dark-border);
        }

        .gateway-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .gateway-card:hover {
            border-color: var(--primary);
            transform: translateY(-8px) scale(1.05);
            box-shadow: 0 15px 35px rgba(99, 102, 241, 0.3);
        }

        .gateway-card:hover::before {
            opacity: 0.1;
        }

        .gateway-card .icon {
            font-size: 42px;
            margin-bottom: 10px;
            display: inline-block;
            transition: transform 0.3s ease;
            position: relative;
            z-index: 1;
        }

        .gateway-card:hover .icon {
            transform: scale(1.2) rotate(5deg);
        }

        .gateway-card .name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            position: relative;
            z-index: 1;
        }

        .dark-mode .gateway-card .name {
            color: var(--dark-text-primary);
        }

        .gateway-card .category {
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-top: 6px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .dark-mode .gateway-card .category {
            color: var(--dark-text-secondary);
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
            border-radius: 20px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) backwards;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        .stats-card:hover {
            transform: translateY(-8px) scale(1.05);
            box-shadow: 0 20px 50px rgba(99, 102, 241, 0.5);
        }

        .stats-card h3 {
            font-size: 48px;
            font-weight: 900;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
            animation: countUp 1.5s ease-out;
        }

        @keyframes countUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stats-card p {
            font-size: 14px;
            opacity: 0.95;
            color: white;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        form {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: 16px;
            margin-top: 20px;
            border: 1px solid var(--border);
        }

        .dark-mode form {
            background: var(--dark-bg-secondary);
            border-color: var(--dark-border);
        }

        form label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .dark-mode form label {
            color: var(--dark-text-primary);
        }

        form input, form select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: inherit;
        }

        .dark-mode form input,
        .dark-mode form select {
            background: var(--dark-bg-tertiary);
            border-color: var(--dark-border);
            color: var(--dark-text-primary);
        }

        form input:focus, form select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        .dark-mode form input:focus,
        .dark-mode form select:focus {
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.2);
        }

        .log-box {
            background: #0f172a;
            color: #cbd5e1;
            padding: 24px;
            border-radius: 16px;
            font-family: 'Fira Code', 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.8;
            height: 280px;
            overflow-y: auto;
            margin-top: 20px;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.5);
            border: 1px solid #1e293b;
        }

        .dark-mode .log-box {
            border-color: var(--dark-border);
        }

        .log-box::-webkit-scrollbar {
            width: 8px;
        }

        .log-box::-webkit-scrollbar-track {
            background: #1e293b;
            border-radius: 4px;
        }

        .log-box::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }

        /* Chart Container */
        .chart-container {
            background: var(--bg-secondary);
            border-radius: 20px;
            padding: 24px;
            margin: 20px 0;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .dark-mode .chart-container {
            background: var(--dark-bg-secondary);
            border-color: var(--dark-border);
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
            margin-top: 20px;
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 12px;
            background: var(--border);
            border-radius: 10px;
            overflow: hidden;
            margin: 12px 0;
            position: relative;
        }

        .dark-mode .progress-bar {
            background: var(--dark-bg-tertiary);
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 10px;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.3),
                transparent
            );
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            from { transform: translateX(-100%); }
            to { transform: translateX(100%); }
        }

        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, var(--border) 25%, var(--light) 50%, var(--border) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s ease-in-out infinite;
            border-radius: 8px;
        }

        .dark-mode .skeleton {
            background: linear-gradient(90deg, var(--dark-bg-tertiary) 25%, var(--dark-bg-secondary) 50%, var(--dark-bg-tertiary) 75%);
            background-size: 200% 100%;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
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

            .theme-toggle {
                top: 20px;
                right: 20px;
                padding: 6px;
            }

            .theme-toggle-option {
                padding: 6px 12px;
                font-size: 12px;
            }

            .header {
                padding: 30px 20px;
            }

            .chart-wrapper {
                height: 250px;
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

        /* Tooltip */
        .tooltip {
            position: absolute;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            pointer-events: none;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .tooltip.show {
            opacity: 1;
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

<!-- Dark Mode Toggle -->
<div class="theme-toggle" onclick="toggleTheme()">
    <div class="theme-toggle-option" id="light-mode-btn">
        ☀️ Light
    </div>
    <div class="theme-toggle-option active" id="dark-mode-btn">
        🌙 Dark
    </div>
</div>

<div class="container">
    <div class="header">
        <h1><span>🎯</span> Advanced Payment & Proxy Intelligence Hub</h1>
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

    <!-- Navigation Tabs -->
    <div class="tabs">
        <button class="tab active" onclick="switchTab('dashboard')">📊 Dashboard</button>
        <button class="tab" onclick="switchTab('gateways')">💳 Payment Gateways</button>
        <button class="tab" onclick="switchTab('proxies')">🌐 Proxy Manager</button>
        <button class="tab" onclick="switchTab('tools')">🛠️ Tools & Tests</button>
        <button class="tab" onclick="switchTab('logs')">📈 Logs & Analytics</button>
        <button class="tab" onclick="switchTab('docs')">📚 Documentation</button>
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

        <!-- Proxy Distribution Chart -->
        <div class="chart-container">
            <h2 style="color: var(--text-primary); margin-bottom: 20px;">📊 Proxy Type Distribution</h2>
            <div class="chart-wrapper">
                <canvas id="proxyChart"></canvas>
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

<script>
const dashboardState = <?= json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// Tab switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Add active class to clicked button
    event.target.classList.add('active');
}

// Gateway search filter
function filterGateways() {
    const searchTerm = document.getElementById('gateway-search').value.toLowerCase();
    const cards = document.querySelectorAll('.gateway-card');
    
    cards.forEach(card => {
        const name = card.getAttribute('data-name');
        const category = card.getAttribute('data-category');
        
        if (name.includes(searchTerm) || category.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Refresh dashboard data
function refreshDashboard() {
    fetch('index.php?stats=1', {cache: 'no-store'})
        .then(resp => resp.json())
        .then(data => {
            // Update metrics
            const setText = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.textContent = value;
            };

            setText('proxy-total', data.proxies.total);
            setText('proxy-total-stat', data.proxies.total);
            setText('proxy-unique', data.proxies.unique);
            setText('proxy-unique-stat', data.proxies.unique);
            setText('proxy-auth', data.proxies.withAuth);
            setText('proxy-noauth', data.proxies.withoutAuth);
            setText('proxy-updated', data.proxies.lastUpdatedHuman);
            setText('health-last', data.system.lastHealthCheckHuman);
            setText('last-update', '⏱️ ' + new Date().toLocaleTimeString());

            // Update proxy types
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

            // Update proxy sample
            const sampleContainer = document.getElementById('proxy-sample');
            if (sampleContainer && data.proxies.sample) {
                sampleContainer.innerHTML = '';
                if (data.proxies.sample.length === 0) {
                    sampleContainer.textContent = '# No proxies loaded. Click "Fetch Proxies" to get started.';
                } else {
                    data.proxies.sample.forEach(item => {
                        sampleContainer.innerHTML += item + '<br>';
                    });
                }
            }

            // Update logs
            updateLog('proxy-log', data.logs.proxy);
            updateLog('rotation-log', data.logs.rotation);
        })
        .catch(() => {
            console.log('Dashboard refresh failed, will retry...');
        });
}

function updateLog(elementId, lines) {
    const target = document.getElementById(elementId);
    if (!target) return;
    
    target.innerHTML = '';
    if (!lines || lines.length === 0) {
        target.textContent = 'No data available.';
        return;
    }
    
    lines.forEach(line => {
        target.innerHTML += line + '<br>';
    });
}

// Auto-refresh every 15 seconds
setInterval(refreshDashboard, 15000);

// Initial update timestamp
setInterval(() => {
    document.getElementById('last-update').textContent = '⏱️ ' + new Date().toLocaleTimeString();
}, 1000);

console.log('%c🎯 Advanced Payment & Proxy Intelligence Hub', 'font-size: 20px; font-weight: bold; color: #6366f1;');
console.log('%cPowered by @LEGEND_BL', 'font-size: 14px; color: #8b5cf6;');
console.log('%cDashboard loaded successfully. Auto-refresh enabled.', 'color: #10b981;');

// ============================================
// DARK MODE FUNCTIONALITY
// ============================================
function toggleTheme() {
    const body = document.body;
    const lightBtn = document.getElementById('light-mode-btn');
    const darkBtn = document.getElementById('dark-mode-btn');
    
    body.classList.toggle('dark-mode');
    
    // Update button states
    if (body.classList.contains('dark-mode')) {
        darkBtn.classList.add('active');
        lightBtn.classList.remove('active');
        localStorage.setItem('theme', 'dark');
        updateChartTheme(true);
    } else {
        lightBtn.classList.add('active');
        darkBtn.classList.remove('active');
        localStorage.setItem('theme', 'light');
        updateChartTheme(false);
    }
}

// Load saved theme preference
function loadTheme() {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    const lightBtn = document.getElementById('light-mode-btn');
    const darkBtn = document.getElementById('dark-mode-btn');
    
    if (savedTheme === 'light' || (!savedTheme && !prefersDark)) {
        document.body.classList.remove('dark-mode');
        lightBtn.classList.add('active');
        darkBtn.classList.remove('active');
    } else {
        document.body.classList.add('dark-mode');
        darkBtn.classList.add('active');
        lightBtn.classList.remove('active');
    }
}

// Load theme on page load
loadTheme();

// ============================================
// CHART FUNCTIONALITY
// ============================================
let proxyChart = null;

function initChart() {
    const ctx = document.getElementById('proxyChart');
    if (!ctx) return;
    
    const isDark = document.body.classList.contains('dark-mode');
    const textColor = isDark ? '#cbd5e1' : '#64748b';
    const gridColor = isDark ? 'rgba(51, 65, 85, 0.3)' : 'rgba(226, 232, 240, 0.3)';
    
    // Get proxy data from dashboard state
    const proxyTypes = dashboardState.proxies.byType || {};
    const labels = Object.keys(proxyTypes).map(type => type.toUpperCase());
    const data = Object.values(proxyTypes);
    
    // Define gradient colors
    const colors = [
        'rgba(99, 102, 241, 0.8)',   // primary
        'rgba(139, 92, 246, 0.8)',   // secondary
        'rgba(16, 185, 129, 0.8)',   // success
        'rgba(245, 158, 11, 0.8)',   // warning
        'rgba(59, 130, 246, 0.8)',   // info
        'rgba(239, 68, 68, 0.8)',    // danger
    ];
    
    const borderColors = [
        'rgba(99, 102, 241, 1)',
        'rgba(139, 92, 246, 1)',
        'rgba(16, 185, 129, 1)',
        'rgba(245, 158, 11, 1)',
        'rgba(59, 130, 246, 1)',
        'rgba(239, 68, 68, 1)',
    ];
    
    if (proxyChart) {
        proxyChart.destroy();
    }
    
    proxyChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels.length > 0 ? labels : ['No Data'],
            datasets: [{
                label: 'Proxy Count',
                data: data.length > 0 ? data : [1],
                backgroundColor: data.length > 0 ? colors.slice(0, labels.length) : ['rgba(100, 116, 139, 0.3)'],
                borderColor: data.length > 0 ? borderColors.slice(0, labels.length) : ['rgba(100, 116, 139, 0.5)'],
                borderWidth: 3,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: textColor,
                        font: {
                            size: 13,
                            weight: '600',
                            family: 'Inter, sans-serif'
                        },
                        padding: 15,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.9)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return label + ': ' + value + ' (' + percentage + '%)';
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 1500,
                easing: 'easeInOutQuart'
            }
        }
    });
}

function updateChartTheme(isDark) {
    if (!proxyChart) return;
    
    const textColor = isDark ? '#cbd5e1' : '#64748b';
    proxyChart.options.plugins.legend.labels.color = textColor;
    proxyChart.update();
}

// Initialize chart when page loads
window.addEventListener('load', function() {
    setTimeout(initChart, 500);
});

// ============================================
// ENHANCED ANIMATIONS
// ============================================
function animateValue(element, start, end, duration) {
    if (!element) return;
    
    const range = end - start;
    const increment = range / (duration / 16);
    let current = start;
    
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
            current = end;
            clearInterval(timer);
        }
        element.textContent = Math.floor(current);
    }, 16);
}

// Animate stats on page load
window.addEventListener('load', function() {
    const totalStat = document.getElementById('proxy-total-stat');
    const uniqueStat = document.getElementById('proxy-unique-stat');
    
    if (totalStat) {
        const totalValue = parseInt(totalStat.textContent) || 0;
        totalStat.textContent = '0';
        animateValue(totalStat, 0, totalValue, 1500);
    }
    
    if (uniqueStat) {
        const uniqueValue = parseInt(uniqueStat.textContent) || 0;
        uniqueStat.textContent = '0';
        setTimeout(() => {
            animateValue(uniqueStat, 0, uniqueValue, 1500);
        }, 200);
    }
});

// ============================================
// ENHANCED TAB ANIMATIONS
// ============================================
function switchTab(tabName) {
    // Hide all tabs with fade out
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.style.opacity = '0';
        setTimeout(() => {
            tab.classList.remove('active');
        }, 200);
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab with fade in
    setTimeout(() => {
        const selectedTab = document.getElementById(tabName + '-tab');
        selectedTab.classList.add('active');
        setTimeout(() => {
            selectedTab.style.opacity = '1';
        }, 50);
    }, 250);
    
    // Add active class to clicked button
    event.target.classList.add('active');
    
    // Smooth scroll to top
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// ============================================
// SCROLL ANIMATIONS
// ============================================
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

// Observe all cards for scroll animations
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.card, .stats-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });
});

console.log('%c✨ Advanced UI Enhancements Loaded', 'color: #10b981; font-weight: bold;');
console.log('%c🎨 Dark Mode • 📊 Charts • ⚡ Animations', 'color: #8b5cf6;');
</script>
</body>
</html>
