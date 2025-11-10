<?php
declare(strict_types=1);

/**
 * ProxyFetcher
 *
 * Lightweight service that can bootstrap and maintain a pool of working proxies.
 * Designed to run without any external dependencies so that other scripts (autosh.php,
 * jsonp.php, php.php, etc.) can request fresh proxies on-demand.
 *
 * The implementation favours resiliency over raw performance: each source fetch is
 * wrapped in retry logic, results are de-duplicated, and every proxy is actively
 * validated before being persisted to disk.
 */
class ProxyFetcher
{
    private const DEFAULT_PROTOCOLS = ['http', 'https', 'socks4', 'socks5'];
    private const DEFAULT_TEST_URL = 'http://ip-api.com/json';

    /** @var string */
    private $proxyFile;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $maxTimeout;

    /** @var array */
    private $lastDiagnostics = [];

    public function __construct(?string $proxyFile = null, array $options = [])
    {
        $this->proxyFile = $proxyFile ?: __DIR__ . '/../ProxyList.txt';
        $this->connectTimeout = (int)($options['connectTimeout'] ?? 6);
        $this->maxTimeout = (int)($options['timeout'] ?? 15);
    }

    /**
     * Expose most recent diagnostics (fetch attempts, errors, timings).
     */
    public function getDiagnostics(): array
    {
        return $this->lastDiagnostics;
    }

    /**
     * Ensure that at least $minimum proxies are available on disk.
     * Returns the merged list after any refresh attempts.
     */
    public function ensureWorkingPool(int $minimum = 25, array $options = []): array
    {
        $existing = $this->readProxyFile();
        if (count($existing) >= $minimum) {
            return $existing;
        }

        $target = max($minimum - count($existing), (int)($options['target'] ?? $minimum));
        $fresh = $this->fetchAndTest($target, $options);

        if (!empty($fresh)) {
            $merged = $this->mergeAndPersist($existing, $fresh);
            return $merged;
        }

        return $existing;
    }

    /**
     * Fetch proxies from public sources, validate them, and return the working list.
     *
     * @param int   $desiredCount Number of working proxies to return (best-effort).
     * @param array $options      Optional overrides: protocols, sources, timeout, etc.
     * @return array<string> Normalised proxy strings.
     */
    public function fetchAndTest(int $desiredCount = 25, array $options = []): array
    {
        $start = microtime(true);
        $protocols = $this->normaliseProtocols($options['protocols'] ?? self::DEFAULT_PROTOCOLS);
        $sources = $this->normaliseSources($options['sources'] ?? [
            'geonode', 'proxyscrape', 'github_primary', 'github_additional',
        ]);

        $candidateLimit = max($desiredCount * 8, (int)($options['candidateLimit'] ?? 400));
        $timeout = (int)($options['timeout'] ?? $this->maxTimeout);
        $connectTimeout = (int)($options['connectTimeout'] ?? $this->connectTimeout);
        $stopWhenFound = (bool)($options['stopWhenFound'] ?? true);

        $this->lastDiagnostics = [
            'started_at' => date(DATE_ATOM),
            'desired_count' => $desiredCount,
            'candidate_limit' => $candidateLimit,
            'protocols' => $protocols,
            'sources' => $sources,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'tested' => 0,
            'working' => 0,
            'elapsed' => 0,
            'errors' => [],
        ];

        $candidates = [];
        foreach ($sources as $source) {
            $batch = [];
            try {
                $batch = $this->collectFromSource($source, $protocols);
            } catch (Throwable $e) {
                $this->lastDiagnostics['errors'][] = sprintf(
                    '[%s] %s',
                    strtoupper($source),
                    $e->getMessage()
                );
            }

            foreach ($batch as $proxy) {
                $candidates[$proxy] = true;
                if (count($candidates) >= $candidateLimit) {
                    break 2;
                }
            }
        }

        if (empty($candidates)) {
            $this->lastDiagnostics['elapsed'] = round(microtime(true) - $start, 3);
            return [];
        }

        $working = [];
        foreach (array_keys($candidates) as $proxy) {
            $result = $this->testProxy($proxy, $timeout, $connectTimeout);
            $this->lastDiagnostics['tested']++;
            if ($result['working']) {
                $working[] = $proxy;
                $this->lastDiagnostics['working']++;
                if ($stopWhenFound && count($working) >= $desiredCount) {
                    break;
                }
            } else {
                if ($result['error']) {
                    $this->lastDiagnostics['errors'][] = sprintf(
                        '[TEST][%s] %s',
                        $proxy,
                        $result['error']
                    );
                }
            }
        }

        $this->lastDiagnostics['elapsed'] = round(microtime(true) - $start, 3);
        return array_values(array_unique($working));
    }

