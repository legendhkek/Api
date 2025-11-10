<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProxyFetcher.php';

function load_operations_summary(): array
{
    $path = __DIR__ . '/graphql/operations.php';
    if (!is_file($path)) {
        return [];
    }
    $ops = include $path;
    if (!is_array($ops)) {
        return [];
    }
    $summary = [];
    foreach ($ops as $name => $query) {
        $query = (string)$query;
        $summary[] = [
            'name' => $name,
            'length' => strlen($query),
            'hash' => substr(sha1($query), 0, 12),
        ];
    }
    return $summary;
}

$fetcher = new ProxyFetcher(__DIR__ . '/ProxyList.txt');
$pool = $fetcher->readProxyFile();
$operations = load_operations_summary();
$checkoutPath = __DIR__ . '/checkout_last.html';
$checkoutHtml = is_file($checkoutPath) ? file_get_contents($checkoutPath) : null;
$checkoutPreview = $checkoutHtml !== null ? substr($checkoutHtml, 0, 4000) : null;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Proxy &amp; Checkout Diagnostics</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f172a;
            --card: #1e293b;
            --accent: #6366f1;
            --accent-dark: #4f46e5;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --success: #22c55e;
            --error: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 24px;
        }
        h1 { margin: 0 0 16px 0; font-size: 28px; }
        .grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }
        .card {
            background: var(--card);
            padding: 20px;
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.35);
        }
        .card h2 { margin-top: 0; font-size: 20px; }
        .stat {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .stat:last-child { border-bottom: none; }
        .stat-label { color: var(--muted); }
        .stat-value { font-weight: 600; }
        button, .btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        button:hover, .btn:hover { background: var(--accent-dark); }
        form { display: grid; gap: 12px; margin-top: 12px; }
        label { font-size: 13px; color: var(--muted); }
        input {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(15, 23, 42, 0.6);
            color: var(--text);
        }
        pre {
            background: rgba(15, 23, 42, 0.75);
            padding: 16px;
            border-radius: 12px;
            max-height: 320px;
            overflow: auto;
            font-size: 12px;
            color: #cbd5f5;
            white-space: pre-wrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(99, 102, 241, 0.2);
            color: #c7d2fe;
            font-size: 12px;
            margin-left: 6px;
        }
        details summary {
            cursor: pointer;
            color: var(--accent);
            margin-bottom: 12px;
            font-weight: 600;
        }
        #testerResult, #bootstrapResult {
            font-size: 13px;
            margin-top: 12px;
        }
        .status-success { color: var(--success); }
        .status-error { color: var(--error); }
    </style>
</head>
<body>
    <h1>Proxy &amp; Checkout Diagnostics</h1>
    <p style="color: var(--muted); margin-bottom: 24px;">
        Inspect your working proxy pool, Shopify GraphQL payloads, and the latest checkout response captured by <code>autosh.php</code>.
    </p>

    <div class="grid">
        <section class="card">
            <h2>Proxy Pool</h2>
            <div class="stat">
                <span class="stat-label">Total verified proxies</span>
                <span class="stat-value"><?= count($pool) ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Sample</span>
                <span class="stat-value"><?= count($pool) ? h(implode(', ', array_slice($pool, 0, 2))) . (count($pool) > 2 ? '…' : '') : 'None' ?></span>
            </div>
            <div style="margin-top: 18px; display: flex; flex-wrap: wrap; gap: 10px;">
                <button id="bootstrapBtn">Bootstrap + Persist 20</button>
                <a class="btn" href="jsonp.php?action=download">Download ProxyList.txt</a>
            </div>
            <div id="bootstrapResult"></div>
        </section>

        <section class="card">
            <h2>Proxy Tester</h2>
            <form id="testerForm">
                <div>
                    <label for="proxyInput">Proxy string (scheme://host:port[:user:pass])</label>
                    <input id="proxyInput" name="proxy" placeholder="socks5://127.0.0.1:9050">
                </div>
                <button type="submit">Run Diagnostics</button>
            </form>
            <pre id="testerResult">Awaiting input…</pre>
        </section>

        <section class="card">
            <h2>GraphQL Operations <span class="badge"><?= count($operations) ?></span></h2>
            <ul style="list-style: none; padding: 0; margin: 0;">
                <?php if (empty($operations)): ?>
                    <li style="color: var(--muted);">No operations registered. Ensure <code>graphql/operations.php</code> exists.</li>
                <?php else: ?>
                    <?php foreach ($operations as $op): ?>
                        <li style="margin-bottom: 12px;">
                            <strong><?= h($op['name']) ?></strong>
                            <span class="badge"><?= h((string)$op['length']) ?> chars</span>
                            <span class="badge">sha1 <?= h($op['hash']) ?></span>
                            <a class="btn" style="margin-left: 10px; padding: 4px 12px; font-size: 12px;" href="jsonp.php?action=operations&amp;name=<?= urlencode($op['name']) ?>&amp;format=graphql" target="_blank">View</a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </section>

        <section class="card" style="grid-column: 1 / -1;">
            <h2>Last Checkout Response</h2>
            <?php if ($checkoutPreview === null): ?>
                <p style="color: var(--muted);">No capture yet. Execute <code>autosh.php</code> to generate <code>checkout_last.html</code>.</p>
            <?php else: ?>
                <details>
                    <summary>Show sanitized HTML preview</summary>
                    <pre><?= h($checkoutPreview) ?><?= strlen((string)$checkoutHtml) > strlen((string)$checkoutPreview) ? "\n… truncated …" : '' ?></pre>
                </details>
                <a class="btn" href="checkout_last.html" target="_blank">Open Full Capture</a>
            <?php endif; ?>
        </section>
    </div>

    <script>
        const bootstrapBtn = document.getElementById('bootstrapBtn');
        const bootstrapResult = document.getElementById('bootstrapResult');
        bootstrapBtn.addEventListener('click', async () => {
            bootstrapBtn.disabled = true;
            bootstrapBtn.textContent = 'Bootstrapping…';
            bootstrapResult.textContent = '';
            try {
                const res = await fetch('jsonp.php?action=proxy-bootstrap&count=20&persist=1');
                const data = await res.json();
                bootstrapResult.innerHTML = `<div class="status-success">Fetched ${data.working_count} working proxies.</div><pre>${JSON.stringify(data.diagnostics, null, 2)}</pre>`;
            } catch (error) {
                bootstrapResult.innerHTML = `<div class="status-error">Failed: ${error instanceof Error ? error.message : error}</div>`;
            } finally {
                bootstrapBtn.disabled = false;
                bootstrapBtn.textContent = 'Bootstrap + Persist 20';
            }
        });

        const testerForm = document.getElementById('testerForm');
        const testerResult = document.getElementById('testerResult');
        testerForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const proxy = testerForm.proxy.value.trim();
            if (!proxy) {
                testerResult.textContent = 'Enter a proxy first.';
                testerResult.className = '';
                return;
            }
            testerResult.textContent = 'Testing…';
            testerResult.className = '';
            try {
                const res = await fetch(`jsonp.php?action=test-proxy&proxy=${encodeURIComponent(proxy)}`);
                const data = await res.json();
                testerResult.textContent = JSON.stringify(data, null, 2);
                testerResult.className = data?.result?.working ? 'status-success' : 'status-error';
            } catch (error) {
                testerResult.textContent = `Error: ${error instanceof Error ? error.message : error}`;
                testerResult.className = 'status-error';
            }
        });
    </script>
</body>
</html>
