<?php
/**
 * Advanced Payment Gateway & Proxy Tool - Main Entry Point
 * 
 * Features:
 * - Advanced payment gateway detection (50+ gateways)
 * - Multi-payment method support (Credit Cards, Debit Cards, Digital Wallets)
 * - High-performance proxy management (200 concurrent connections)
 * - Real-time gateway detection and payment processing
 * - Advanced JSON API with comprehensive gateway support
 * - Modern responsive UI
 */

// Set headers for security and performance
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Check if API request
$isApiRequest = isset($_GET['api']) || isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

// API Mode - Return JSON
if ($isApiRequest) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? 'info';
    
    switch ($action) {
        case 'detect':
            // Gateway detection API
            $url = $_GET['url'] ?? '';
            if (empty($url)) {
                echo json_encode(['error' => 'URL parameter required']);
                exit;
            }
            
            require_once 'autosh.php';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, flow_user_agent());
            $response = curl_exec($ch);
            curl_close($ch);
            
            $gateway = GatewayDetector::detect($response, $url);
            $allGateways = GatewayDetector::detectAll($response, $url);
            
            echo json_encode([
                'url' => $url,
                'primary_gateway' => $gateway,
                'all_gateways' => $allGateways,
                'supported_methods' => GatewayDetector::getSupportedMethods($gateway),
                'timestamp' => time()
            ]);
            exit;
            
        case 'proxy_stats':
            // Proxy statistics API
            require_once 'ProxyManager.php';
            $pm = new ProxyManager();
            if (file_exists('ProxyList.txt')) {
                $pm->loadFromFile('ProxyList.txt');
            }
            $stats = $pm->getStats();
            echo json_encode($stats);
            exit;
            
        case 'info':
        default:
            echo json_encode([
                'name' => 'Advanced Payment Gateway & Proxy Tool',
                'version' => '2.0.0',
                'features' => [
                    'Advanced gateway detection (50+ gateways)',
                    'Multi-payment method support',
                    '200 concurrent proxy connections',
                    'Real-time payment processing',
                    'Advanced JSON API'
                ],
                'endpoints' => [
                    '/index.php?api=1&action=detect&url=...' => 'Detect payment gateway',
                    '/index.php?api=1&action=proxy_stats' => 'Get proxy statistics',
                    '/autosh.php?cc=...&site=...' => 'Process payment',
                    '/jsonp.php?url=...' => 'Advanced JSON gateway detection'
                ]
            ]);
            exit;
    }
}

// HTML Mode - Show UI
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Payment Gateway & Proxy Tool</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #4caf50;
            --warning: #ff9800;
            --danger: #f44336;
            --dark: #1a1a2e;
            --light: #f5f5f5;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        
        .header h1 {
            color: var(--primary);
            font-size: 2.5em;
            margin-bottom: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 1.1em;
            margin-bottom: 20px;
        }
        
        .badges {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            color: white;
        }
        
        .badge-success { background: var(--success); }
        .badge-warning { background: var(--warning); }
        .badge-info { background: #2196f3; }
        .badge-danger { background: var(--danger); }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 50px rgba(0,0,0,0.3);
        }
        
        .card h2 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card p {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 600;
            margin-right: 10px;
            margin-top: 5px;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            background: var(--secondary);
            transform: scale(1.05);
        }
        
        .btn-success { background: var(--success); }
        .btn-success:hover { background: #45a049; }
        
        .btn-warning { background: var(--warning); }
        .btn-warning:hover { background: #e68900; }
        
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #d32f2f; }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 600;
            font-size: 0.9em;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            padding: 10px 0;
            color: #666;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .feature-list li:before {
            content: "✓";
            color: var(--success);
            font-weight: bold;
            font-size: 1.2em;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }
        
        .stat-card .number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-card .label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .code-block {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin-top: 10px;
            border-left: 4px solid var(--primary);
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 20px;
            margin-top: 20px;
            border-radius: 10px;
        }
        
        .info-box h3 {
            color: #1976d2;
            margin-bottom: 10px;
            font-size: 1.2em;
        }
        
        .info-box p {
            color: #555;
            margin: 5px 0;
        }
        
        .gateway-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .gateway-item {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-size: 0.9em;
            font-weight: 600;
            color: #555;
            transition: all 0.3s ease;
        }
        
        .gateway-item:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.05);
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 1.8em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Advanced Payment Gateway & Proxy Tool</h1>
            <p class="subtitle">Professional-grade payment processing with advanced gateway detection and high-performance proxy management</p>
            <div class="badges">
                <span class="badge badge-success">⚡ 200 Concurrent Connections</span>
                <span class="badge badge-info">💳 50+ Payment Gateways</span>
                <span class="badge badge-warning">🔄 Advanced Proxy Rotation</span>
                <span class="badge badge-danger">🚀 High Performance</span>
            </div>
        </div>
        
        <div class="grid">
            <div class="card">
                <h2>💳 Payment Gateway Detection</h2>
                <p>Advanced detection system supporting 50+ payment gateways including Stripe, PayPal, Razorpay, Square, Braintree, Adyen, and many more.</p>
                <div class="form-group">
                    <label>Website URL</label>
                    <input type="url" id="detectUrl" placeholder="https://example.com" value="">
                </div>
                <button class="btn btn-success" onclick="detectGateway()">🔍 Detect Gateway</button>
                <div id="detectionResult" style="margin-top: 15px;"></div>
            </div>
            
            <div class="card">
                <h2>🔄 Proxy Management</h2>
                <p>High-performance proxy system supporting 200 concurrent connections with automatic rotation and health checking.</p>
                <div class="stats-grid" id="proxyStats">
                    <div class="stat-card">
                        <div class="number" id="totalProxies">-</div>
                        <div class="label">Total Proxies</div>
                    </div>
                    <div class="stat-card">
                        <div class="number" id="liveProxies">-</div>
                        <div class="label">Live Proxies</div>
                    </div>
                    <div class="stat-card">
                        <div class="number" id="deadProxies">-</div>
                        <div class="label">Dead Proxies</div>
                    </div>
                </div>
                <button class="btn" onclick="loadProxyStats()">🔄 Refresh Stats</button>
                <a href="fetch_proxies.php" class="btn btn-success" target="_blank">📥 Fetch Proxies</a>
            </div>
            
            <div class="card">
                <h2>⚡ Payment Processing</h2>
                <p>Process payments through detected gateways with support for credit cards, debit cards, and digital wallets.</p>
                <a href="autosh.php?cc=4111111111111111|12|2025|123&site=https://example.myshopify.com" class="btn btn-success" target="_blank">🚀 Process Payment</a>
                <a href="jsonp.php?url=https://example.com" class="btn btn-warning" target="_blank">📊 Advanced JSON API</a>
            </div>
            
            <div class="card">
                <h2>🌐 Supported Gateways</h2>
                <p>Comprehensive support for major payment gateways worldwide:</p>
                <div class="gateway-list">
                    <div class="gateway-item">Stripe</div>
                    <div class="gateway-item">PayPal</div>
                    <div class="gateway-item">Razorpay</div>
                    <div class="gateway-item">Square</div>
                    <div class="gateway-item">Braintree</div>
                    <div class="gateway-item">Adyen</div>
                    <div class="gateway-item">Authorize.Net</div>
                    <div class="gateway-item">Checkout.com</div>
                    <div class="gateway-item">Worldpay</div>
                    <div class="gateway-item">SagePay</div>
                    <div class="gateway-item">PayU</div>
                    <div class="gateway-item">Paytm</div>
                    <div class="gateway-item">PhonePe</div>
                    <div class="gateway-item">Shopify Payments</div>
                    <div class="gateway-item">+35 More</div>
                </div>
            </div>
            
            <div class="card">
                <h2>📊 API Endpoints</h2>
                <p>RESTful API for integration:</p>
                <div class="code-block">
