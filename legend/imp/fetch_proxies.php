<?php
/**
 * Fast Proxy Fetcher and Tester (Multi-Protocol)
 * - Scrapes HTTP/HTTPS/SOCKS4/SOCKS5 from multiple sources
 * - Parameters (GET):
 *     - count (int): desired number of working proxies to return/save (default: 0 = test all)
 *     - protocols (csv): http,https,socks4,socks5 (default: all)
 *     - timeout (int): per-proxy timeout seconds (default: 3)
 *     - concurrency (int): parallel tests per batch (default: 200, capped at 200)
 *     - sources (csv): builtin,github,proxyscrape (default: builtin,github)
 *     - api/json: when present, returns JSON payload instead of HTML
 * - Saves working proxies with scheme into ProxyList.txt
 */

// Performance optimizations
ob_implicit_flush(true); // Auto flush output
set_time_limit(300); // 5 minutes max
ini_set('max_execution_time', 900);
ini_set('memory_limit', '256M'); // Increase memory for better performance

// Check if API request (return JSON)
if (isset($_GET['api']) || isset($_GET['json'])) {
    header('Content-Type: application/json');
    $apiMode = true;
} else {
    header('Content-Type: text/html; charset=utf-8');
    $apiMode = false;
}

// Concurrency guard rails
const FETCH_MAX_CONCURRENCY = 200;

// Read parameters
$desiredCount = isset($_GET['count']) ? max(0, (int)$_GET['count']) : 0; // 0 = test all
$scrapeLimit = isset($_GET['scrapeLimit']) ? max(0, (int)$_GET['scrapeLimit']) : 0; // 0 = no scrape cap
$timeout = isset($_GET['timeout']) ? max(1, (int)$_GET['timeout']) : 3;
$concurrency = isset($_GET['concurrency'])
    ? min(FETCH_MAX_CONCURRENCY, max(1, (int)$_GET['concurrency']))
    : FETCH_MAX_CONCURRENCY;
$protocolsParam = isset($_GET['protocols']) ? strtolower(trim($_GET['protocols'])) : 'http,https,socks4,socks5';
$sourcesParam = isset($_GET['sources']) ? strtolower(trim($_GET['sources'])) : 'builtin,github';

$allowedProtocols = ['http','https','socks4','socks5'];
$rawProtoTokens = array_filter(array_map('trim', explode(',', $protocolsParam)));
// Expand aliases
$expanded = [];
foreach ($rawProtoTokens as $tok) {
    $t = strtolower($tok);
    if ($t === 'all' || $t === '*') { $expanded = array_merge($expanded, $allowedProtocols); continue; }
    if ($t === 'socks' || $t === 'sock') { $expanded = array_merge($expanded, ['socks4','socks5']); continue; }
    $expanded[] = $t;
}
$requestedProtocols = array_values(array_intersect($allowedProtocols, $expanded));
if (empty($requestedProtocols)) { $requestedProtocols = $allowedProtocols; }

$allowedSources = ['builtin','github','proxyscrape'];
$requestedSources = array_values(array_intersect($allowedSources, array_filter(array_map('trim', explode(',', $sourcesParam)))));
if (empty($requestedSources)) { $requestedSources = ['builtin','github']; }

// Auto-mix mode: when protocols=all or multiple types, fetch from all sources
$autoMixMode = (count($requestedProtocols) >= 3 || in_array('all', $rawProtoTokens));
if ($autoMixMode && empty($_GET['sources'])) {
    $requestedSources = ['builtin', 'github', 'proxyscrape']; // Use all sources for comprehensive mix
}

