<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProxyFetcher.php';

const GRAPHQL_OPERATIONS_FILE = __DIR__ . '/graphql/operations.php';

/**
 * Emit a JSON or JSONP response.
 *
 * @param mixed       $payload
 * @param string|null $callback
 * @param int         $status
 * @param array       $headers
 */
function jsonp_respond($payload, ?string $callback = null, int $status = 200, array $headers = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Content-Type: ' . ($callback ? 'application/javascript; charset=utf-8' : 'application/json; charset=utf-8'));
    }

    $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (isset($_GET['pretty'])) {
        $options |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($payload, $options);
    if ($callback) {
        echo sprintf('%s(%s);', $callback, $json);
    } else {
        echo $json;
    }
    exit;
}

/**
 * Load cached GraphQL operations.
 */
function load_operations(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!is_file(GRAPHQL_OPERATIONS_FILE)) {
        return $cache = [];
    }
    $ops = include GRAPHQL_OPERATIONS_FILE;
    if (!is_array($ops)) {
        return $cache = [];
    }
    return $cache = $ops;
}

/**
 * Sanitize JSONP callback name.
 */
function sanitize_callback(?string $callback): ?string
{
    if ($callback === null || $callback === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$.]*$/', $callback)) {
        return null;
    }
    return $callback;
}

try {
    $action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : 'operations';
    $callback = sanitize_callback($_GET['callback'] ?? null);

    switch ($action) {
        case 'operations':
            $operations = load_operations();
            if (empty($operations)) {
                jsonp_respond(['error' => 'operations_not_available'], $callback, 404);
            }
            if (isset($_GET['name'])) {
                $name = trim((string)$_GET['name']);
                if (!isset($operations[$name])) {
                    jsonp_respond(['error' => 'unknown_operation', 'requested' => $name, 'available' => array_keys($operations)], $callback, 404);
                }
                $query = (string)$operations[$name];
                $format = strtolower((string)($_GET['format'] ?? 'json'));
                if ($format === 'graphql' || $format === 'raw') {
                    if (!headers_sent()) {
                        header('Content-Type: text/plain; charset=utf-8');
                        header('Cache-Control: no-store, max-age=0');
                    }
                    echo $query;
                    exit;
                }
                jsonp_respond([
                    'name' => $name,
                    'query' => $query,
                    'hash' => sha1($query),
                    'length' => strlen($query),
                ], $callback);
            } else {
                $summary = [];
                foreach ($operations as $name => $query) {
                    $summary[] = [
                        'name' => $name,
                        'length' => strlen((string)$query),
                        'hash' => sha1((string)$query),
                    ];
                }
                jsonp_respond([
                    'operations' => $summary,
                    'count' => count($summary),
                ], $callback);
            }
            break;

        case 'proxy-pool':
        case 'proxy_pool':
            $fetcher = new ProxyFetcher(__DIR__ . '/ProxyList.txt');
            $proxies = $fetcher->readProxyFile();
            jsonp_respond([
                'count' => count($proxies),
                'proxies' => isset($_GET['include']) && $_GET['include'] === 'all' ? $proxies : array_slice($proxies, 0, 25),
                'file' => 'ProxyList.txt',
            ], $callback);
            break;

        case 'proxy-bootstrap':
        case 'proxy_bootstrap':
            $desired = max(1, (int)($_GET['count'] ?? 20));
            $options = [
                'timeout' => isset($_GET['timeout']) ? (int)$_GET['timeout'] : null,
                'connectTimeout' => isset($_GET['connectTimeout']) ? (int)$_GET['connectTimeout'] : null,
            ];
            if (!empty($_GET['protocols'])) {
                $options['protocols'] = (string)$_GET['protocols'];
            }
            if (!empty($_GET['sources'])) {
                $options['sources'] = (string)$_GET['sources'];
            }
            $fetcher = new ProxyFetcher(__DIR__ . '/ProxyList.txt');
            $working = $fetcher->fetchAndTest($desired, $options);
            $diagnostics = $fetcher->getDiagnostics();
            $persist = isset($_GET['persist']) ? strtolower((string)$_GET['persist']) : 'false';
            if (in_array($persist, ['1', 'true', 'yes'], true) && !empty($working)) {
                $existing = $fetcher->readProxyFile();
                $fetcher->writeProxyFile(array_merge($existing, $working));
            }
            jsonp_respond([
                'requested' => $desired,
                'working' => $working,
                'working_count' => count($working),
                'diagnostics' => $diagnostics,
            ], $callback);
            break;

        case 'test-proxy':
        case 'test_proxy':
            $proxy = trim((string)($_GET['proxy'] ?? ''));
            if ($proxy === '') {
                jsonp_respond(['error' => 'missing_proxy_parameter'], $callback, 400);
            }
            $fetcher = new ProxyFetcher(__DIR__ . '/ProxyList.txt');
            $result = $fetcher->testProxy($proxy);
            jsonp_respond([
                'proxy' => $proxy,
                'result' => $result,
            ], $callback);
            break;

        case 'download':
            $fetcher = new ProxyFetcher(__DIR__ . '/ProxyList.txt');
            $payload = $fetcher->downloadProxyList();
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="ProxyList.txt"');
                header('Cache-Control: no-store, max-age=0');
            }
            echo $payload;
            exit;

        case 'sources':
            jsonp_respond([
                'sources' => ['geonode', 'proxyscrape', 'github_primary', 'github_additional'],
                'protocols' => ['http', 'https', 'socks4', 'socks5'],
            ], $callback);
            break;

        default:
            jsonp_respond([
                'error' => 'unknown_action',
                'action' => $action,
                'available' => ['operations', 'proxy-pool', 'proxy-bootstrap', 'test-proxy', 'download', 'sources'],
            ], $callback, 400);
    }
} catch (Throwable $e) {
    jsonp_respond([
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], sanitize_callback($_GET['callback'] ?? null), 500);
}

