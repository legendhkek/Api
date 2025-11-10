<?php
/**
 * Download ProxyList.txt API Endpoint
 * 
 * Usage:
 * - /download_proxies.php - Download full proxy list
 * - /download_proxies.php?format=json - Get JSON format
 * - /download_proxies.php?type=http - Filter by proxy type
 * - /download_proxies.php?limit=50 - Limit number of proxies
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

$proxyFile = __DIR__ . '/ProxyList.txt';

// Check if file exists
if (!file_exists($proxyFile)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Proxy list not found',
        'message' => 'ProxyList.txt does not exist. Run fetch_proxies.php first.'
    ]);
    exit;
}

// Read proxies
$lines = file($proxyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$proxies = [];

foreach ($lines as $line) {
    $line = trim($line);
    if ($line && $line[0] !== '#') {
        $proxies[] = $line;
    }
}

// Parse parameters
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'text';
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : null;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 0;

// Filter by type if specified
if ($type) {
    $proxies = array_filter($proxies, function($proxy) use ($type) {
        return strpos(strtolower($proxy), $type . '://') === 0;
    });
    $proxies = array_values($proxies);
}

// Apply limit if specified
if ($limit > 0 && count($proxies) > $limit) {
    $proxies = array_slice($proxies, 0, $limit);
}

// Return in requested format
if ($format === 'json') {
    header('Content-Type: application/json');
    
    // Parse proxies into detailed format
    $detailed = [];
    foreach ($proxies as $proxy) {
        $parsed = parseProxyString($proxy);
        if ($parsed) {
            $detailed[] = $parsed;
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($proxies),
        'proxies' => $proxies,
        'proxies_detailed' => $detailed,
        'filters' => [
            'type' => $type,
            'limit' => $limit
        ]
    ], JSON_PRETTY_PRINT);
} else {
    // Plain text format - downloadable
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="ProxyList.txt"');
    header('Content-Length: ' . strlen(implode("\n", $proxies)));
    
    echo implode("\n", $proxies);
}

/**
 * Parse proxy string into components
 * 
 * @param string $proxy Proxy string
 * @return array|null Parsed proxy data
 */
function parseProxyString(string $proxy): ?array {
    $proxy = trim($proxy);
    
    // Extract protocol
    $protocol = 'http';
    $rest = $proxy;
    
    if (preg_match('/^(https?|socks[45]h?|socks[45]a?):\/\/(.+)$/i', $proxy, $m)) {
        $protocol = strtolower($m[1]);
        $rest = $m[2];
    }
    
    // Parse host:port or host:port:user:pass
    $parts = explode(':', $rest);
    
    if (count($parts) < 2) {
        return null;
    }
    
    $result = [
        'protocol' => $protocol,
        'host' => $parts[0],
        'port' => (int)$parts[1],
        'full' => $proxy
    ];
    
    if (count($parts) >= 4) {
        $result['username'] = $parts[2] ?? '';
        $result['password'] = $parts[3] ?? '';
        $result['has_auth'] = true;
    } else {
        $result['has_auth'] = false;
    }
    
    return $result;
}