if (!$apiMode) {
echo "<!DOCTYPE html>
<html>
<head>
    <title>Proxy Fetcher & Tester - @LEGEND_BL</title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 { 
            color: #667eea; 
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
        }
        .progress-section {
            background: #f5f5f5;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4caf50 0%, #45a049 100%);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }
        .log {
            background: #1e1e1e;
            color: #0f0;
            padding: 15px;
            border-radius: 10px;
            height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            margin: 20px 0;
        }
        .log-entry {
            padding: 5px 0;
            border-bottom: 1px solid #333;
        }
        .success { color: #4caf50; }
        .error { color: #f44336; }
        .info { color: #2196f3; }
        .warning { color: #ff9800; }
        .proxy-item {
            background: #2d2d2d;
            padding: 8px 12px;
            margin: 5px 0;
            border-radius: 5px;
            border-left: 4px solid #4caf50;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .proxy-dead {
            border-left-color: #f44336;
            opacity: 0.6;
        }
        .proxy-details {
            font-size: 11px;
            opacity: 0.8;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn:hover { background: #5568d3; }
        .btn-secondary {
            background: #95a5a6;
        }
        .btn-secondary:hover { background: #7f8c8d; }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        .section-title {
            color: #333;
            margin: 20px 0 10px 0;
            font-size: 18px;
            display: flex;
            align-items: center;
        }
        .badge{display:inline-block;padding:2px 6px;border-radius:6px;font-size:10px;margin-left:8px;color:#fff}
        .type-http{background:#3f51b5}
        .type-https{background:#009688}
        .type-socks4{background:#8e44ad}
        .type-socks5{background:#e67e22}
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 Proxy Fetcher & Tester</h1>
    <p class='subtitle'>Automatically scrape, test, and save working proxies from multiple sources</p>
    <p class='subtitle' style='font-size: 12px; margin-top: -5px;'>Owner: <strong>@LEGEND_BL</strong></p>
    
    <div class='stats'>
        <div class='stat-box'>
            <div class='stat-label'>Total Scraped</div>
            <div class='stat-value' id='totalScraped'>0</div>
        </div>
        <div class='stat-box'>
            <div class='stat-label'>Tested</div>
            <div class='stat-value' id='totalTested'>0</div>
        </div>
        <div class='stat-box'>
            <div class='stat-label'>Working</div>
            <div class='stat-value' id='totalWorking'>0</div>
        </div>
        <div class='stat-box'>
            <div class='stat-label'>Success Rate</div>
            <div class='stat-value' id='successRate'>0%</div>
        </div>
    </div>
    
    <div class='progress-section'>
        <h3 class='section-title'><span class='spinner'></span> Progress</h3>
        <div class='progress-bar'>
            <div class='progress-fill' id='progressBar' style='width: 0%'>0%</div>
        </div>
        <div id='currentAction' style='color: #666; margin-top: 10px; font-size: 13px;'>Initializing...</div>
    </div>

    <h3 class='section-title'>⚡ Live Working Proxies <span style='font-size:12px;margin-left:8px;opacity:.7;'>(<span id='liveCount'>0</span>)</span></h3>
    <div id='liveWorkingContainer' style='background:#f5f5f5; padding: 15px; border-radius: 10px; max-height: 260px; overflow-y: auto;'></div>
    <div class='button-group'>
        <a id='liveDownload' class='btn btn-secondary' href='#' download='ProxyList.txt'>📥 Download current list</a>
    </div>
    
    <h3 class='section-title'>📋 Live Log</h3>
    <div class='log' id='log'>
";
echo "<script>
    window.liveProxies = [];
    window.liveBlobUrl = null;
    function updateLiveDownload(){
        try{
            if(window.liveBlobUrl){ URL.revokeObjectURL(window.liveBlobUrl); }
            var text = window.liveProxies.join('\n');
            var blob = new Blob([text], {type: 'text/plain'});
            window.liveBlobUrl = URL.createObjectURL(blob);
            var a = document.getElementById('liveDownload');
            a.href = window.liveBlobUrl;
            a.download = 'ProxyList.txt';
            a.textContent = '📥 Download current list ('+window.liveProxies.length+')';
        }catch(e){}
    }
    function addWorkingProxy(proxy, type, city, country){
        try{
            window.liveProxies.push(proxy);
            var container = document.getElementById('liveWorkingContainer');
            var badgeClass = 'type-'+String(type||'http').toLowerCase();
            var label = String(type||'').toUpperCase();
            var div = document.createElement('div');
            div.className = 'proxy-item';
            var safeProxy = proxy;
            var safeCity = city||''; var safeCountry = country||'';
            div.innerHTML = '<span>'+safeProxy+' <span class=\"badge '+badgeClass+'\">['+label+']</span></span>'+
                            '<span class=\"proxy-details\">'+safeCity+', '+safeCountry+'</span>';
            container.appendChild(div);
            document.getElementById('liveCount').textContent = window.liveProxies.length;
            updateLiveDownload();
        }catch(e){}
    }
</script>";
flush();
}

/**
 * Log message to console
 */
function logMessage($message, $type = 'info') {
    global $apiMode;
    
    $colors = [
        'success' => '#4caf50',
        'error' => '#f44336',
        'info' => '#2196f3',
        'warning' => '#ff9800'
    ];
    
    $color = $colors[$type] ?? '#fff';
    
    if (!$apiMode) {
        echo "<div class='log-entry' style='color: $color;'>$message</div>";
        echo "<script>document.getElementById('log').scrollTop = document.getElementById('log').scrollHeight;</script>";
        flush();
    }
}

/**
 * Update stats display
 */
function updateStats($scraped, $tested, $working) {
    global $apiMode;
    
    if (!$apiMode) {
        $successRate = $tested > 0 ? round(($working / $tested) * 100, 1) : 0;
        echo "<script>
            document.getElementById('totalScraped').textContent = '$scraped';
            document.getElementById('totalTested').textContent = '$tested';
            document.getElementById('totalWorking').textContent = '$working';
            document.getElementById('successRate').textContent = '$successRate%';
        </script>";
        flush();
    }
}

/**
 * Append a working proxy to ProxyList.txt in real time (deduped, locked)
 */
function saveWorkingProxyRealtime(string $proxy, string $file = 'ProxyList.txt'): void {
    static $seen = null;
    $p = trim(strtolower($proxy));
    if ($p === '') return;
    if ($seen === null) {
        $seen = [];
        if (file_exists($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $l) { $seen[trim(strtolower($l))] = true; }
        }
    }
    if (isset($seen[$p])) return;
    $fp = @fopen($file, 'a');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            @fwrite($fp, $p . PHP_EOL);
            @fflush($fp);
            @flock($fp, LOCK_UN);
        } else {
            // Fallback without flock
            @fwrite($fp, $p . PHP_EOL);
            @fflush($fp);
        }
        @fclose($fp);
        $seen[$p] = true;
    }
}

/**
 * Update progress bar
 */
function updateProgress($current, $total, $action = '') {
    global $apiMode;
    
    if (!$apiMode && $total > 0) {
        $percent = round(($current / $total) * 100);
        echo "<script>
            document.getElementById('progressBar').style.width = '$percent%';
            document.getElementById('progressBar').textContent = '$percent%';
            document.getElementById('currentAction').textContent = '$action';
        </script>";
        flush();
    }
}

/**
 * Scrape free-proxy-list.net
 */
function scrapeFreeProxyListNet(array $wantProtocols = ['http','https']) {
    logMessage("📡 Scraping free-proxy-list.net...", 'info');
    
    $proxies = [];
    $maxRetries = 3;
    $response = false;
    
    // Retry logic for better reliability
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://free-proxy-list.net/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Connection: keep-alive'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response && $httpCode == 200) {
            break;
        }
        
        if ($attempt < $maxRetries) {
            logMessage("⚠️ Attempt $attempt failed, retrying...", 'warning');
            sleep(2); // Wait before retry
        }
    }
    
    if ($response) {
        // Parse table using DOM to also detect HTTPS support
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($dom->loadHTML($response)) {
            $xpath = new DOMXPath($dom);
            // Rows under the main table
            foreach ($xpath->query('//table[contains(@id,"proxylisttable")]//tbody/tr') as $tr) {
                $tds = $tr->getElementsByTagName('td');
                if ($tds->length >= 7) {
                    $ip = trim($tds->item(0)->textContent);
                    $port = trim($tds->item(1)->textContent);
                    $httpsFlag = strtolower(trim($tds->item(6)->textContent)); // "yes" or "no"
                    if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($port)) {
                        if (in_array('http', $wantProtocols)) {
                            $proxies[] = 'http://' . $ip . ':' . $port;
                        }
                        if ($httpsFlag === 'yes' && in_array('https', $wantProtocols)) {
                            $proxies[] = 'https://' . $ip . ':' . $port;
                        }
                    }
                }
            }
        }
        libxml_clear_errors();
        logMessage("✓ Found " . count($proxies) . " proxies from free-proxy-list.net", 'success');
    } else {
        logMessage("✗ Failed to fetch from free-proxy-list.net", 'error');
    }
    
    return $proxies;
}

/**
 * Scrape sslproxies.org
 */
function scrapeSSLProxies() {
    logMessage("📡 Scraping sslproxies.org...", 'info');
    
    $proxies = [];
    $maxRetries = 3;
    $response = false;
    $httpCode = 0;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://www.sslproxies.org/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response && ($httpCode === 200 || $httpCode === 0)) {
            break;
        }
        if ($attempt < $maxRetries) {
            logMessage("⚠️ SSLProxies attempt $attempt failed, retrying...", 'warning');
            sleep(2);
        }
    }
    
    if ($response) {
        // Treat these as HTTPS-capable proxies
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($dom->loadHTML($response)) {
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//table[contains(@id,"proxylisttable")]//tbody/tr') as $tr) {
                $tds = $tr->getElementsByTagName('td');
                if ($tds->length >= 2) {
                    $ip = trim($tds->item(0)->textContent);
                    $port = trim($tds->item(1)->textContent);
                    if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($port)) {
                        $proxies[] = 'https://' . $ip . ':' . $port; // prefer https scheme
                    }
                }
            }
        }
        libxml_clear_errors();
        logMessage("✓ Found " . count($proxies) . " SSL proxies", 'success');
    } else {
        logMessage("✗ Failed to fetch SSL proxies", 'error');
    }
    
    return $proxies;
}

/**
 * Scrape socks-proxy.net
 */
function scrapeSOCKSProxies() {
    logMessage("📡 Scraping socks-proxy.net...", 'info');
    
    $proxies = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://www.socks-proxy.net/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($dom->loadHTML($response)) {
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//table[contains(@id,"proxylisttable")]//tbody/tr') as $tr) {
                $tds = $tr->getElementsByTagName('td');
                // Typical columns: IP, Port, Code, Country, Version, Anonymity, ...
                if ($tds->length >= 5) {
                    $ip = trim($tds->item(0)->textContent);
                    $port = trim($tds->item(1)->textContent);
                    $version = strtolower(trim($tds->item(4)->textContent)); // socks4 or socks5
                    if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($port)) {
                        if ($version === 'socks5') {
                            $proxies[] = 'socks5://' . $ip . ':' . $port;
                        } elseif ($version === 'socks4') {
                            $proxies[] = 'socks4://' . $ip . ':' . $port;
                        }
                    }
                }
            }
        }
        libxml_clear_errors();
        logMessage("✓ Found " . count($proxies) . " SOCKS proxies", 'success');
    } else {
        logMessage("✗ Failed to fetch SOCKS proxies", 'error');
    }
    
    return $proxies;
}

