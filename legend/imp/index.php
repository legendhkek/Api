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
    <title>Proxy & Payment Toolkit Dashboard</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #485CDE 0%, #6F3AA0 100%);
            min-height: 100vh;
            padding: 20px;
            color: #1f2933;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .header {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 18px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.25);
            position: relative;
            overflow: hidden;
        }

        .header::after {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(111, 58, 160, 0.12), rgba(72, 92, 222, 0));
            pointer-events: none;
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 18px;
        }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-nav a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: 12px;
            background: rgba(99, 102, 241, 0.12);
            color: #4338ca;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.09);
            transition: all 0.2s ease;
        }

        .header-nav a:hover {
            background: rgba(99, 102, 241, 0.22);
            box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.2);
            transform: translateY(-2px);
        }

        h1 {
            color: #3b2f74;
            margin-bottom: 10px;
            font-size: 32px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        h1 span {
            font-size: 38px;
        }

        .subtitle {
            color: #4a5568;
            font-size: 15px;
            line-height: 1.7;
            max-width: 860px;
        }

        .subtitle.meta {
            margin-top: 14px;
            font-size: 13px;
            opacity: 0.78;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background: rgba(79, 70, 229, 0.12);
            color: #4338ca;
            font-size: 12px;
            font-weight: 600;
        }

        .status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: #4caf50;
            color: white;
            border-radius: 20px;
            font-size: 13px;
            box-shadow: 0 3px 10px rgba(76, 175, 80, 0.35);
        }

        .status.orange { background: #ff9800; box-shadow: 0 3px 10px rgba(255, 152, 0, 0.35); }
        .status.teal { background: #009688; box-shadow: 0 3px 10px rgba(0, 150, 136, 0.35); }
        .status.blue { background: #3b82f6; box-shadow: 0 3px 10px rgba(59, 130, 246, 0.35); }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.25);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 46px rgba(15, 23, 42, 0.32);
        }

        .card h2 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card p {
            color: #4a5568;
            margin-bottom: 14px;
            font-size: 14px;
            line-height: 1.65;
        }

        .metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.7);
        }

        .metric strong {
            color: #2d3748;
        }

        .metric span {
            color: #4f46e5;
            font-weight: 600;
        }

        .list {
            margin-top: 12px;
            font-size: 13px;
            line-height: 1.6;
            color: #4a5568;
        }

        .list code {
            background: rgba(79, 70, 229, 0.08);
            padding: 3px 6px;
            border-radius: 6px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            background: linear-gradient(135deg, #6366f1, #4338ca);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            font-size: 14px;
            margin-right: 12px;
            margin-top: 8px;
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.25);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(67, 56, 202, 0.35);
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e, #15803d);
            box-shadow: 0 10px 26px rgba(34, 197, 94, 0.28);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #c2410c);
            box-shadow: 0 10px 26px rgba(245, 158, 11, 0.28);
        }

        .code-block {
            background: rgba(15, 23, 42, 0.85);
            color: #e0e7ff;
            padding: 16px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin-top: 10px;
            line-height: 1.6;
        }

        .info-box {
            background: rgba(238, 242, 255, 0.95);
            border-left: 4px solid #6366f1;
            padding: 18px;
            margin-top: 20px;
            border-radius: 10px;
            box-shadow: 0 12px 24px rgba(99, 102, 241, 0.15);
        }

        .info-box h3 {
            color: #3730a3;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .info-box p {
            color: #444;
            font-size: 13px;
            margin: 4px 0;
            line-height: 1.6;
        }

        .log-box {
            background: rgba(15, 23, 42, 0.92);
            color: #d6e4ff;
            padding: 16px;
            border-radius: 10px;
            font-family: 'Fira Code', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            height: 200px;
            overflow-y: auto;
            margin-top: 12px;
        }

        @media (max-width: 768px) {
            .btn {
                width: 100%;
                margin-right: 0;
            }
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
            <h1><span>🧠</span> Proxy & Payment Intelligence Hub</h1>
            <nav class="header-nav">
                <a href="#proxy-ops">Proxy Ops</a>
                <a href="#platform-intel">Platform</a>
                <a href="#payments">Payments</a>
                <a href="#woocommerce">WooCommerce</a>
                <a href="#docs">Docs</a>
            </nav>
        </div>
        <p class="subtitle">
            Unified proxy intelligence, adaptive platform fingerprinting, and multi-gateway reconnaissance (Shopify, WooCommerce + Stripe/Razorpay/PayU)
            orchestrated from a single high-velocity command center. Built for <strong>200×</strong> concurrency, tuned for stealth, ready for enterprise-grade card flows.
        </p>
        <p class="subtitle meta">
            <span class="pill"><strong>Owner:</strong> @LEGEND_BL</span>
            <span class="pill"><strong>Release:</strong> 2025.11 Gateway+ Platform Edition</span>
            <span class="pill"><strong>New:</strong> <code>?platform=shopify|woo</code> overrides &amp; WooCommerce deep sweep</span>
        </p>
        <div class="status-row">
            <span class="status">● Local Server Active</span>
            <span class="status orange">⚡ Latency-Optimised</span>
            <span class="status teal">⇄ 200x Parallel Proxy Tests</span>
            <span class="status" style="background:#7c3aed; box-shadow:0 3px 10px rgba(124,58,237,0.35);">🧭 Multi-platform Ready</span>
            <span class="status blue" id="last-generated">⏱️ Snapshot: <?= h($dashboard['generatedAt']) ?></span>
        </div>
    </div>

    <div class="grid" id="proxy-ops">
        <div class="card">
            <h2>🌐 Proxy Inventory</h2>
            <p>Live snapshot of `ProxyList.txt` with distribution by protocol and auth coverage.</p>
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
            <div class="list" id="proxy-types">
                <?php if (!empty($dashboard['proxies']['byType'])): ?>
                    <?php foreach ($dashboard['proxies']['byType'] as $type => $count): ?>
                        <div><strong><?= h(strtoupper($type)) ?>:</strong> <?= h($count) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div>No proxies loaded.</div>
                <?php endif; ?>
            </div>
            <div class="code-block" id="proxy-sample">
                <?php if (!empty($dashboard['proxies']['sample'])): ?>
                    <?php foreach ($dashboard['proxies']['sample'] as $sample): ?>
                        <?= h($sample) ?><br>
                    <?php endforeach; ?>
                <?php else: ?>
                    # Add proxies via fetcher or manual upload<br>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>🚀 Orchestration Controls</h2>
            <p>Kick off high-speed proxy acquisition or run API-mode automation.</p>
            <a href="fetch_proxies.php" class="btn btn-success" target="_blank">🚀 Fetch &amp; Test Proxies</a>
            <a href="fetch_proxies.php?api=1" class="btn" target="_blank">📊 API Mode (JSON)</a>
            <a href="download_proxies.php" class="btn btn-warning" target="_blank">📥 Download Proxy List</a>
            <a href="download_proxies.php?format=json" class="btn" target="_blank">📄 Download JSON</a>
            <div style="margin-top:15px; padding:14px; background:#f8f9ff; border-radius:12px; border:1px solid #e0e3ff;">
                <h3 style="font-size:15px; color:#334; margin-bottom:10px;">🎛️ Quick Launch</h3>
                <form action="fetch_proxies.php" method="get" target="_blank" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px; align-items:end;">
                    <div>
                        <label style="font-size:12px;color:#666;">Protocols</label>
                        <select name="protocols" style="width:100%; padding:8px; border-radius:6px; border:1px solid #ccc;">
                            <option value="http,https,socks4,socks5">All (HTTP ⇄ SOCKS)</option>
                            <option value="http,https">HTTP + HTTPS</option>
                            <option value="socks4,socks5">SOCKS4 + SOCKS5</option>
                            <option value="http">HTTP only</option>
                            <option value="https">HTTPS only</option>
                            <option value="socks4">SOCKS4 only</option>
                            <option value="socks5">SOCKS5 only</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;color:#666;">Sources</label>
                        <select name="sources" style="width:100%; padding:8px; border-radius:6px; border:1px solid #ccc;">
                            <option value="builtin,github,proxyscrape">All Sources</option>
                            <option value="builtin,github">Built-in + GitHub</option>
                            <option value="builtin">Built-in Only</option>
                            <option value="github">GitHub Only</option>
                            <option value="proxyscrape">ProxyScrape Only</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;color:#666;">Scrape Limit</label>
                        <input type="number" min="0" name="scrapeLimit" placeholder="0 = unlimited" style="width:100%; padding:8px; border-radius:6px; border:1px solid #ccc;" />
                    </div>
                    <div>
                        <label style="font-size:12px;color:#666;">Target Working</label>
                        <input type="number" min="0" name="count" placeholder="e.g. 80" style="width:100%; padding:8px; border-radius:6px; border:1px solid #ccc;" />
                    </div>
                    <div>
                        <label style="font-size:12px;color:#666;">Timeout (s)</label>
                        <input type="number" min="1" name="timeout" value="3" style="width:100%; padding:8px; border-radius:6px; border:1px solid #ccc;" />
                    </div>
                    <div>
                        <label style="font-size:12px;color:#666;">Concurrency</label>
                        <input type="number" min="1" max="200" name="concurrency" value="<?= h(DASHBOARD_CONCURRENCY_LIMIT) ?>" style="width:100%; padding:8px; border-radius:6px; border:1px solid #ccc;" />
                    </div>
                    <div>
                        <button type="submit" class="btn btn-success" style="width:100%;">Start Scraping</button>
                    </div>
                    <div>
                        <button type="submit" class="btn" style="width:100%; background:#374151;" name="api" value="1">Run in API Mode</button>
                    </div>
                </form>
                <p style="font-size:12px;color:#666;margin-top:10px;">
                    Tip: use <code>concurrency=<?= h(DASHBOARD_CONCURRENCY_LIMIT) ?></code> to leverage the upgraded multi-thread runner.
                </p>
            </div>
        </div>

        <div class="card" id="payments">
            <h2>💳 Payment Gateway Recon</h2>
            <p>Autonomous gateway fingerprinting &amp; card orchestration with on-the-fly captcha mitigation across Shopify, WooCommerce, Magento and more.</p>
            <a href="test_proxy_system.php" class="btn btn-success" target="_blank">📡 Run Gateway Suite</a>
            <a href="autosh.php?site=https://example.myshopify.com&amp;cc=4111111111111111|12|2027|123" class="btn btn-warning" target="_blank">⚙️ Execute autosh</a>
            <div class="code-block">
curl https://localhost/autosh.php?site=https://woo.example/<br>
  &amp;cc=4111111111111111|12|2028|123<br>
  &amp;platform=woo&amp;product=176&amp;format=json
            </div>
            <div class="info-box" style="background:#fef3c7;border-left-color:#f59e0b;">
                <h3>Gateway Coverage Highlights</h3>
                <p>Stripe, PayPal/Braintree, Adyen, Checkout.com, Worldpay, Authorize.Net, PayU, Razorpay, Square, Klarna, Afterpay/Clearpay, Affirm, Cybersource, MercadoPago, Amazon Pay, PhonePe, Paytm &amp; more.</p>
                <p><strong>WooCommerce sweep:</strong> surfaces <code>payment_methods</code>, Stripe publishable keys, Razorpay key IDs and PayU merchant keys without browser automation.</p>
                <p>Instant JSON intelligence: risk hints, card-flow support, 3DS requirement flags, and proxy provenance.</p>
            </div>
        </div>

        <div class="card" id="platform-intel">
            <h2>🧭 Platform Fingerprint</h2>
            <p>Auto-classify Shopify, WooCommerce, Magento, and BigCommerce before the checkout runbook executes. Responses now expose a <code>platform</code> section with confidence, signals, and detected URLs.</p>
            <div class="code-block">
autosh.php?site=https://store.example/&amp;cc=4111111111111111|12|2028|123<br>
autosh.php?...&amp;platform=shopify&amp;format=json
            </div>
            <div class="info-box" style="background:#eef2ff;border-left-color:#6366f1;">
                <h3>Quick Notes</h3>
                <p><strong>Auto mode:</strong> Detection runs before captcha or proposal steps &mdash; nothing extra to configure.</p>
                <p><strong>Override:</strong> Use <code>?platform=woo</code> or <code>?platform=shopify</code> to force a specific strategy.</p>
                <p><strong>Inspect:</strong> Review <code>platform.signals</code> + <code>platform.notes</code> for confidence hints and raw headers.</p>
            </div>
        </div>

        <div class="card" id="woocommerce">
            <h2>🛍️ WooCommerce Orchestrator</h2>
            <p>First-class WooCommerce analysis with automatic product discovery, cart priming, and Stripe/Razorpay/PayU token harvesting.</p>
            <div class="code-block">
autosh.php?site=https://woo.example/&amp;cc=4111111111111111|12|2028|123<br>
  &amp;platform=woo&amp;product=beanie-sku&amp;format=json
            </div>
            <div class="info-box" style="background:#ecfdf5;border-left-color:#10b981;">
                <h3>Highlights</h3>
                <p>✔ Auto product discovery via Store API (fallback to <code>?product=slug</code> or <code>?product=ID</code>).</p>
                <p>✔ JSON output includes <code>payment.payment_methods</code>, Stripe publishable keys, Razorpay key IDs, and PayU merchant keys.</p>
                <p>✔ Shares proxy/session jar with Shopify flow so rotation &amp; captcha bypass stay seamless.</p>
            </div>
        </div>

        <div class="card">
            <h2>📈 Telemetry Stream</h2>
            <p>Live rotation and execution logs for rapid debugging.</p>
            <h3 style="font-size:13px; color:#cbd5f5; text-transform:uppercase; letter-spacing: 1px; margin-bottom:6px;">proxy_log.txt</h3>
            <div class="log-box" id="proxy-log">
                <?php if (!empty($dashboard['logs']['proxy'])): ?>
                    <?php foreach ($dashboard['logs']['proxy'] as $line): ?>
                        <?= h($line) ?><br>
                    <?php endforeach; ?>
                <?php else: ?>
                    No log entries yet.<br>
                <?php endif; ?>
            </div>
            <h3 style="font-size:13px; color:#cbd5f5; text-transform:uppercase; letter-spacing: 1px; margin:12px 0 6px;">proxy_rotation.log</h3>
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

        <div class="card">
            <h2>🩺 Health &amp; Diagnostics</h2>
            <p>Verify runtime readiness before high-volume operations.</p>
            <a href="health.php" class="btn" target="_blank">🩻 Health Report</a>
            <a href="proxy_cache_refresh.php?auto=1" class="btn btn-warning" target="_blank">♻️ Force Health Check</a>
            <a href="test_improvements.php" class="btn btn-success" target="_blank">🧪 Test New Features</a>
            <div class="metric">
                <strong>Last Health Sweep</strong>
                <span id="health-last"><?= h($dashboard['system']['lastHealthCheckHuman']) ?></span>
            </div>
            <div class="metric">
                <strong>PHP Runtime</strong>
                <span><?= h($dashboard['system']['phpVersion']) ?></span>
            </div>
            <div class="metric">
                <strong>Worker Concurrency</strong>
                <span>≤ <?= h($dashboard['system']['concurrencyCap']) ?></span>
            </div>
            <div class="code-block">
php -S 0.0.0.0:8000 -t imp<br>
start_server.sh # Linux/macOS<br>
START_SERVER.bat # Windows
            </div>
        </div>
    </div>

    <div class="info-box" style="background:#e8f5e9;border-left-color:#4caf50;">
        <h3>🌐 Proxy Fetcher Features</h3>
        <p><strong>Real-time UI:</strong> Watch scrape + test across 200 concurrent workers.</p>
        <p><strong>Smart Testing:</strong> Adaptive timeouts + protocol auto-detection.</p>
        <p><strong>Analytics:</strong> JSON stats for automation flows (<code>?api=1</code>).</p>
        <p><strong>Auto-curation:</strong> Only functional proxies persisted to `ProxyList.txt`.</p>
        <p><strong>Download API:</strong> Get proxies in TXT/JSON format with filters (<code>download_proxies.php</code>).</p>
        <p><strong>Auto-Fetch:</strong> Automatic proxy fetching when list is empty or stale.</p>
    </div>

    <div class="info-box" style="background:#dbeafe;border-left-color:#3b82f6;">
        <h3>🧭 Platform &amp; Gateway Updates</h3>
        <p><strong>Detector:</strong> Responses now include <code>platform.id</code>, <code>confidence</code>, <code>signals</code>, and <code>notes</code> for Shopify, WooCommerce, Magento, and BigCommerce.</p>
        <p><strong>Overrides:</strong> Use <code>?platform=woo</code> or <code>?platform=shopify</code> to force a runbook &mdash; handy for bespoke storefronts.</p>
        <p><strong>JSON tips:</strong> Inspect <code>gateway.candidates</code> plus <code>payment.payment_methods</code> for 3DS hints, publishable keys, and funding lanes.</p>
    </div>

    <div class="info-box" style="background:#fff7ed;border-left-color:#fb923c;">
        <h3>🧭 Using autosh.php</h3>
        <p><strong>Direct hit:</strong></p>
        <div class="code-block">
autosh.php?cc=4111111111111111|12|2028|123<br>
  &amp;site=https://example.myshopify.com&amp;platform=shopify
        </div>
        <p><strong>Rotating proxy + country pinning:</strong></p>
        <div class="code-block">
autosh.php?...&amp;rotate=1&amp;country=us&amp;requireProxy=1
        </div>
        <p><strong>Advanced tuning:</strong></p>
        <div class="code-block">
autosh.php?...&amp;platform=woo&amp;product=176&amp;cto=4&amp;to=20&amp;v4=1&amp;format=json
        </div>
    </div>

    <div class="card" id="docs" style="margin-top:24px;">
        <h2>📁 Toolkit Files</h2>
        <p style="color:#4a5568; font-size:13px;">
            <strong>ProxyManager.php</strong> &ndash; Rotation engine with health tracking<br>
            <strong>fetch_proxies.php</strong> &ndash; High-speed scraping + validation<br>
            <strong>autosh.php</strong> &ndash; Shopify/WooCommerce multi-gateway executor<br>
            <strong>WooCommerceCheckout.php</strong> &ndash; Stripe, Razorpay, PayU automation helpers<br>
            <strong>proxy_cache_refresh.php</strong> &ndash; Scheduled pruning<br>
            <strong>test_proxy_system.php</strong> &ndash; End-to-end diagnostics<br>
            <strong>SETUP_GUIDE.txt</strong> &ndash; Fast start companion
        </p>
    </div>
</div>
<script>
const dashboardState = <?= json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function renderLatestLogs(elementId, lines) {
    const target = document.getElementById(elementId);
    if (!target) return;
    target.innerHTML = '';
    if (!lines || !lines.length) {
        target.textContent = 'No data available.';
        return;
    }
    lines.forEach(line => {
        const div = document.createElement('div');
        div.textContent = line;
        target.appendChild(div);
    });
}

function refreshDashboard() {
    fetch('index.php?stats=1', {cache: 'no-store'}).then(resp => resp.json()).then(data => {
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = value;
            }
        };

        setText('proxy-total', data.proxies.total);
        setText('proxy-unique', data.proxies.unique);
        setText('proxy-auth', data.proxies.withAuth);
        setText('proxy-noauth', data.proxies.withoutAuth);
        setText('proxy-updated', data.proxies.lastUpdatedHuman);
        setText('health-last', data.system.lastHealthCheckHuman);
        setText('last-generated', '⏱️ Snapshot: ' + data.generatedAt);

        const typesContainer = document.getElementById('proxy-types');
        if (typesContainer) {
            typesContainer.innerHTML = '';
            const byType = data.proxies.byType || {};
            const keys = Object.keys(byType);
            if (!keys.length) {
                typesContainer.textContent = 'No proxies loaded.';
            } else {
                keys.sort().forEach(key => {
                    const row = document.createElement('div');
                    const strong = document.createElement('strong');
                    strong.textContent = key.toUpperCase() + ':';
                    row.appendChild(strong);
                    row.appendChild(document.createTextNode(' ' + byType[key]));
                    typesContainer.appendChild(row);
                });
            }
        }

        const sampleContainer = document.getElementById('proxy-sample');
        if (sampleContainer) {
            sampleContainer.innerHTML = '';
            if (!data.proxies.sample || !data.proxies.sample.length) {
                sampleContainer.textContent = '# Add proxies via fetcher or manual upload';
            } else {
                data.proxies.sample.forEach(item => {
                    const line = document.createElement('div');
                    line.textContent = item;
                    sampleContainer.appendChild(line);
                });
            }
        }

        renderLatestLogs('proxy-log', data.logs.proxy);
        renderLatestLogs('rotation-log', data.logs.rotation);
    }).catch(() => {
        // Silent; dashboard remains with last-known good data.
    });
}

setInterval(refreshDashboard, 15000);
</script>
</body>
</html>
