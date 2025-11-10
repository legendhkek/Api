<?php
/**
 * PHP.PHP - HTTP Response Viewer & Debugger
 * 
 * Displays HTTP responses in a formatted, readable way
 * Used for debugging Shopify checkout responses
 * 
 * @version 2.0
 */

// Check if this is being accessed directly or included
$is_direct_access = !isset($GLOBALS['__php_php_included']);

// If accessed directly, show interface
if ($is_direct_access && !isset($_POST['response_data']) && !isset($_GET['view'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>🔍 PHP.PHP - Response Viewer</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Courier New', monospace;
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 20px;
            }
            .container {
                max-width: 1400px;
                margin: 0 auto;
            }
            .header {
                background: #252526;
                padding: 30px;
                border-radius: 8px;
                margin-bottom: 20px;
                border-left: 4px solid #4ec9b0;
            }
            h1 {
                color: #4ec9b0;
                margin-bottom: 10px;
                font-size: 24px;
            }
            .subtitle {
                color: #9cdcfe;
                font-size: 14px;
            }
            .section {
                background: #252526;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .section h2 {
                color: #4ec9b0;
                font-size: 18px;
                margin-bottom: 15px;
            }
            textarea {
                width: 100%;
                min-height: 200px;
                background: #1e1e1e;
                color: #d4d4d4;
                border: 1px solid #3c3c3c;
                border-radius: 4px;
                padding: 15px;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                resize: vertical;
            }
            .btn {
                background: #0e639c;
                color: white;
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                font-weight: bold;
                cursor: pointer;
                transition: background 0.3s;
            }
            .btn:hover {
                background: #1177bb;
            }
            .btn-secondary {
                background: #37373d;
            }
            .btn-secondary:hover {
                background: #4e4e55;
            }
            .response {
                background: #1e1e1e;
                padding: 20px;
                border-radius: 4px;
                border: 1px solid #3c3c3c;
                overflow-x: auto;
                margin-top: 15px;
            }
            .header-line {
                color: #4fc1ff;
                padding: 2px 0;
            }
            .status-200 { color: #4ec9b0; }
            .status-400 { color: #ce9178; }
            .status-500 { color: #f48771; }
            .json-key { color: #9cdcfe; }
            .json-value { color: #ce9178; }
            .json-string { color: #ce9178; }
            .json-number { color: #b5cea8; }
            .json-boolean { color: #569cd6; }
            .help {
                color: #858585;
                font-size: 12px;
                margin-top: 10px;
            }
            .examples {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }
            .example {
                background: #1e1e1e;
                padding: 15px;
                border-radius: 4px;
                border: 1px solid #3c3c3c;
                cursor: pointer;
                transition: border-color 0.3s;
            }
            .example:hover {
                border-color: #4ec9b0;
            }
            .example h3 {
                color: #dcdcaa;
                font-size: 14px;
                margin-bottom: 8px;
            }
            .example p {
                color: #858585;
                font-size: 11px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔍 PHP.PHP - HTTP Response Viewer & Debugger</h1>
                <p class="subtitle">Format and analyze HTTP responses, headers, and JSON data</p>
            </div>
            
            <div class="section">
                <h2>Paste Response Data</h2>
                <form method="POST" action="">
                    <textarea name="response_data" placeholder="Paste HTTP response here (headers + body)..."></textarea>
                    <div class="help">
                        Paste complete HTTP response including headers. Supports JSON, HTML, and plain text.
                    </div>
                    <div style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="submit" class="btn">🔍 Analyze Response</button>
                        <button type="button" class="btn btn-secondary" onclick="fillExample()">📋 Load Example</button>
                        <button type="button" class="btn btn-secondary" onclick="clearData()">🗑️ Clear</button>
                    </div>
                </form>
            </div>
            
            <div class="section">
                <h2>Features</h2>
                <div class="examples">
                    <div class="example">
                        <h3>✓ Header Parsing</h3>
                        <p>Extracts and formats HTTP headers with status codes</p>
                    </div>
                    <div class="example">
                        <h3>✓ JSON Formatting</h3>
                        <p>Pretty-prints JSON with syntax highlighting</p>
                    </div>
                    <div class="example">
                        <h3>✓ Cookie Extraction</h3>
                        <p>Lists all Set-Cookie headers separately</p>
                    </div>
                    <div class="example">
                        <h3>✓ Body Analysis</h3>
                        <p>Detects content type and formats accordingly</p>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2>Usage Examples</h2>
                <div class="response">
                    <div class="header-line">// Save response to php.php in autosh.php:</div>
                    <div class="header-line">file_put_contents('php.php', $response);</div>
                    <br>
                    <div class="header-line">// View in browser:</div>
                    <div class="header-line">https://yoursite.com/php.php?view=1</div>
                    <br>
                    <div class="header-line">// Or paste response here and analyze</div>
                </div>
            </div>
        </div>
        
        <script>
            function fillExample() {
                document.querySelector('[name="response_data"]').value = 
`HTTP/2 200
content-type: application/json
date: ${new Date().toUTCString()}
set-cookie: session=abc123; Path=/; HttpOnly
x-request-id: req_abc123

{
    "success": true,
    "status": "LIVE",
    "card": "411111******1111",
    "gateway": "Shopify Payments",
    "message": "Card accepted"
}`;
            }
            
            function clearData() {
                document.querySelector('[name="response_data"]').value = '';
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Function to format and display response
function displayResponse($response_data) {
    // Separate headers and body
    $parts = explode("\r\n\r\n", $response_data, 2);
    if (count($parts) < 2) {
        $parts = explode("\n\n", $response_data, 2);
    }
    
    $headers_text = $parts[0] ?? '';
    $body = $parts[1] ?? $response_data;
    
    // Parse headers
    $headers = [];
    $status_line = '';
    $lines = explode("\n", $headers_text);
    
    foreach ($lines as $i => $line) {
        $line = trim($line);
        if ($i === 0 && (strpos($line, 'HTTP/') === 0 || strpos($line, 'HTTP2') === 0)) {
            $status_line = $line;
        } elseif (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $headers[trim($key)] = trim($value);
        }
    }
    
    // Extract cookies
    $cookies = [];
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'set-cookie') {
            $cookies[] = $value;
        }
    }
    
    // Detect content type
    $content_type = $headers['content-type'] ?? $headers['Content-Type'] ?? 'text/plain';
    $is_json = stripos($content_type, 'json') !== false;
    
    // Format body
    $formatted_body = $body;
    if ($is_json) {
        $json_data = json_decode($body, true);
        if ($json_data !== null) {
            $formatted_body = json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>🔍 Response Analysis</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Courier New', monospace;
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 20px;
            }
            .container {
                max-width: 1400px;
                margin: 0 auto;
            }
            .header {
                background: #252526;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 20px;
                border-left: 4px solid #4ec9b0;
            }
            h1 {
                color: #4ec9b0;
                font-size: 20px;
                margin-bottom: 5px;
            }
            .section {
                background: #252526;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .section h2 {
                color: #4ec9b0;
                font-size: 16px;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #3c3c3c;
            }
            .status-line {
                font-size: 18px;
                font-weight: bold;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 15px;
            }
            .status-200 { background: rgba(78, 201, 176, 0.2); color: #4ec9b0; }
            .status-300 { background: rgba(156, 220, 254, 0.2); color: #9cdcfe; }
            .status-400 { background: rgba(206, 145, 120, 0.2); color: #ce9178; }
            .status-500 { background: rgba(244, 135, 113, 0.2); color: #f48771; }
            .header-line {
                padding: 5px 0;
                border-bottom: 1px solid #2d2d30;
            }
            .header-key {
                color: #9cdcfe;
                display: inline-block;
                width: 200px;
            }
            .header-value {
                color: #ce9178;
            }
            .cookie {
                background: #1e1e1e;
                padding: 8px 12px;
                border-radius: 4px;
                margin-bottom: 8px;
                border-left: 3px solid #dcdcaa;
                font-size: 11px;
            }
            pre {
                background: #1e1e1e;
                padding: 20px;
                border-radius: 4px;
                overflow-x: auto;
                border: 1px solid #3c3c3c;
                line-height: 1.5;
            }
            .json-key { color: #9cdcfe; }
            .json-value { color: #ce9178; }
            .json-number { color: #b5cea8; }
            .json-boolean { color: #569cd6; }
            .json-null { color: #569cd6; }
            .btn {
                background: #0e639c;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                margin-right: 10px;
            }
            .btn:hover {
                background: #1177bb;
            }
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            .stat {
                background: #1e1e1e;
                padding: 15px;
                border-radius: 4px;
                text-align: center;
            }
            .stat-value {
                font-size: 24px;
                font-weight: bold;
                color: #4ec9b0;
            }
            .stat-label {
                font-size: 11px;
                color: #858585;
                margin-top: 5px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔍 HTTP Response Analysis</h1>
                <div style="margin-top: 10px;">
                    <a href="?" class="btn">← Back</a>
                    <button class="btn" onclick="window.print()">🖨️ Print</button>
                </div>
            </div>
            
            <?php if ($status_line): ?>
            <div class="section">
                <div class="status-line status-<?= substr($status_line, strpos($status_line, ' ') + 1, 1) ?>00">
                    <?= htmlspecialchars($status_line) ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?= count($headers) ?></div>
                    <div class="stat-label">Headers</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= count($cookies) ?></div>
                    <div class="stat-label">Cookies</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= number_format(strlen($body)) ?></div>
                    <div class="stat-label">Body Bytes</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= $is_json ? 'JSON' : 'TEXT' ?></div>
                    <div class="stat-label">Content Type</div>
                </div>
            </div>
            
            <?php if (!empty($headers)): ?>
            <div class="section">
                <h2>📋 Response Headers</h2>
                <?php foreach ($headers as $key => $value): ?>
                    <?php if (strtolower($key) !== 'set-cookie'): ?>
                    <div class="header-line">
                        <span class="header-key"><?= htmlspecialchars($key) ?>:</span>
                        <span class="header-value"><?= htmlspecialchars($value) ?></span>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($cookies)): ?>
            <div class="section">
                <h2>🍪 Cookies (<?= count($cookies) ?>)</h2>
                <?php foreach ($cookies as $cookie): ?>
                <div class="cookie"><?= htmlspecialchars($cookie) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="section">
                <h2>📄 Response Body<?= $is_json ? ' (JSON)' : '' ?></h2>
                <pre><?= htmlspecialchars($formatted_body) ?></pre>
            </div>
            
            <?php if ($is_json && isset($json_data)): ?>
            <div class="section">
                <h2>🔍 JSON Structure</h2>
                <div style="background: #1e1e1e; padding: 15px; border-radius: 4px;">
                    <?php
                    function displayJsonStructure($data, $level = 0) {
                        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
                        if (is_array($data)) {
                            echo $indent . '<span style="color: #9cdcfe;">[Array with ' . count($data) . ' items]</span><br>';
                            foreach ($data as $key => $value) {
                                echo $indent . '&nbsp;&nbsp;<span style="color: #dcdcaa;">' . htmlspecialchars($key) . '</span>: ';
                                if (is_array($value) || is_object($value)) {
                                    echo '<br>';
                                    displayJsonStructure($value, $level + 1);
                                } else {
                                    echo '<span style="color: #ce9178;">' . gettype($value) . '</span><br>';
                                }
                            }
                        } elseif (is_object($data)) {
                            $props = get_object_vars($data);
                            echo $indent . '<span style="color: #9cdcfe;">{Object with ' . count($props) . ' properties}</span><br>';
                            foreach ($props as $key => $value) {
                                echo $indent . '&nbsp;&nbsp;<span style="color: #dcdcaa;">' . htmlspecialchars($key) . '</span>: ';
                                if (is_array($value) || is_object($value)) {
                                    echo '<br>';
                                    displayJsonStructure($value, $level + 1);
                                } else {
                                    echo '<span style="color: #ce9178;">' . gettype($value) . '</span><br>';
                                }
                            }
                        }
                    }
                    displayJsonStructure($json_data);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

// Handle POST request
if (isset($_POST['response_data'])) {
    displayResponse($_POST['response_data']);
    exit;
}

// Handle file viewing
if (isset($_GET['view'])) {
    $file_content = file_get_contents(__FILE__);
    displayResponse($file_content);
    exit;
}

// If included and response data is provided via function
if (isset($GLOBALS['_php_response_data'])) {
    displayResponse($GLOBALS['_php_response_data']);
    exit;
}

// Fallback: show content if accessed as data file
if (file_exists(__FILE__) && filesize(__FILE__) > 1000) {
    $content = file_get_contents(__FILE__);
    // Check if it looks like HTTP response
    if (strpos($content, 'HTTP/') === 0 || strpos($content, 'content-type:') !== false) {
        displayResponse($content);
        exit;
    }
}
