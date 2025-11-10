<?php
declare(strict_types=1);

$scriptStart = microtime(true);

require_once __DIR__ . '/ProxyManager.php';

const LEGEND_MAX_CONCURRENCY = 200;
const LEGEND_DEFAULT_CONCURRENCY = 40;
const LEGEND_HEALTH_INTERVAL = 3600; // seconds

/**
 * Safely convert to HTML.
 */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Human friendly number formatting.
 */
function nf(int $value): string
{
    return number_format($value);
}

/**
 * Read proxy inventory from file.
 *
 * @return array<int, array{raw:string,type:string}>
 */
function readProxyInventory(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $inventory = [];
    foreach ($lines as $line) {
        $raw = trim($line);
        if ($raw === '' || $raw[0] === '#') {
            continue;
        }
        $type = 'http';
        if (preg_match('/^(https?|socks4|socks5):\/\//i', $raw, $matches)) {
            $type = strtolower($matches[1]);
        }
        $inventory[] = [
            'raw' => $raw,
            'type' => $type,
        ];
    }

    return $inventory;
}

/**
 * Group proxies by scheme.
 *
 * @param array<int, array{raw:string,type:string}> $inventory
 * @return array<string,int>
 */
function groupProxiesByType(array $inventory): array
{
    $counts = [
        'http' => 0,
        'https' => 0,
        'socks4' => 0,
        'socks5' => 0,
    ];

    foreach ($inventory as $entry) {
        $type = $entry['type'];
        if (!isset($counts[$type])) {
            $counts[$type] = 0;
        }
        $counts[$type]++;
    }

    return $counts;
}

/**
 * Return tail lines from file efficiently.
 *
 * @return string[]
 */
function readTail(string $path, int $lines = 20): array
{
    if ($lines <= 0 || !is_file($path)) {
        return [];
    }

    $file = new SplFileObject($path, 'r');
    $file->seek(PHP_INT_MAX);
    $lastLine = $file->key();
    $start = max(0, $lastLine - ($lines - 1));
    $buffer = [];

    for ($line = $start; $line <= $lastLine; $line++) {
        $file->seek($line);
        $current = rtrim((string)$file->current());
        if ($current !== '') {
            $buffer[] = $current;
        }
    }

    return $buffer;
}

/**
 * Human readable relative time.
 */