/**
 * Scrape from GitHub raw lists (TheSpeedX/PROXY-List)
 */
function scrapeFromGitHubLists(array $wantProtocols): array {
    $map = [
        'http' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt',
        'https' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/https.txt',
        'socks4' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks4.txt',
        'socks5' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt',
    ];
    $all = [];
    foreach ($wantProtocols as $p) {
        if (!isset($map[$p])) continue;
        logMessage("📡 Scraping GitHub ($p)...", 'info');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $map[$p],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_USERAGENT => 'curl/8 GithubFetcher'
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $lines = array_filter(array_map('trim', explode("\n", $resp)));
            foreach ($lines as $line) {
                if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d+$/', $line)) {
                    $all[] = $p . '://' . $line;
                }
            }
            logMessage("✓ GitHub $p proxies: " . count($lines), 'success');
        } else {
            logMessage("✗ GitHub list failed for $p", 'error');
        }
    }
    return $all;
}

/**
 * Scrape from GeoNode API (very reliable, JSON-based)
 */
function scrapeGeoNode(array $wantProtocols): array {
    logMessage("📡 Scraping GeoNode API...", 'info');
    $proxies = [];
    
    $protocols = [];
    if (in_array('http', $wantProtocols) || in_array('https', $wantProtocols)) $protocols[] = 'http';
    if (in_array('socks4', $wantProtocols)) $protocols[] = 'socks4';
    if (in_array('socks5', $wantProtocols)) $protocols[] = 'socks5';
    
    foreach ($protocols as $proto) {
        for ($page = 1; $page <= 2; $page++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://proxylist.geonode.com/api/proxy-list?protocols=$proto&limit=100&page=$page&sort_by=lastChecked&sort_type=desc",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0'
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $proxy) {
                        if (isset($proxy['ip']) && isset($proxy['port'])) {
                            $ip = $proxy['ip'];
                            $port = $proxy['port'];
                            $protoList = $proxy['protocols'] ?? [$proto];
                            
                            foreach ($protoList as $p) {
                                $pLower = strtolower($p);
                                if (in_array($pLower, $wantProtocols)) {
                                    $proxies[] = "$pLower://$ip:$port";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    logMessage("✓ Found " . count(array_unique($proxies)) . " proxies from GeoNode", 'success');
    return array_unique($proxies);
}

/**
 * Scrape from Proxy-List API (fast and reliable)
 */
function scrapeProxyListDownload(array $wantProtocols): array {
    logMessage("📡 Scraping Proxy-List.download...", 'info');
    $proxies = [];
    
    $typeMap = [
        'http' => 'http',
        'https' => 'https', 
        'socks4' => 'socks4',
        'socks5' => 'socks5'
    ];
    
    foreach ($wantProtocols as $proto) {
        if (isset($typeMap[$proto])) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://www.proxy-list.download/api/v1/get?type={$typeMap[$proto]}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0'
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $lines = explode("\n", trim($response));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^(\d+\.\d+\.\d+\.\d+):(\d+)$/', $line, $m)) {
                        $proxies[] = "$proto://{$m[1]}:{$m[2]}";
                    }
                }
            }
        }
    }
    
    logMessage("✓ Found " . count($proxies) . " proxies from Proxy-List.download", 'success');
    return $proxies;
}

/**
 * Scrape additional GitHub sources for more proxy diversity
 */
function scrapeFromAdditionalGitHubSources(array $wantProtocols): array {
    $sources = [
        'http' => [
            'https://raw.githubusercontent.com/clarketm/proxy-list/master/proxy-list-raw.txt',
            'https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt',
            'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt',
        ],
        'https' => [
            'https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/https.txt',
            'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/https.txt',
        ],
        'socks4' => [
            'https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/socks4.txt',
            'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/socks4.txt',
            'https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-socks4.txt',
        ],
        'socks5' => [
            'https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/socks5.txt',
            'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/socks5.txt',
            'https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-socks5.txt',
        ],
    ];
    
    $all = [];
    foreach ($wantProtocols as $p) {
        if (!isset($sources[$p])) continue;
        foreach ($sources[$p] as $url) {
            logMessage("📡 Scraping additional GitHub source ($p)...", 'info');
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => '',
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_USERAGENT => 'curl/8 ProxyFetcher'
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            if ($resp) {
                $lines = array_filter(array_map('trim', explode("\n", $resp)));
                $count = 0;
                foreach ($lines as $line) {
                    // Handle both ip:port and scheme://ip:port formats
                    if (preg_match('/^(?:https?|socks[45]):\/\/(.+)$/i', $line, $m)) {
                        $all[] = strtolower($line);
                        $count++;
                    } elseif (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d+$/', $line)) {
                        $all[] = $p . '://' . $line;
                        $count++;
                    }
                }
                if ($count > 0) {
                    logMessage("✓ Found $count additional $p proxies", 'success');
                }
            }
        }
    }
    return $all;
}

/**
 * Scrape from proxyscrape API (v3)
 */
function scrapeFromProxyScrape(array $wantProtocols): array {
    $base = 'https://api.proxyscrape.com/v3/free-proxy-list/get?request=displayproxies&country=all&format=text&proxy_format=protocolipport';
    $all = [];
    foreach ($wantProtocols as $p) {
        logMessage("📡 Scraping ProxyScrape ($p)...", 'info');
        $url = $base . '&protocol=' . urlencode($p);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_USERAGENT => 'curl/8 ProxyScrapeFetcher'
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $lines = array_filter(array_map('trim', explode("\n", $resp)));
            foreach ($lines as $line) {
                // ProxyScrape may already include protocol in each line; accept if so, else prefix
                if (preg_match('/^(https?|socks4|socks5):\/\//i', $line)) {
                    $all[] = strtolower($line);
                } elseif (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d+$/', $line)) {
                    $all[] = $p . '://' . $line;
                }
            }
            logMessage("✓ ProxyScrape $p proxies: " . count($lines), 'success');
        } else {
            logMessage("✗ ProxyScrape list failed for $p", 'error');
        }
    }
    return $all;
}