    /**
     * Test a proxy by calling an IP-echo API.
     *
     * @return array{working:bool, ip?:string, country?:string, city?:string, http_code:int, error:string|null, elapsed:float}
     */
    public function testProxy(string $proxy, int $timeout = 15, ?int $connectTimeout = null): array
    {
        $connectTimeout = $connectTimeout ?? min($timeout, $this->connectTimeout);
        $start = microtime(true);

        $normalised = $this->normaliseProxyString($proxy);
        $scheme = $normalised['scheme'];
        $address = $normalised['address'];
        $auth = $normalised['auth'];

        $ch = curl_init(self::DEFAULT_TEST_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_NODELAY => true,
        ]);

        curl_setopt($ch, CURLOPT_PROXY, $address);
        switch ($scheme) {
            case 'socks4':
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
                break;
            case 'socks4a':
                if (defined('CURLPROXY_SOCKS4A')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4A);
                } else {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
                }
                break;
            case 'socks5h':
                if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                } else {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                }
                break;
            case 'socks5':
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                break;
            case 'https':
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
                break;
            default:
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        if ($auth !== null) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
        }

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = round(microtime(true) - $start, 3);

        if ($body !== false && $httpCode === 200) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                return [
                    'working' => true,
                    'ip' => $json['query'] ?? ($json['ip'] ?? null),
                    'country' => $json['country'] ?? $json['countryCode'] ?? null,
                    'city' => $json['city'] ?? null,
                    'http_code' => $httpCode,
                    'error' => null,
                    'elapsed' => $elapsed,
                ];
            }
        }

        return [
            'working' => false,
            'http_code' => $httpCode,
            'error' => $error ?: 'No response',
            'elapsed' => $elapsed,
        ];
    }

    /**
     * Attempt to download the current proxy list as a string.
     */
    public function downloadProxyList(): string
    {
        $existing = $this->readProxyFile();
        return implode(PHP_EOL, $existing) . PHP_EOL;
    }

    /**
     * Read proxy list file (if present) into normalised array.
     *
     * @return array<string>
     */
    public function readProxyFile(): array
    {
        if (!is_file($this->proxyFile)) {
            return [];
        }

        $lines = file($this->proxyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $result[$this->normaliseProxyNotation($line)] = true;
        }

        return array_keys($result);
    }

    /**
     * Persist proxies to disk (deduped, sorted).
     */
    public function writeProxyFile(array $proxies): void
    {
        if (empty($proxies)) {
            return;
        }
        $normalised = [];
        foreach ($proxies as $proxy) {
            $normalised[$this->normaliseProxyNotation($proxy)] = true;
        }
        $content = implode(PHP_EOL, array_keys($normalised)) . PHP_EOL;
        file_put_contents($this->proxyFile, $content, LOCK_EX);
    }

    /**
     * Merge two proxy lists and persist.
     */
    private function mergeAndPersist(array $existing, array $fresh): array
    {
        $pool = [];
        foreach ($existing as $proxy) {
            $pool[$this->normaliseProxyNotation($proxy)] = true;
        }

        foreach ($fresh as $proxy) {
            $pool[$this->normaliseProxyNotation($proxy)] = true;
        }

        $merged = array_keys($pool);
        sort($merged);
        file_put_contents($this->proxyFile, implode(PHP_EOL, $merged) . PHP_EOL, LOCK_EX);

        return $merged;
    }

    /**
     * Collect proxies from a particular source identifier.
     */
    private function collectFromSource(string $source, array $protocols): array
    {
        switch ($source) {
            case 'geonode':
                return $this->collectFromGeoNode($protocols);
            case 'proxyscrape':
                return $this->collectFromProxyScrape($protocols);
            case 'github_primary':
                return $this->collectFromGithubPrimary($protocols);
            case 'github_additional':
                return $this->collectFromGithubAdditional($protocols);
            default:
                throw new InvalidArgumentException("Unknown proxy source: {$source}");
        }
    }

    private function collectFromGeoNode(array $protocols): array
    {
        $result = [];
        $pages = 2;

        foreach ($protocols as $protocol) {
            $geoProtocol = $protocol === 'https' ? 'http' : $protocol;
            for ($page = 1; $page <= $pages; $page++) {
                $url = sprintf(
                    'https://proxylist.geonode.com/api/proxy-list?protocols=%s&limit=100&page=%d&sort_by=lastChecked&sort_type=desc',
                    urlencode($geoProtocol),
                    $page
                );
                $payload = $this->httpGet($url);
                if ($payload === null) {
                    continue;
                }
                $json = json_decode($payload, true);
                if (!isset($json['data']) || !is_array($json['data'])) {
                    continue;
                }
                foreach ($json['data'] as $row) {
                    if (empty($row['ip']) || empty($row['port'])) {
                        continue;
                    }
                    $protoList = $row['protocols'] ?? [$geoProtocol];
                    foreach ($protoList as $p) {
                        $p = strtolower((string)$p);
                        if (in_array($p, $protocols, true)) {
                            $result[$p . '://' . $row['ip'] . ':' . $row['port']] = true;
                        }
                    }
                }
            }
        }

        return array_keys($result);
    }

    private function collectFromProxyScrape(array $protocols): array
    {
        $base = 'https://api.proxyscrape.com/v3/free-proxy-list/get?request=displayproxies&country=all&format=text&proxy_format=protocolipport';
        $result = [];

        foreach ($protocols as $protocol) {
            $url = $base . '&protocol=' . urlencode($protocol);
            $payload = $this->httpGet($url);
            if ($payload === null) {
                continue;
            }
            $lines = preg_split('/\r?\n/', trim($payload));
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('#^(https?|socks4|socks5)://#i', $line)) {
                    $result[strtolower($line)] = true;
                } elseif (preg_match('#^\d{1,3}(?:\.\d{1,3}){3}:\d+$#', $line)) {
                    $result[$protocol . '://' . $line] = true;
                }
            }
        }

        return array_keys($result);
    }

    private function collectFromGithubPrimary(array $protocols): array
    {
        $map = [
            'http' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt',
            'https' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/https.txt',
            'socks4' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks4.txt',
            'socks5' => 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt',
        ];

        $result = [];
        foreach ($protocols as $protocol) {
            if (!isset($map[$protocol])) {
                continue;
            }
            $payload = $this->httpGet($map[$protocol]);
            if ($payload === null) {
                continue;
            }
            $lines = preg_split('/\r?\n/', trim($payload));
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('#^\d{1,3}(?:\.\d{1,3}){3}:\d+$#', $line)) {
                    $result[$protocol . '://' . $line] = true;
                } elseif (preg_match('#^(https?|socks4|socks5)://#i', $line)) {
                    $result[strtolower($line)] = true;
                }
            }
        }

        return array_keys($result);
    }

    private function collectFromGithubAdditional(array $protocols): array
    {
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

        $result = [];
        foreach ($protocols as $protocol) {
            if (!isset($sources[$protocol])) {
                continue;
            }
            foreach ($sources[$protocol] as $url) {
                $payload = $this->httpGet($url);
                if ($payload === null) {
                    continue;
                }
                $lines = preg_split('/\r?\n/', trim($payload));
                if (!$lines) {
                    continue;
                }
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if (preg_match('#^(https?|socks4|socks5)://#i', $line)) {
                        $result[strtolower($line)] = true;
                    } elseif (preg_match('#^\d{1,3}(?:\.\d{1,3}){3}:\d+$#', $line)) {
                        $result[$protocol . '://' . $line] = true;
                    }
                }
            }
        }

        return array_keys($result);
    }

    private function normaliseProtocols($protocols): array
    {
        if (!is_array($protocols)) {
            $protocols = explode(',', (string)$protocols);
        }

        $normalised = [];
        foreach ($protocols as $protocol) {
            $protocol = strtolower(trim((string)$protocol));
            if ($protocol === '' || $protocol === 'all' || $protocol === '*') {
                $normalised = self::DEFAULT_PROTOCOLS;
                break;
            }
            if ($protocol === 'socks') {
                $normalised[] = 'socks4';
                $normalised[] = 'socks5';
                continue;
            }
            if (in_array($protocol, self::DEFAULT_PROTOCOLS, true)) {
                $normalised[] = $protocol;
            }
        }

        if (empty($normalised)) {
            $normalised = self::DEFAULT_PROTOCOLS;
        }

        return array_values(array_unique($normalised));
    }

    private function normaliseSources($sources): array
    {
        if (!is_array($sources)) {
            $sources = explode(',', (string)$sources);
        }
        $sources = array_map(static function ($source) {
            return strtolower(trim((string)$source));
        }, $sources);

        $allowed = ['geonode', 'proxyscrape', 'github_primary', 'github_additional'];
        $result = array_values(array_intersect($allowed, $sources));

        if (empty($result)) {
            $result = $allowed;
        }

        return $result;
    }

    /**
     * Perform GET with retries and sane headers.
     */
    private function httpGet(string $url, int $retries = 2): ?string
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt <= $retries) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->maxTimeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => '',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/plain,application/json;q=0.9,*/*;q=0.8',
                    'User-Agent: ProxyFetcher/1.1 (+https://github.com/)',
                    'Cache-Control: no-cache',
                ],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body !== false && ($code === 200 || $code === 0)) {
                return $body;
            }
            $lastError = $err ?: ("HTTP $code");
            usleep(150000); // 150ms
        }

        $this->lastDiagnostics['errors'][] = sprintf('[HTTP][%s] %s', $url, $lastError ?: 'Unknown error');
        return null;
    }

    /**
     * Reduce proxy notation to canonical form (scheme://host:port or scheme://host:port:user:pass)
     */
    private function normaliseProxyNotation(string $proxy): string
    {
        $proxy = trim($proxy);
        if ($proxy === '') {
            throw new InvalidArgumentException('Proxy string is empty');
        }

        $parts = parse_url(strpos($proxy, '://') === false ? 'http://' . $proxy : $proxy);
        if ($parts === false || !isset($parts['host']) || !isset($parts['port'])) {
            throw new InvalidArgumentException("Invalid proxy string: {$proxy}");
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $user = $parts['user'] ?? null;
        $pass = $parts['pass'] ?? null;

        $base = sprintf('%s://%s:%d', $scheme, $parts['host'], $parts['port']);
        if ($user !== null && $user !== '') {
            $base .= ':' . $user;
            if ($pass !== null && $pass !== '') {
                $base .= ':' . $pass;
            }
        }

        return $base;
    }

    /**
     * Normalise proxy string for cURL consumption.
     *
     * @return array{scheme:string, address:string, auth:?string}
     */
    private function normaliseProxyString(string $proxy): array
    {
        $proxy = trim($proxy);
        if ($proxy === '') {
            throw new InvalidArgumentException('Proxy string is empty');
        }

        if (strpos($proxy, '://') === false) {
            $proxy = 'http://' . $proxy;
        }

        $parts = parse_url($proxy);
        if ($parts === false || !isset($parts['host']) || !isset($parts['port'])) {
            throw new InvalidArgumentException("Invalid proxy definition: {$proxy}");
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $address = $parts['host'] . ':' . $parts['port'];

        $auth = null;
        if (!empty($parts['user'])) {
            $auth = $parts['user'] . ':' . ($parts['pass'] ?? '');
        }

        return [
            'scheme' => $scheme,
            'address' => $address,
            'auth' => $auth,
        ];
    }
}