function humanTimeDiff(?int $timestamp): string
{
    if (!$timestamp) {
        return 'never';
    }

    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = 0;
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
 * Build gateway catalog metadata for UI + JSON.
 *
 * @return array<int, array<string,mixed>>
 */
function buildGatewayCatalog(): array
{
    return [
        [
            'name' => 'Stripe',
            'regions' => 'Global',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover', 'JCB', 'UnionPay'],
            'extras' => ['Apple Pay', 'Google Pay', 'ACH Debit', 'Klarna', 'Afterpay'],
            'keywords' => ['stripe', 'checkout.stripe.com', 'pk_live_', 'sk_live_', 'stripe.js'],
        ],
        [
            'name' => 'PayPal / Braintree',
            'regions' => 'Global',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover', 'Maestro'],
            'extras' => ['PayPal Balance', 'Venmo', 'PayPal Credit', 'PayPal Pay Later'],
            'keywords' => ['paypal.com', 'braintreepayments.com', 'paypalobjects.com', 'merchantId'],
        ],
        [
            'name' => 'Adyen',
            'regions' => 'Global / Enterprise',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Diners', 'JCB'],
            'extras' => ['SEPA', 'iDEAL', 'Klarna', 'Apple Pay', 'Google Pay'],
            'keywords' => ['adyen.com', 'checkoutshopper-live', 'adyencheckout'],
        ],
        [
            'name' => 'Checkout.com',
            'regions' => 'Global',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Diners', 'Maestro'],
            'extras' => ['Apple Pay', 'Google Pay', 'Klarna', 'Sofort'],
            'keywords' => ['checkout.com', 'cko_', 'cko-transaction-id', 'checkoutjs'],
        ],
        [
            'name' => 'Authorize.Net',
            'regions' => 'North America',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
            'extras' => ['ACH eCheck', 'Customer Profiles'],
            'keywords' => ['authorize.net', 'accept.authorize.net', 'anet_', 'aimTxnId'],
        ],
        [
            'name' => 'Razorpay',
            'regions' => 'India / APAC',
            'cards' => ['Visa', 'Mastercard', 'RuPay', 'Amex'],
            'extras' => ['UPI', 'NetBanking', 'Wallets', 'EMI'],
            'keywords' => ['razorpay.com', 'rzp_', 'razorpay-checkout'],
        ],
        [
            'name' => 'Paystack',
            'regions' => 'Africa',
            'cards' => ['Visa', 'Mastercard', 'Verve'],
            'extras' => ['Bank Transfer', 'USSD', 'Mobile Money'],
            'keywords' => ['paystack.com', 'ps_checkout', 'paystack-inline'],
        ],
        [
            'name' => 'Flutterwave',
            'regions' => 'Africa / Global',
            'cards' => ['Visa', 'Mastercard', 'Verve'],
            'extras' => ['Mobile Money', 'Bank Transfer', 'Barter'],
            'keywords' => ['flutterwave.com', 'flw_', 'ravepay'],
        ],
        [
            'name' => 'Worldpay / FIS',
            'regions' => 'Global',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'JCB'],
            'extras' => ['ACH', 'Alternative Payments'],
            'keywords' => ['worldpay.com', 'secureacceptance', 'vantiv', 'wpaytoken'],
        ],
        [
            'name' => 'Square',
            'regions' => 'North America, AU, JP',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
            'extras' => ['Cash App Pay', 'Afterpay/Clearpay', 'ACH'],
            'keywords' => ['squareup.com', 'square-api', 'sq0idp', 'square-secure-payment-form'],
        ],
        [
            'name' => 'Klarna',
            'regions' => 'EU / US / AU',
            'cards' => ['Visa', 'Mastercard'],
            'extras' => ['Pay in 4', 'Pay Later', 'Financing'],
            'keywords' => ['klarna.com', 'klarnapay', 'x-klarna-client-id', 'klarna-payments'],
        ],
        [
            'name' => 'Affirm',
            'regions' => 'US / CA',
            'cards' => ['Virtual Visa'],
            'extras' => ['Pay over time', 'Virtual card'],
            'keywords' => ['affirm.com', 'affirm-checkout', 'checkout.affirm', 'public_key'],
        ],
        [
            'name' => 'Sezzle',
            'regions' => 'US / CA / AU',
            'cards' => ['Virtual card'],
            'extras' => ['Pay in 4', 'Virtual Card'],
            'keywords' => ['sezzle.com', 'sezzle-js', 'sezzle-checkout'],
        ],
        [
            'name' => 'Amazon Pay',
            'regions' => 'US / EU / JP',
            'cards' => ['Amazon stored cards'],
            'extras' => ['Amazon Balance', 'Alexa Pay'],
            'keywords' => ['pay.amazon.com', 'amazonpay', 'amazonpay-widget'],
        ],
        [
            'name' => 'BlueSnap',
            'regions' => 'Global',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
            'extras' => ['ACH', 'SEPA', 'Digital Wallets'],
            'keywords' => ['bluesnap.com', 'bluesnap-js', 'wallapi.bluesnap.com'],
        ],
        [
            'name' => 'Mollie',
            'regions' => 'EU',
            'cards' => ['Visa', 'Mastercard', 'Amex'],
            'extras' => ['iDEAL', 'Bancontact', 'SEPA', 'Klarna'],
            'keywords' => ['mollie.com', 'mollie-payments', 'profiles.mollie.com'],
        ],
        [
            'name' => 'CCAvenue',
            'regions' => 'India / Middle East',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Diners'],
            'extras' => ['NetBanking', 'Wallets', 'UPI'],
            'keywords' => ['ccavenue.com', 'citruspay', 'ccavenue-checkout'],
        ],
        [
            'name' => 'CyberSource',
            'regions' => 'Global / Enterprise',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
            'extras' => ['Tokenization', 'Payer Authentication'],
            'keywords' => ['cybersource.com', 'secureacceptance', 'ics2ws'],
        ],
        [
            'name' => 'Moneris',
            'regions' => 'Canada',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
            'extras' => ['Interact Online', 'Vault'],
            'keywords' => ['moneris.com', 'monerisgateway', 'hostedpaypage'],
        ],
        [
            'name' => 'PayU',
            'regions' => 'EU / India / LATAM',
            'cards' => ['Visa', 'Mastercard', 'Amex', 'RuPay'],
            'extras' => ['NetBanking', 'UPI', 'EMI'],
            'keywords' => ['payu', 'boltpay', 'payu.in', 'secure.payu'],
        ],
    ];
}