/**
 * Test if proxy is working
 */
function testProxy($proxyString, $timeout = 5) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'http://ip-api.com/json', // Use HTTP not HTTPS
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPPROXYTUNNEL => false, // Using HTTP target; no CONNECT tunnel needed
        // parse scheme
        // supported formats: scheme://ip:port or ip:port (defaults to http)
        CURLOPT_ENCODING => '', // Enable compression
        CURLOPT_TCP_NODELAY => true, // Disable Nagle's algorithm for speed
        CURLOPT_MAXREDIRS => 2 // Limit redirects
    ]);
    $proxy = trim($proxyString);
    $type = 'http';
    $addr = $proxy;
    if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $proxy, $m)) {
        $type = strtolower($m[1]);
        $addr = $m[2];
    }
    curl_setopt($ch, CURLOPT_PROXY, $addr);
    if ($type === 'socks4') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
    elseif ($type === 'socks5') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    elseif ($type === 'https') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
    else curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response !== false && $httpCode == 200) {
        $data = json_decode($response, true);
        return [
            'working' => true,
            'ip' => $data['query'] ?? 'unknown',
            'country' => $data['country'] ?? 'unknown',
            'city' => $data['city'] ?? 'unknown'
        ];
    }
    
    return ['working' => false, 'error' => $error];
}