GET /index.php?api=1&action=detect&url=https://example.com
GET /index.php?api=1&action=proxy_stats
GET /jsonp.php?url=https://example.com
POST /autosh.php?cc=...&site=...
                </div>
            </div>
            
            <div class="card">
                <h2>✨ Features</h2>
                <ul class="feature-list">
                    <li>Advanced gateway detection (50+ gateways)</li>
                    <li>Multi-payment method support</li>
                    <li>200 concurrent proxy connections</li>
                    <li>Automatic proxy rotation</li>
                    <li>Real-time health checking</li>
                    <li>Advanced JSON API</li>
                    <li>Credit card processing</li>
                    <li>Digital wallet support</li>
                    <li>High-performance optimization</li>
                    <li>Comprehensive error handling</li>
                </ul>
            </div>
        </div>
        
        <div class="info-box">
            <h3>🚀 Quick Start</h3>
            <p><strong>1. Detect Gateway:</strong> Enter a website URL and click "Detect Gateway"</p>
            <p><strong>2. Process Payment:</strong> Use the payment processing tool with credit card details</p>
            <p><strong>3. Manage Proxies:</strong> Fetch and manage proxies for optimal performance</p>
            <p><strong>4. API Integration:</strong> Use the JSON API endpoints for automated processing</p>
        </div>
    </div>
    
    <script>
        // Load proxy stats on page load
        window.addEventListener('DOMContentLoaded', function() {
            loadProxyStats();
        });
        
        function loadProxyStats() {
            fetch('index.php?api=1&action=proxy_stats')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('totalProxies').textContent = data.total_proxies || 0;
                    document.getElementById('liveProxies').textContent = data.live_proxies || 0;
                    document.getElementById('deadProxies').textContent = data.dead_proxies || 0;
                })
                .catch(e => {
                    console.error('Failed to load proxy stats:', e);
                });
        }
        
        function detectGateway() {
            const url = document.getElementById('detectUrl').value;
            if (!url) {
                alert('Please enter a URL');
                return;
            }
            
            const resultDiv = document.getElementById('detectionResult');
            resultDiv.innerHTML = '<p>Detecting...</p>';
            
            fetch(`index.php?api=1&action=detect&url=${encodeURIComponent(url)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        resultDiv.innerHTML = `<p style="color: red;">Error: ${data.error}</p>`;
                        return;
                    }
                    
                    let html = `<div style="background: #e8f5e9; padding: 15px; border-radius: 10px; margin-top: 10px;">`;
                    html += `<h3 style="color: #2e7d32; margin-bottom: 10px;">Detection Results</h3>`;
                    html += `<p><strong>Primary Gateway:</strong> <span style="color: var(--primary); font-weight: bold;">${data.primary_gateway || 'Unknown'}</span></p>`;
                    
                    if (data.all_gateways && data.all_gateways.length > 0) {
                        html += `<p><strong>All Detected Gateways:</strong> ${data.all_gateways.join(', ')}</p>`;
                    }
                    
                    if (data.supported_methods && data.supported_methods.length > 0) {
                        html += `<p><strong>Supported Methods:</strong> ${data.supported_methods.join(', ')}</p>`;
                    }
                    
                    html += `</div>`;
                    resultDiv.innerHTML = html;
                })
                .catch(e => {
                    resultDiv.innerHTML = `<p style="color: red;">Error: ${e.message}</p>`;
                });
        }
    </script>
</body>
</html>