$proxyListPath = __DIR__ . '/ProxyList.txt';
$proxyInventory = readProxyInventory($proxyListPath);
$proxyCount = count($proxyInventory);
$uniqueProxyCount = count(array_unique(array_map(
    static fn(array $entry): string => strtolower($entry['raw']),
    $proxyInventory
)));
$proxyByType = groupProxiesByType($proxyInventory);
$proxySample = array_slice(array_map(
    static fn(array $entry): string => $entry['raw'],
    $proxyInventory
), 0, 8);
$proxyLastUpdated = is_file($proxyListPath) ? (int)filemtime($proxyListPath) : null;

$healthFile = __DIR__ . '/proxy_check_time.txt';
$lastHealthCheck = is_file($healthFile) ? (int)trim((string)file_get_contents($healthFile)) : null;
$nextHealthEta = $lastHealthCheck ? max(0, ($lastHealthCheck + LEGEND_HEALTH_INTERVAL) - time()) : null;

$proxyLogTail = readTail(__DIR__ . '/proxy_log.txt', 12);
$rotationLogTail = readTail(__DIR__ . '/proxy_rotation.log', 8);

$pm = new ProxyManager('');
$pmStats = [
    'total_proxies' => 0,
    'dead_proxies' => 0,
    'live_proxies' => 0,
    'utilization_rate' => 0,
];
if ($proxyCount > 0) {
    $pm->loadFromFile($proxyListPath);
    $stats = $pm->getStats();
    $pmStats['total_proxies'] = $stats['total_proxies'] ?? $proxyCount;
    $pmStats['dead_proxies'] = $stats['dead_proxies'] ?? 0;
    $pmStats['live_proxies'] = $stats['live_proxies'] ?? $proxyCount;
    $pmStats['utilization_rate'] = $stats['total_proxies'] > 0
        ? round(100 - (($stats['dead_proxies'] / max(1, $stats['total_proxies'])) * 100), 1)
        : 100.0;
}

$systemStats = [
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'working_dir' => __DIR__,
    'server' => php_uname('n'),
    'os' => PHP_OS_FAMILY,
];

$gatewayCatalog = buildGatewayCatalog();

$statusPayload = [
    'generated_at' => gmdate(DATE_ATOM),
    'runtime_ms' => (int)round((microtime(true) - $scriptStart) * 1000),
    'proxies' => [
        'total' => $proxyCount,
        'unique' => $uniqueProxyCount,
        'by_type' => $proxyByType,
        'sample' => $proxySample,
        'last_updated' => $proxyLastUpdated ? gmdate(DATE_ATOM, $proxyLastUpdated) : null,
        'last_updated_human' => humanTimeDiff($proxyLastUpdated),
    ],
    'health' => [
        'last_check_at' => $lastHealthCheck ? gmdate(DATE_ATOM, $lastHealthCheck) : null,
        'last_check_human' => humanTimeDiff($lastHealthCheck),
        'next_check_eta_seconds' => $nextHealthEta,
    ],
    'proxy_manager' => $pmStats,
    'logs' => [
        'proxy_log' => $proxyLogTail,
        'rotation_log' => $rotationLogTail,
    ],
    'limits' => [
        'max_concurrency' => LEGEND_MAX_CONCURRENCY,
        'default_concurrency' => LEGEND_DEFAULT_CONCURRENCY,
        'health_interval' => LEGEND_HEALTH_INTERVAL,
    ],
    'system' => $systemStats,
];