/**
 * Test proxies in parallel using curl_multi (batched)
 * Keeps timeouts unchanged, defaults: timeout=3s, concurrency=200
 * If $targetWorking <= 0, tests ALL proxies (no early stop)
 */
function testProxiesInParallel(array $proxies, int $timeout = 3, int $concurrency = FETCH_MAX_CONCURRENCY, int $targetWorking = 0): array {
    $total = count($proxies);
    $tested = 0;
    $working = [];
    $workingDetails = [];
    
    // Process in chunks to limit parallelism
    $chunks = array_chunk($proxies, max(1, $concurrency));
    $chunkIndex = 0;
    foreach ($chunks as $chunk) {
        $chunkIndex++;
        $mh = curl_multi_init();
        $handles = [];
        
        foreach ($chunk as $proxy) {
            $ch = curl_init();
            // Parse scheme first to adjust timeout
            $p = trim($proxy);
            $type = 'http';
            $addr = $p;
            if (preg_match('/^(https?|socks4|socks5):\/\/(.+)$/i', $p, $m)) {
                $type = strtolower($m[1]);
                $addr = $m[2];
            }
            // SOCKS proxies need double timeout for SSL handshake
            $actualTimeout = ($type === 'socks4' || $type === 'socks5') ? ($timeout * 2) : $timeout;
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'http://ip-api.com/json',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $actualTimeout,
                CURLOPT_CONNECTTIMEOUT => $actualTimeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPPROXYTUNNEL => false,
                CURLOPT_ENCODING => '',
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_MAXREDIRS => 2
            ]);
            curl_setopt($ch, CURLOPT_PROXY, $addr);
            if ($type === 'socks4') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            elseif ($type === 'socks5') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            elseif ($type === 'https') curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            else curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            $handles[] = [
                'handle' => $ch,
                'proxy' => $proxy,
                'type' => $type,
            ];
            curl_multi_add_handle($mh, $ch);
        }
        
        // Execute all handles in this chunk
        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $mrc == CURLM_OK);
        
        // Collect results
        foreach ($handles as $key => $entry) {
            $ch = $entry['handle'];
            $proxy = $entry['proxy'];
            $ptype = $entry['type'];
            $tested++;
            
            updateProgress($tested, $total, "Testing proxy $tested/$total: $proxy");
            logMessage("[$tested/$total] Testing $proxy...", 'warning');
            
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            
            if ($response !== false && $httpCode == 200) {
                $data = json_decode($response, true);
                $city = $data['city'] ?? 'unknown';
                $country = $data['country'] ?? 'unknown';
                // Ensure saved proxy has scheme
                $normalized = preg_match('/^(https?|socks4|socks5):\/\//i', $proxy) ? strtolower($proxy) : ('http://' . $proxy);
                $working[] = $normalized;
                $workingDetails[] = [
                    'proxy' => $normalized,
                    'type' => strtolower($ptype),
                    'city' => $city,
                    'country' => $country,
                ];
                // Real-time persistence to ProxyList.txt
                saveWorkingProxyRealtime($normalized, 'ProxyList.txt');
                logMessage("✓ WORKING: $normalized | $city, $country | type: " . strtoupper($ptype), 'success');
                
                // Emit to live viewer (HTML mode only)
                if (!isset($GLOBALS['apiMode']) || $GLOBALS['apiMode'] === false) {
                    echo "<script>addWorkingProxy(".json_encode($normalized).",".json_encode(strtoupper($ptype)).",".json_encode($city).",".json_encode($country).");</script>";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }
            } else {
                logMessage("✗ DEAD: $proxy | Reason: " . ($err ?: 'Timeout'), 'error');
            }
            
            updateStats($total, $tested, count($working));
            
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            
            if ($targetWorking > 0 && count($working) >= $targetWorking) {
                // Cleanup remaining handles in this chunk
                foreach ($handles as $k2 => $e2) {
                    if ($e2['handle'] !== $ch) {
                        curl_multi_remove_handle($mh, $e2['handle']);
                        curl_close($e2['handle']);
                    }
                }
                curl_multi_close($mh);
                return [$working, $tested, $workingDetails];
            }
        }
        
        curl_multi_close($mh);
    }
    
    return [$working, $tested, $workingDetails];
}