if (isset($_GET['status'])) {
    header('Content-Type: application/json');
    echo json_encode(
        [
            'status' => 'ok',
            'payload' => $statusPayload,
            'gateway_catalog' => $gatewayCatalog,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legend Automation Console</title>
    <meta name="theme-color" content="#4f46e5">
    <style>
        :root {
            color-scheme: light dark;
            --bg-gradient: linear-gradient(135deg, #4338ca 0%, #7c3aed 100%);
            --surface: rgba(255,255,255,0.9);
            --surface-dark: rgba(17,24,39,0.72);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --accent: #10b981;
            --accent-strong: #14b8a6;
            --warning: #f97316;
            --danger: #ef4444;
            --shadow: 0 20px 45px rgba(17,24,39,0.25);
            --radius-lg: 22px;
            --radius-md: 14px;
            --radius-sm: 10px;
            font-family: 'Segoe UI', 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg-gradient);
            color: var(--text-primary);
            padding: 36px 24px 60px;
            display: flex;
            justify-content: center;
        }

        .page {
            width: 100%;
            max-width: 1320px;
            backdrop-filter: blur(24px);
        }

        header.hero {
            background: rgba(255,255,255,0.95);
            border-radius: var(--radius-lg);
            padding: 36px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            align-items: flex-start;
        }

        .hero h1 {
            font-size: clamp(2rem, 4vw, 2.8rem);
            margin: 0;
            color: #312e81;
        }

        .hero p {
            margin: 8px 0 0;
            color: var(--text-secondary);
            max-width: 640px;
            line-height: 1.6;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            flex: 1 1 320px;
        }

        .metric {
            background: rgba(99,102,241,0.08);
            border-radius: var(--radius-sm);
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            border: 1px solid rgba(99,102,241,0.2);
        }

        .metric-label {
            font-size: 0.85rem;
            color: #6366f1;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .metric-value {
            font-size: 1.9rem;
            font-weight: 700;
            color: #1f2937;
        }

        .grid {
            display: grid;
            gap: 24px;
        }

        @media (min-width: 960px) {
            .grid {
                grid-template-columns: repeat(12, 1fr);
            }
            .span-8 {
                grid-column: span 8;
            }
            .span-4 {
                grid-column: span 4;
            }
            .span-6 {
                grid-column: span 6;
            }
        }

        .card {
            background: rgba(255,255,255,0.95);
            border-radius: var(--radius-md);
            padding: 28px;
            box-shadow: 0 16px 30px rgba(17,24,39,0.18);
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .card h2 {
            margin: 0;
            font-size: 1.5rem;
            color: #1f2937;
        }

        .card p {
            margin: 0;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(16,185,129,0.12);
            color: #047857;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .card-section {
            background: rgba(249,250,251,0.85);
            border-radius: var(--radius-sm);
            padding: 16px;
            border: 1px solid rgba(229,231,235,0.7);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .list, .log {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 260px;
            overflow-y: auto;
        }

        .log-item {
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.85rem;
            background: rgba(17,24,39,0.85);
            color: #f9fafb;
            padding: 12px 14px;
            border-radius: var(--radius-sm);
            line-height: 1.4;
            white-space: pre-wrap;
        }

        .list-item {
            background: rgba(255,255,255,0.9);
            border-left: 4px solid #6366f1;
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .flex {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .button-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 12px 24px rgba(99,102,241,0.35);
        }

        .button-secondary {
            background: rgba(17,24,39,0.08);
            color: #1f2937;
        }

        .button:focus-visible {
            outline: 3px solid rgba(99,102,241,0.45);
            outline-offset: 2px;
        }

        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(99,102,241,0.45);
        }

        .range-control {
            display: grid;
            gap: 10px;
        }

        .range-control input[type="range"] {
            width: 100%;
            accent-color: #6366f1;
        }

        table.catalog {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }

        .catalog th, .catalog td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid rgba(209,213,219,0.6);
        }

        .catalog th {
            text-transform: uppercase;
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            color: #6b7280;
        }

        .catalog tbody tr:hover {
            background: rgba(99,102,241,0.08);
        }

        .badge {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: rgba(79,70,229,0.12);
            color: #4338ca;
            margin-right: 6px;
        }

        footer {
            margin-top: 32px;
            text-align: center;
            color: rgba(243,244,246,0.9);
            font-size: 0.85rem;
        }

        @media (max-width: 720px) {
            body {
                padding: 24px 14px 40px;
            }
            header.hero {
                padding: 24px;
            }
            .card {
                padding: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="hero">
            <div class="hero-copy">
                <div class="tag">Legend Toolkit • Unified Proxy &amp; Gateway Automation</div>
                <h1>Command Center</h1>
                <p>
                    Monitor proxies, launch scrapers, and inspect payment gateway coverage from a single console.
                    This dashboard exposes real-time JSON telemetry via <code>index.php?status=1</code> and unlocks
                    ultra-high concurrency (<?= LEGEND_MAX_CONCURRENCY ?> parallel proxy tests) for rapid validation.
                </p>
            </div>
            <div class="hero-stats">
                <div class="metric">
                    <span class="metric-label">Working Proxies</span>
                    <span class="metric-value" data-proxy-count><?= nf($proxyCount) ?></span>
                    <span class="metric-status">Unique: <strong data-proxy-unique><?= nf($uniqueProxyCount) ?></strong></span>
                </div>
                <div class="metric">
                    <span class="metric-label">Live Utilization</span>
                    <span class="metric-value" data-proxy-health><?= h((string)$pmStats['utilization_rate']) ?>%</span>
                    <span class="metric-status">Dead: <strong data-proxy-dead><?= nf($pmStats['dead_proxies']) ?></strong></span>
                </div>
                <div class="metric">
                    <span class="metric-label">Last Health Check</span>
                    <span class="metric-value" data-health-last><?= h(humanTimeDiff($lastHealthCheck)) ?></span>
                    <span class="metric-status">Next in <strong data-health-next><?= $nextHealthEta !== null ? h($nextHealthEta . 's') : 'n/a' ?></strong></span>
                </div>
                <div class="metric">
                    <span class="metric-label">Concurrency Ceiling</span>
                    <span class="metric-value"><?= LEGEND_MAX_CONCURRENCY ?></span>
                    <span class="metric-status">Default: <?= LEGEND_DEFAULT_CONCURRENCY ?></span>
                </div>
            </div>
        </header>

        <div class="grid">
            <section class="card span-8">
                <div>
                    <h2>Proxy Operations</h2>
                    <p>Spin up the high-speed fetcher, refresh caches, or inspect proxy inventory instantly.</p>
                </div>
                <div class="card-section">
                    <form action="fetch_proxies.php" method="get" target="_blank" class="range-control" id="fetch-control">
                        <label for="concurrency-slider"><strong>Concurrency</strong> (1 - <?= LEGEND_MAX_CONCURRENCY ?>)</label>
                        <input id="concurrency-slider" type="range" min="1" max="<?= LEGEND_MAX_CONCURRENCY ?>" value="<?= LEGEND_DEFAULT_CONCURRENCY ?>" name="concurrency">
                        <div class="flex" style="align-items:center; justify-content:space-between;">
                            <input id="concurrency-input" type="number" min="1" max="<?= LEGEND_MAX_CONCURRENCY ?>" value="<?= LEGEND_DEFAULT_CONCURRENCY ?>" name="concurrency_override" style="width:96px; padding:8px 10px; border-radius:10px; border:1px solid rgba(156,163,175,0.7); font-weight:600;">
                            <div style="font-size:0.9rem; color:var(--text-secondary);">
                                Timeout <input type="number" name="timeout" value="3" min="1" style="width:64px; margin:0 6px; padding:6px 8px; border-radius:8px; border:1px solid rgba(156,163,175,0.6);">
                                Protocols
                                <select name="protocols" style="padding:6px 10px; border-radius:8px; border:1px solid rgba(156,163,175,0.6);">
                                    <option value="http,https,socks4,socks5">All</option>
                                    <option value="http,https">HTTP+HTTPS</option>
                                    <option value="socks4,socks5">SOCKS</option>
                                    <option value="http">HTTP</option>
                                    <option value="https">HTTPS</option>
                                </select>
                            </div>
                            <button type="submit" class="button button-primary">🚀 Fetch &amp; Test</button>
                        </div>
                        <div style="font-size:0.82rem; color:var(--text-secondary);">
                            Tip: Set <code>count</code> to stop after N working proxies. Leave blank to exhaust the source pool. API mode: add <code>&amp;api=1</code>.
                        </div>
                    </form>
                </div>
                <div class="flex">
                    <a class="button button-secondary" href="fetch_proxies.php?api=1&concurrency=<?= LEGEND_DEFAULT_CONCURRENCY ?>" target="_blank">📊 JSON Fetch (API)</a>
                    <a class="button button-secondary" href="proxy_cache_refresh.php?auto=1" target="_blank">🛠 Force Health Sweep</a>
                    <a class="button button-secondary" href="ProxyList.txt" download>📥 Download ProxyList.txt</a>
                    <a class="button button-secondary" href="test_proxy_system.php" target="_blank">🧪 Run System Tests</a>
                </div>
                <div class="card-section">
                    <h3 style="margin:0; font-size:1.1rem; color:#1f2937;">Inventory Snapshot</h3>
                    <div class="flex" style="gap:16px; flex-wrap:wrap;">
                        <div>
                            <div class="badge">HTTP</div>
                            <div data-type-http><?= nf($proxyByType['http'] ?? 0) ?></div>
                        </div>
                        <div>
                            <div class="badge" style="background:rgba(16,185,129,0.15); color:#047857;">HTTPS</div>
                            <div data-type-https><?= nf($proxyByType['https'] ?? 0) ?></div>
                        </div>
                        <div>
                            <div class="badge" style="background:rgba(59,130,246,0.15); color:#1d4ed8;">SOCKS4</div>
                            <div data-type-socks4><?= nf($proxyByType['socks4'] ?? 0) ?></div>
                        </div>
                        <div>
                            <div class="badge" style="background:rgba(245,158,11,0.18); color:#b45309;">SOCKS5</div>
                            <div data-type-socks5><?= nf($proxyByType['socks5'] ?? 0) ?></div>
                        </div>
                    </div>
                    <ul class="list" data-proxy-sample>
                        <?php if (empty($proxySample)): ?>
                            <li class="list-item">No proxies available yet. Start the fetcher to populate ProxyList.txt.</li>
                        <?php else: ?>
                            <?php foreach ($proxySample as $proxy): ?>
                                <li class="list-item"><?= h($proxy) ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

            <section class="card span-4">
                <div>
                    <h2>Status Feed</h2>
                    <p>Live-tail of <code>proxy_log.txt</code> and rotation metrics refreshed every 15 seconds.</p>
                </div>
                <div class="card-section">
                    <h3 style="margin:0; font-size:1rem; color:#1f2937;">Proxy Log</h3>
                    <ul class="log" data-log-proxy>
                        <?php if (empty($proxyLogTail)): ?>
                            <li class="log-item">No log entries yet.</li>
                        <?php else: ?>
                            <?php foreach ($proxyLogTail as $line): ?>
                                <li class="log-item"><?= h($line) ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-section">
                    <h3 style="margin:0; font-size:1rem; color:#1f2937;">Rotation Activity</h3>
                    <ul class="log" data-log-rotation>
                        <?php if (empty($rotationLogTail)): ?>
                            <li class="log-item">No rotation entries yet.</li>
                        <?php else: ?>
                            <?php foreach ($rotationLogTail as $line): ?>
                                <li class="log-item"><?= h($line) ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

            <section class="card span-6">
                <div>
                    <h2>Gateway Intelligence</h2>
                    <p>The payment detector now recognises 20+ processors with deep credit card coverage maps.</p>
                </div>
                <div class="card-section" style="overflow:auto; max-height:420px;">
                    <table class="catalog">
                        <thead>
                            <tr>
                                <th>Gateway</th>
                                <th>Regions</th>
                                <th>Credit Cards</th>
                                <th>Extra Rails</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gatewayCatalog as $gateway): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($gateway['name']) ?></strong><br>
                                        <span style="font-size:0.75rem; color:#6b7280;">
                                            <?= h(implode(', ', $gateway['keywords'])) ?>
                                        </span>
                                    </td>
                                    <td><?= h($gateway['regions']) ?></td>
                                    <td><?= h(implode(', ', $gateway['cards'])) ?></td>
                                    <td><?= h(implode(', ', $gateway['extras'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card span-6">
                <div>
                    <h2>System Insights</h2>
                    <p>Quick telemetry for the PHP runtime running this toolkit.</p>
                </div>
                <div class="card-section">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px;">
                        <div>
                            <div class="badge">PHP</div>
                            <div><?= h($systemStats['php_version']) ?> (<?= h($systemStats['php_sapi']) ?>)</div>
                        </div>
                        <div>
                            <div class="badge" style="background:rgba(16,185,129,0.12); color:#047857;">Memory</div>
                            <div><?= h($systemStats['memory_limit']) ?></div>
                        </div>
                        <div>
                            <div class="badge" style="background:rgba(59,130,246,0.12); color:#1d4ed8;">Execution</div>
                            <div><?= h((string)$systemStats['max_execution_time']) ?>s</div>
                        </div>
                        <div>
                            <div class="badge" style="background:rgba(245,158,11,0.15); color:#b45309;">Host</div>
                            <div><?= h($systemStats['server']) ?> / <?= h($systemStats['os']) ?></div>
                        </div>
                    </div>
                    <div style="font-family: 'SFMono-Regular', Consolas, monospace; font-size:0.85rem;">
                        Working Dir:<br>
                        <code><?= h($systemStats['working_dir']) ?></code>
                    </div>
                    <div>
                        <strong>Status API:</strong>
                        <a href="?status=1" target="_blank">index.php?status=1</a>
                        &mdash; returns JSON snapshot for automation pipelines.
                    </div>
                </div>
                <div class="flex">
                    <a class="button button-primary" href="autosh.php" target="_blank">💳 Launch autosh.php</a>
                    <a class="button button-secondary" href="autosh.php?debug=1" target="_blank">🪲 Debug Session</a>
                    <a class="button button-secondary" href="router.php" target="_blank">🌐 Router</a>
                </div>
            </section>
        </div>

        <footer>
            Runtime: <span data-runtime><?= (int)round((microtime(true) - $scriptStart) * 1000) ?></span>ms • Legend toolkit upgraded for ultra-high concurrency &amp; expanded gateway coverage.
        </footer>
    </div>
    <script>
        (function(){
            const statusUrl = 'index.php?status=1';
            const $ = (sel) => document.querySelector(sel);
            const updateText = (sel, value) => {
                const el = $(sel);
                if (el) el.textContent = value;
            };
            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const sampleContainer = document.querySelector('[data-proxy-sample]');
            const proxyLogContainer = document.querySelector('[data-log-proxy]');
            const rotationLogContainer = document.querySelector('[data-log-rotation]');

            async function fetchStatus() {
                try {
                    const res = await fetch(statusUrl, {cache: 'no-store'});
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const json = await res.json();
                    const data = json.payload || {};

                    if (data.proxies) {
                        updateText('[data-proxy-count]', new Intl.NumberFormat().format(data.proxies.total ?? 0));
                        updateText('[data-proxy-unique]', new Intl.NumberFormat().format(data.proxies.unique ?? 0));
                        if (data.proxies.by_type) {
                            updateText('[data-type-http]', new Intl.NumberFormat().format(data.proxies.by_type.http ?? 0));
                            updateText('[data-type-https]', new Intl.NumberFormat().format(data.proxies.by_type.https ?? 0));
                            updateText('[data-type-socks4]', new Intl.NumberFormat().format(data.proxies.by_type.socks4 ?? 0));
                            updateText('[data-type-socks5]', new Intl.NumberFormat().format(data.proxies.by_type.socks5 ?? 0));
                        }
                        if (Array.isArray(data.proxies.sample) && sampleContainer) {
                            if (data.proxies.sample.length === 0) {
                                sampleContainer.innerHTML = '<li class="list-item">No proxies available yet.</li>';
                            } else {
                                sampleContainer.innerHTML = data.proxies.sample
                                    .map(proxy => '<li class="list-item">' + escapeHtml(proxy) + '</li>')
                                    .join('');
                            }
                        }
                    }

                    if (data.proxy_manager) {
                        updateText('[data-proxy-health]', (data.proxy_manager.utilization_rate ?? 0) + '%');
                        updateText('[data-proxy-dead]', new Intl.NumberFormat().format(data.proxy_manager.dead_proxies ?? 0));
                    }

                    if (data.health) {
                        updateText('[data-health-last]', data.health.last_check_human ?? 'never');
                        updateText('[data-health-next]', data.health.next_check_eta_seconds !== null
                            ? data.health.next_check_eta_seconds + 's'
                            : 'n/a'
                        );
                    }

                        if (data.logs) {
                            if (proxyLogContainer) {
                                const logs = Array.isArray(data.logs.proxy_log) ? data.logs.proxy_log : [];
                                proxyLogContainer.innerHTML = logs.length
                                    ? logs.map(line => '<li class="log-item">' + escapeHtml(line) + '</li>').join('')
                                    : '<li class="log-item">No log entries yet.</li>';
                            }
                            if (rotationLogContainer) {
                                const logs = Array.isArray(data.logs.rotation_log) ? data.logs.rotation_log : [];
                                rotationLogContainer.innerHTML = logs.length
                                    ? logs.map(line => '<li class="log-item">' + escapeHtml(line) + '</li>').join('')
                                    : '<li class="log-item">No rotation entries yet.</li>';
                            }
                        }

                    updateText('[data-runtime]', data.runtime_ms ?? '—');
                } catch (err) {
                    console.error('Failed to refresh status', err);
                }
            }

            const slider = document.getElementById('concurrency-slider');
            const numberInput = document.getElementById('concurrency-input');
            if (slider && numberInput) {
                const syncFromSlider = () => {
                    numberInput.value = slider.value;
                };
                const syncFromNumber = () => {
                    const value = Math.min(<?= LEGEND_MAX_CONCURRENCY ?>, Math.max(1, Number(numberInput.value) || <?= LEGEND_DEFAULT_CONCURRENCY ?>));
                    numberInput.value = value;
                    slider.value = value;
                };
                slider.addEventListener('input', syncFromSlider);
                numberInput.addEventListener('change', syncFromNumber);
                syncFromSlider();
            }

            fetchStatus();
            setInterval(fetchStatus, 15000);
        })();
    </script>
</body>
</html>