// ============================================
// MAIN EXECUTION
// ============================================

$startTime = microtime(true);

// Load existing proxies from ProxyList.txt to preserve working ones
$existingProxies = [];
if (file_exists('ProxyList.txt')) {
    $lines = file('ProxyList.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line && $line[0] !== '#') {
            $existingProxies[] = strtolower($line);
        }
    }
    if (!empty($existingProxies)) {
        logMessage("📋 Found " . count($existingProxies) . " existing proxies in ProxyList.txt", 'info');
    }
}

logMessage("🚀 Starting proxy scraping and testing...", 'info');
logMessage("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');

$allProxies = [];

// Scrape from requested sources and protocols
$progressSteps = count($requestedSources) + (in_array('builtin', $requestedSources) ? 4 : 0) + (in_array('github', $requestedSources) ? 1 : 0);
$step = 0;

if (in_array('builtin', $requestedSources)) {
    updateProgress(++$step, $progressSteps, "Scraping free-proxy-list.net...");
    $allProxies = array_merge($allProxies, scrapeFreeProxyListNet($requestedProtocols));
    updateProgress(++$step, $progressSteps, "Scraping sslproxies.org...");
    $allProxies = array_merge($allProxies, scrapeSSLProxies());
    updateProgress(++$step, $progressSteps, "Scraping socks-proxy.net...");
    $allProxies = array_merge($allProxies, scrapeSOCKSProxies());
    updateProgress(++$step, $progressSteps, "Scraping GeoNode API...");
    $allProxies = array_merge($allProxies, scrapeGeoNode($requestedProtocols));
    updateProgress(++$step, $progressSteps, "Scraping Proxy-List.download...");
    $allProxies = array_merge($allProxies, scrapeProxyListDownload($requestedProtocols));
}
if (in_array('github', $requestedSources)) {
    updateProgress(++$step, $progressSteps, "Scraping GitHub raw lists (TheSpeedX)...");
    $allProxies = array_merge($allProxies, scrapeFromGitHubLists($requestedProtocols));
    updateProgress(++$step, $progressSteps, "Scraping additional GitHub sources...");
    $allProxies = array_merge($allProxies, scrapeFromAdditionalGitHubSources($requestedProtocols));
}
if (in_array('proxyscrape', $requestedSources)) {
    updateProgress(++$step, $progressSteps, "Scraping ProxyScrape API...");
    $allProxies = array_merge($allProxies, scrapeFromProxyScrape($requestedProtocols));
}

// Filter by requested protocols if not already prefixed
$filtered = [];
foreach ($allProxies as $p) {
    $pp = trim(strtolower($p));
    if ($pp === '') continue;
    $scheme = 'http';
    if (preg_match('/^(https?|socks4|socks5):\/\//', $pp, $m)) {
        $scheme = $m[1];
    }
    if (in_array($scheme, $requestedProtocols)) {
        $filtered[] = $pp;
    }
}
$allProxies = array_values(array_unique($filtered));
// Apply optional scrapeLimit to cap how many proxies we test
if (isset($scrapeLimit) && $scrapeLimit > 0 && count($allProxies) > $scrapeLimit) {
    $allProxies = array_slice($allProxies, 0, $scrapeLimit);
    logMessage("🔧 Applying scrape limit: using first $scrapeLimit proxies for testing", 'warning');
}

logMessage("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
logMessage("📊 Total unique proxies found: " . count($allProxies), 'success');
if ($desiredCount > 0) {
    logMessage("⚡ Starting proxy testing (timeout: {$timeout}s, target: {$desiredCount} working proxies)...", 'info');
} else {
    logMessage("⚡ Starting proxy testing (timeout: {$timeout}s, full scan)...", 'info');
}
logMessage("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');

updateProgress($progressSteps, $progressSteps, "Scraping complete! Testing proxies...");
updateStats(count($allProxies), 0, 0);

$workingProxies = [];
$tested = 0;

// Parallel testing with curl_multi
list($workingProxies, $tested, $workingDetails) = testProxiesInParallel($allProxies, $timeout, $concurrency, $desiredCount);

// Merge with existing working proxies and remove duplicates
if (!empty($existingProxies)) {
    logMessage("🔄 Merging with existing proxies and removing duplicates...", 'info');
    $allWorkingProxies = array_unique(array_merge($existingProxies, $workingProxies));
    $added = count($workingProxies);
    $removed = count($existingProxies) - (count($allWorkingProxies) - $added);
    logMessage("   • New proxies added: $added", 'success');
    if ($removed > 0) {
        logMessage("   • Dead proxies removed: $removed", 'warning');
    }
    $workingProxies = array_values($allWorkingProxies);
}

// Save final merged list
if (!empty($workingProxies)) {
    file_put_contents('ProxyList.txt', implode("\n", $workingProxies) . "\n", LOCK_EX);
    logMessage("💾 Saved " . count($workingProxies) . " total working proxies to ProxyList.txt", 'success');
}

logMessage("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');

if (!empty($workingProxies)) {
    $elapsed = round(microtime(true) - $startTime, 2);
    $successRate = round((count($workingProxies) / $tested) * 100, 2);
    
    logMessage("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
    logMessage("🎯 Final Statistics:", 'info');
    logMessage("   • Total Scraped: " . count($allProxies), 'info');
    logMessage("   • Total Tested: $tested", 'info');
    logMessage("   • Working Proxies: " . count($workingProxies), 'success');
    logMessage("   • Success Rate: $successRate%", 'success');
    logMessage("   • Time Taken: {$elapsed}s", 'info');
    logMessage("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━", 'info');
    
    if ($apiMode) {
        // Return JSON for API requests
        echo json_encode([
            'success' => true,
            'proxies' => $workingProxies,
            'proxies_detailed' => $workingDetails,
            'stats' => [
                'total_scraped' => count($allProxies),
                'tested' => $tested,
                'working' => count($workingProxies),
                'success_rate' => $successRate,
                'time_taken' => $elapsed,
                'parameters' => [
                    'count' => $desiredCount,
                    'scrapeLimit' => (int)($scrapeLimit ?? 0),
                    'timeout' => $timeout,
                    'concurrency' => $concurrency,
                    'protocols' => $requestedProtocols,
                    'sources' => $requestedSources
                ]
            ]
        ], JSON_PRETTY_PRINT);
    } else {
        // HTML output
        echo "</div>"; // Close log div
        
        echo "<style>
            .badge{display:inline-block;padding:2px 6px;border-radius:6px;font-size:10px;margin-left:8px;color:#fff}
            .type-http{background:#3f51b5}
            .type-https{background:#009688}
            .type-socks4{background:#8e44ad}
            .type-socks5{background:#e67e22}
        </style>";
        echo "<h3 class='section-title'>✅ Working Proxies (" . count($workingProxies) . ")</h3>";
        echo "<div style='background: #f5f5f5; padding: 15px; border-radius: 10px; max-height: 360px; overflow-y: auto;'>";
        foreach ($workingDetails as $row) {
            $p = htmlspecialchars($row['proxy']);
            $t = htmlspecialchars(strtolower($row['type']));
            $label = strtoupper($t);
            $city = htmlspecialchars($row['city'] ?? 'unknown');
            $country = htmlspecialchars($row['country'] ?? 'unknown');
            echo "<div class='proxy-item'>
                <span>$p <span class='badge type-$t'>[$label]</span></span>
                <span class='proxy-details'>$city, $country</span>
            </div>";
        }
        echo "</div>";
        
        echo "<div class='button-group'>
            <a href='/' class='btn'>← Back to Dashboard</a>
            <a href='ProxyList.txt' class='btn btn-secondary' download>📥 Download Full List (TXT)</a>
            <a href='download_proxies.php?format=json' class='btn btn-secondary' target='_blank'>📄 Download JSON</a>
            <a href='?api=1' class='btn btn-secondary' target='_blank'>📊 View JSON API</a>
        </div>";
        
        echo "<div class='info-box' style='background:#e8f5e9;border-left-color:#4caf50;margin-top:20px;'>
            <h3>🎯 Download Options</h3>
            <p style='margin:5px 0;'><strong>Filter by type:</strong></p>
            <div style='display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;'>
                <a href='download_proxies.php?type=http' class='btn btn-secondary' style='font-size:12px;padding:6px 12px;'>HTTP Only</a>
                <a href='download_proxies.php?type=https' class='btn btn-secondary' style='font-size:12px;padding:6px 12px;'>HTTPS Only</a>
                <a href='download_proxies.php?type=socks4' class='btn btn-secondary' style='font-size:12px;padding:6px 12px;'>SOCKS4 Only</a>
                <a href='download_proxies.php?type=socks5' class='btn btn-secondary' style='font-size:12px;padding:6px 12px;'>SOCKS5 Only</a>
                <a href='download_proxies.php?limit=50' class='btn btn-secondary' style='font-size:12px;padding:6px 12px;'>Top 50</a>
                <a href='download_proxies.php?limit=100' class='btn btn-secondary' style='font-size:12px;padding:6px 12px;'>Top 100</a>
            </div>
        </div>";
        
        echo "</div></body></html>";
    }
    
} else {
    logMessage("✗ No working proxies found! Try again later.", 'error');
    
    if ($apiMode) {
        echo json_encode([
            'success' => false,
            'message' => 'No working proxies found',
            'stats' => [
                'total_scraped' => count($allProxies),
                'tested' => $tested,
                'working' => 0
            ]
        ]);
    } else {
        echo "</div>";
        echo "<div style='background: #ffebee; color: #c62828; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
        echo "<h3>❌ No Working Proxies Found</h3>";
        echo "<p>All tested proxies failed to respond. This could be due to:</p>";
        echo "<ul>";
        echo "<li>Network connectivity issues</li>";
        echo "<li>All proxies are currently offline</li>";
        echo "<li>Firewall blocking proxy connections</li>";
        echo "</ul>";
        echo "<p>Try running the script again in a few minutes.</p>";
        echo "</div>";
        
        echo "<div class='button-group'>";
        echo "<a href='/' class='btn'>← Back to Dashboard</a>";
        echo "<a href='?' class='btn btn-secondary'>🔄 Try Again</a>";
        echo "<a href='download_proxies.php?format=json' class='btn btn-secondary' target='_blank'>📄 View Current List (JSON)</a>";
        echo "</div>";
        
        echo "</div></body></html>";
    }
}