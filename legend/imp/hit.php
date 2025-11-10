<?php
/**
 * HIT.PHP - Advanced CC Checker with autosh.php payment system.
 *
 * Features:
 * - Delegates gateway detection and payment flows to autosh.php via autosh_runner.php
 * - Supports bulk CC checks with proxy rotation
 * - JSON API output via ?format=json
 * - Rich HTML UI with debug mode (?debug=1) to inspect raw responses
 */

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(300);

function countProxies(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return 0;
    }
    return count($lines);
}

function getCustomerDetails(array $input): array
{
    $required = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'street_address',
        'city',
        'state',
        'postal_code',
        'country',
        'currency',
    ];

    $missing = [];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        return [
            'error' => 'Missing required fields: ' . implode(', ', $missing),
        ];
    }

    $country = strtoupper(substr(trim((string)$input['country']), 0, 2));
    if ($country === '') {
        $country = 'US';
    }

    $currency = strtoupper(trim((string)$input['currency']));
    if ($currency === '') {
        $currency = 'USD';
    }

    $firstName = trim((string)$input['first_name']);
    $lastName = trim((string)$input['last_name']);

    return [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => trim((string)$input['email']),
        'phone' => trim((string)$input['phone']),
        'cardholder_name' => isset($input['cardholder_name']) && trim((string)$input['cardholder_name']) !== ''
            ? trim((string)$input['cardholder_name'])
            : trim($firstName . ' ' . $lastName),
        'street_address' => trim((string)$input['street_address']),
        'city' => trim((string)$input['city']),
        'state' => strtoupper(trim((string)$input['state'])),
        'postal_code' => trim((string)$input['postal_code']),
        'country' => $country,
        'currency' => $currency,
    ];
}

function normalizeSite(?string $siteInput): array
{
    $siteInput = trim((string)$siteInput);
    if ($siteInput === '') {
        return ['site' => '', 'error' => 'Site URL is required.'];
    }

    $site = $siteInput;
    if (!preg_match('#^https?://#i', $site)) {
        $site = 'https://' . $site;
    }

    if (!filter_var($site, FILTER_VALIDATE_URL)) {
        return ['site' => '', 'error' => 'Invalid site URL provided.'];
    }

    return ['site' => $site, 'error' => null];
}

function parseCardList(?string $input): array
{
    if (!is_string($input)) {
        return [];
    }

    $input = trim($input);
    if ($input === '') {
        return [];
    }

    $lines = preg_split('/[\r\n;]+/', $input);
    if ($lines === false) {
        return [];
    }

    $cards = [];
    foreach ($lines as $line) {
        $card = parseCardLine($line);
        if ($card !== null) {
            $cards[] = $card;
        }
    }

    return $cards;
}

function parseCardLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $parts = explode('|', $line);
    if (count($parts) < 4) {
        return null;
    }

    $number = preg_replace('/\D+/', '', $parts[0]);
    $monthDigits = preg_replace('/\D+/', '', $parts[1]);
    $yearDigits = preg_replace('/\D+/', '', $parts[2]);
    $cvv = preg_replace('/\D+/', '', $parts[3]);

    if ($number === '' || $monthDigits === '' || $yearDigits === '' || $cvv === '') {
        return null;
    }

    $month = str_pad(substr($monthDigits, 0, 2), 2, '0', STR_PAD_LEFT);
    $monthInt = (int)$month;
    if ($monthInt < 1 || $monthInt > 12) {
        return null;
    }

    if (strlen($yearDigits) === 2) {
        $year = '20' . $yearDigits;
    } elseif (strlen($yearDigits) === 4) {
        $year = substr($yearDigits, 0, 4);
    } else {
        return null;
    }

    return [
        'raw' => $line,
        'number' => $number,
        'month' => $month,
        'year' => $year,
        'cvv' => $cvv,
        'brand' => detectBrand($number),
        'masked' => maskCardNumber($number),
        'luhn_valid' => validateLuhn($number),
    ];
}

function detectBrand(string $number): string
{
    if (preg_match('/^4/', $number)) {
        return 'Visa';
    }
    if (preg_match('/^5[1-5]/', $number)) {
        return 'Mastercard';
    }
    if (preg_match('/^3[47]/', $number)) {
        return 'Amex';
    }
    if (preg_match('/^6(?:011|5|4[4-9]|22)/', $number)) {
        return 'Discover';
    }
    if (preg_match('/^35/', $number)) {
        return 'JCB';
    }
    return 'Unknown';
}

function validateLuhn(string $number): bool
{
    $number = preg_replace('/\D+/', '', $number);
    $sum = 0;
    $alt = false;

    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }

    return $sum % 10 === 0;
}

function maskCardNumber(string $number): string
{
    $length = strlen($number);
    if ($length <= 10) {
        return $number;
    }

    $maskLength = $length - 10;
    return substr($number, 0, 6) . str_repeat('*', max(0, $maskLength)) . substr($number, -4);
}

function buildCustomerSummary(array $customer): array
{
    return [
        'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
        'email' => $customer['email'] ?? '',
        'phone' => $customer['phone'] ?? '',
        'address' => $customer['street_address'] ?? '',
        'country' => $customer['country'] ?? '',
    ];
}

function buildInvalidResult(array $card, array $customer, string $site): array
{
    return [
        'success' => false,
        'status' => 'INVALID',
        'card' => $card['masked'],
        'brand' => $card['brand'],
        'message' => 'Card failed Luhn validation. Skipped autosh payment flow.',
        'gateway' => 'Not attempted',
        'response_time' => 0,
        'site' => $site,
        'customer' => buildCustomerSummary($customer),
    ];
}

function buildPendingResult(array $card, array $customer, string $site, array $errors): array
{
    $message = trim(implode(' | ', array_filter(array_map('strval', $errors))));
    if ($message === '') {
        $message = 'Request not executed.';
    }

    return [
        'success' => false,
        'status' => 'ERROR',
        'card' => $card['masked'],
        'brand' => $card['brand'],
        'message' => $message,
        'gateway' => 'Not attempted',
        'response_time' => 0,
        'site' => $site,
        'customer' => buildCustomerSummary($customer),
    ];
}

function buildAutoshParameters(array $card, array $customer, string $site, array $options): array
{
    $params = [
        'cc' => sprintf('%s|%s|%s|%s', $card['number'], $card['month'], $card['year'], $card['cvv']),
        'site' => $site,
        'rotate' => !empty($options['rotate']) ? '1' : '0',
        'format' => 'json_pretty',
        'pretty' => '1',
        'first_name' => $customer['first_name'],
        'last_name' => $customer['last_name'],
        'email' => $customer['email'],
        'phone' => $customer['phone'],
        'street_address' => $customer['street_address'],
        'city' => $customer['city'],
        'state' => $customer['state'],
        'postal_code' => $customer['postal_code'],
        'country' => $customer['country'],
        'currency' => $customer['currency'],
    ];

    if (!empty($customer['cardholder_name'])) {
        $params['cardholder_name'] = $customer['cardholder_name'];
    }

    $passthroughKeys = [
        'proxy',
        'rotate_ua',
        'rotateSession',
        'cto',
        'to',
        'sleep',
        'rate_limit_detection',
        'auto_rotate_rate_limit',
        'rate_limit_cooldown',
        'max_rate_limit_retries',
        'requireProxy',
        'noproxy',
    ];

    $input = $options['input'] ?? [];
    foreach ($passthroughKeys as $key) {
        if (isset($input[$key]) && $input[$key] !== '') {
            $params[$key] = $input[$key];
        }
    }

    if (!empty($options['debug'])) {
        $params['debug'] = '1';
    }

    return $params;
}

function executeAutosh(string $runnerPath, array $params, bool $debug): array
{
    $phpBinary = PHP_BINARY ?: 'php';
    $query = http_build_query($params);
    $command = sprintf(
        '%s %s %s',
        escapeshellarg($phpBinary),
        escapeshellarg($runnerPath),
        escapeshellarg($query)
    );

    $workingDir = dirname($runnerPath);
    $start = microtime(true);
    $adapter = 'proc_open';
    $stdout = '';
    $stderr = '';
    $exitCode = null;

    if (function_exists('proc_open')) {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes, $workingDir);

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } else {
            $adapter = 'shell_exec';
        }
    } else {
        $adapter = 'shell_exec';
    }

    if ($adapter === 'shell_exec') {
        if (!function_exists('shell_exec')) {
            return [
                'type' => 'error',
                'error' => 'Process execution is disabled (proc_open and shell_exec unavailable).',
                'params' => $params,
                'adapter' => null,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
        $stdout = (string) shell_exec(sprintf('%s 2>&1', $command));
        $stderr = '';
        $exitCode = null;
    }

    $durationMs = (int) round((microtime(true) - $start) * 1000);
    $decoded = decodeJsonFlexible((string) $stdout);

    if (!is_array($decoded)) {
        $errorMessage = 'Unable to parse response from autosh runner.';
        if (trim($stderr) !== '') {
            $errorMessage .= ' ' . trim($stderr);
        }

        return [
            'type' => 'error',
            'error' => $errorMessage,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'adapter' => $adapter,
            'duration_ms' => $durationMs,
            'command' => $debug ? $command : null,
        ];
    }

    return [
        'type' => 'success',
        'data' => $decoded,
        'stdout' => $debug ? $stdout : null,
        'stderr' => $debug ? $stderr : null,
        'exit_code' => $exitCode,
        'adapter' => $adapter,
        'duration_ms' => $durationMs,
        'command' => $debug ? $command : null,
    ];
}

function decodeJsonFlexible(string $output): ?array
{
    $output = trim($output);
    if ($output === '') {
        return null;
    }

    $decoded = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($output, '{');
    $end = strrpos($output, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $slice = substr($output, $start, $end - $start + 1);
        $decoded = json_decode($slice, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function deriveStatusFromAutosh(array $raw): string
{
    $keys = ['status', 'Status', 'result', 'Result'];
    foreach ($keys as $key) {
        if (isset($raw[$key]) && is_string($raw[$key])) {
            $value = strtoupper($raw[$key]);
            if (strpos($value, 'LIVE') !== false || strpos($value, 'APPROV') !== false || strpos($value, 'SUCCESS') !== false) {
                return 'LIVE';
            }
            if (strpos($value, 'DECLINE') !== false || strpos($value, 'FAIL') !== false) {
                return 'DECLINED';
            }
            if (strpos($value, 'ERROR') !== false) {
                return 'ERROR';
            }
        }
    }

    $message = strtoupper(extractMessageFromAutosh($raw));
    $positiveTokens = ['APPROVED', 'SUCCESS', 'CAPTURED', 'LIVE', 'AUTH'];
    foreach ($positiveTokens as $token) {
        if ($message !== '' && strpos($message, $token) !== false) {
            return 'LIVE';
        }
    }

    $declineTokens = ['DECLINED', 'DO NOT HONOR', 'INSUFFICIENT', 'REJECT', 'STOLEN', 'LOST', 'INVALID', 'UNABLE', 'FAIL'];
    foreach ($declineTokens as $token) {
        if ($message !== '' && strpos($message, $token) !== false) {
            return 'DECLINED';
        }
    }

    if ($message !== '') {
        return 'DETECTED';
    }

    return 'ERROR';
}

function extractMessageFromAutosh(array $raw): string
{
    $candidates = ['Response', 'message', 'Message', 'error', 'Error', 'notice', 'Notice'];
    foreach ($candidates as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $value = $raw[$key];
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        } elseif (is_array($value) && !empty($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            if (is_string($json) && $json !== '[]' && $json !== '{}') {
                return $json;
            }
        }
    }

    if (isset($raw['gateway']['primary']['status']) && is_string($raw['gateway']['primary']['status'])) {
        $status = trim($raw['gateway']['primary']['status']);
        if ($status !== '') {
            return $status;
        }
    }

    return 'No response message received from autosh.';
}

function extractGatewayFromAutosh(array $raw): string
{
    if (isset($raw['Gateway']) && is_string($raw['Gateway']) && trim($raw['Gateway']) !== '') {
        return trim($raw['Gateway']);
    }
    if (isset($raw['gateway']['primary']['label']) && is_string($raw['gateway']['primary']['label'])) {
        $label = trim($raw['gateway']['primary']['label']);
        if ($label !== '') {
            return $label;
        }
    }
    if (isset($raw['gateway']['primary']['name']) && is_string($raw['gateway']['primary']['name'])) {
        $name = trim($raw['gateway']['primary']['name']);
        if ($name !== '') {
            return $name;
        }
    }
    return 'Unknown';
}

function mapAutoshResult(array $card, array $customer, string $site, array $execution, bool $debug): array
{
    $result = [
        'success' => false,
        'status' => 'ERROR',
        'card' => $card['masked'],
        'brand' => $card['brand'],
        'message' => '',
        'gateway' => 'Unknown',
        'response_time' => $execution['duration_ms'] ?? 0,
        'site' => $site,
        'customer' => buildCustomerSummary($customer),
    ];

    if ($execution['type'] !== 'success') {
        $result['message'] = $execution['error'] ?? 'Failed to execute autosh payment system.';
        if ($debug) {
            $result['runner'] = [
                'stdout' => $execution['stdout'] ?? null,
                'stderr' => $execution['stderr'] ?? null,
                'exit_code' => $execution['exit_code'] ?? null,
                'adapter' => $execution['adapter'] ?? null,
                'command' => $execution['command'] ?? null,
                'duration_ms' => $execution['duration_ms'] ?? null,
            ];
        }
        return $result;
    }

    $raw = $execution['data'];
    $status = deriveStatusFromAutosh($raw);
    $message = extractMessageFromAutosh($raw);
    $gateway = extractGatewayFromAutosh($raw);
    $proxy = null;

    if (isset($raw['proxy']['string']) && $raw['proxy']['string'] !== null) {
        $proxy = $raw['proxy']['string'];
    } elseif (isset($raw['proxy']['ip']) && $raw['proxy']['ip'] !== null) {
        $proxy = $raw['proxy']['ip'];
    }

    $responseTime = $raw['_meta']['duration_ms'] ?? ($execution['duration_ms'] ?? 0);

    $result['status'] = strtoupper($status);
    $result['message'] = $message;
    $result['gateway'] = $gateway;
    $result['response_time'] = (int) round($responseTime);
    $result['success'] = strtoupper($status) === 'LIVE';

    if ($proxy) {
        $result['proxy_used'] = $proxy;
    }

    if ($debug) {
        $result['autosh_raw'] = $raw;
        $result['runner'] = [
            'stdout' => $execution['stdout'] ?? null,
            'stderr' => $execution['stderr'] ?? null,
            'exit_code' => $execution['exit_code'] ?? null,
            'adapter' => $execution['adapter'] ?? null,
            'command' => $execution['command'] ?? null,
            'duration_ms' => $execution['duration_ms'] ?? null,
        ];
    }

    return $result;
}

$bootStart = microtime(true);
$baseDir = __DIR__;
$runnerPath = $baseDir . '/autosh_runner.php';

$input = array_merge($_GET, $_POST);
$outputFormat = isset($input['format']) ? strtolower((string)$input['format']) : 'html';
$debug = isset($input['debug']) && $input['debug'] !== '0' && $input['debug'] !== 'false';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestSubmitted = $requestMethod === 'POST' || isset($input['cc']) || isset($input['site']);

$proxyCount = countProxies($baseDir . '/ProxyList.txt');

$customer = getCustomerDetails($input);
$siteData = normalizeSite($input['site'] ?? '');
$siteUrl = $siteData['site'];
$siteError = $siteData['error'];

$cards = parseCardList($input['cc'] ?? '');

$rotateProxy = !isset($input['rotate']) || (string)$input['rotate'] !== '0';
$autoshAvailable = is_file($runnerPath);

$aggregateErrors = [];
if (!$autoshAvailable) {
    $aggregateErrors[] = 'autosh_runner.php not found in the same directory as hit.php.';
}

if ($requestSubmitted) {
    if ($siteUrl === '') {
        $aggregateErrors[] = $siteError ?? 'Site URL is required.';
    }
    if (!empty($customer['error'])) {
        $aggregateErrors[] = $customer['error'];
    }
    if (empty($cards)) {
        $aggregateErrors[] = 'No valid credit card entries detected.';
    }
}

$aggregateErrors = array_values(array_filter(array_unique($aggregateErrors)));

$results = [];
if ($autoshAvailable && empty($aggregateErrors) && $siteUrl !== '' && !empty($cards)) {
    foreach ($cards as $card) {
        if (!$card['luhn_valid']) {
            $results[] = buildInvalidResult($card, $customer, $siteUrl);
            continue;
        }

        $params = buildAutoshParameters($card, $customer, $siteUrl, [
            'rotate' => $rotateProxy,
            'debug' => $debug,
            'input' => $input,
        ]);

        $execution = executeAutosh($runnerPath, $params, $debug);
        $results[] = mapAutoshResult($card, $customer, $siteUrl, $execution, $debug);

        if (count($cards) > 1) {
            usleep(200000); // 200ms delay between cards
        }
    }
} elseif ($requestSubmitted && !empty($aggregateErrors) && !empty($cards)) {
    foreach ($cards as $card) {
        $results[] = buildPendingResult($card, $customer, $siteUrl, $aggregateErrors);
    }
}

$totalExecutionTime = (int) round((microtime(true) - $bootStart) * 1000);

$selectedCountry = strtoupper($input['country'] ?? 'US');
$selectedCurrency = strtoupper($input['currency'] ?? 'USD');
$selectedRotate = isset($input['rotate']) ? (string)$input['rotate'] : '1';
$selectedOutput = $outputFormat;

if ($outputFormat === 'json') {
    header('Content-Type: application/json');
    $response = [
        'success' => empty($aggregateErrors) && !empty($results),
        'errors' => $aggregateErrors,
        'count' => count($results),
        'site' => $siteUrl,
        'rotate_proxy' => $rotateProxy,
        'proxies_loaded' => $proxyCount,
        'results' => $results,
        'customer' => empty($customer['error']) ? $customer : null,
        'execution_time_ms' => $totalExecutionTime,
        'timestamp' => gmdate('c'),
        'debug' => $debug,
    ];
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💳 HIT - Autosh Gateway CC Checker</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #1e293b;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #4338ca;
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .subtitle { color: #64748b; font-size: 16px; line-height: 1.6; }
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .card h2 {
            color: #1e293b;
            margin-bottom: 20px;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #334155;
            font-size: 14px;
        }
        label small { font-weight: 400; color: #94a3b8; }
        input, textarea, select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #6366f1;
        }
        textarea { min-height: 80px; font-family: 'Courier New', monospace; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .btn {
            background: linear-gradient(135deg, #6366f1, #4338ca);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-secondary { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .alert {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #991b1b;
            font-weight: 600;
        }
        .alert ul {
            margin: 10px 0 0;
            padding-left: 20px;
        }
        .alert ul li {
            font-weight: 400;
            margin-bottom: 6px;
        }
        .alert-info {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #1e40af;
            font-weight: 600;
        }
        .result-card {
            background: #f8fafc;
            border-left: 4px solid #94a3b8;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        .result-card.success { border-left-color: #10b981; background: #ecfdf5; }
        .result-card.declined { border-left-color: #ef4444; background: #fef2f2; }
        .result-card.error { border-left-color: #f59e0b; background: #fffbeb; }
        .result-item {
            padding: 6px 0;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }
        .result-item:last-child { border-bottom: none; }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-live { background: #10b981; color: white; }
        .status-invalid { background: #f97316; color: white; }
        .status-declined { background: #ef4444; color: white; }
        .status-detected { background: #3b82f6; color: white; }
        .status-error { background: #f59e0b; color: white; }
        .status-pending { background: #6366f1; color: white; }
        .help-text { font-size: 12px; color: #64748b; margin-top: 5px; }
        details.debug {
            background: #1e293b;
            color: #e2e8f0;
            padding: 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-top: 10px;
        }
        details.debug summary {
            cursor: pointer;
            font-weight: 600;
            color: #38bdf8;
        }
        details.debug pre {
            max-height: 260px;
            overflow: auto;
            margin-top: 10px;
            background: rgba(15, 23, 42, 0.8);
            padding: 12px;
            border-radius: 6px;
            color: #f8fafc;
        }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><span>💳</span> HIT - Autosh Gateway CC Checker</h1>
            <p class="subtitle">
                Real gateway integration powered by autosh.php. Calls the autosh runner per card,
                forwarding your customer data and proxy controls. Supports JSON output via <code>?format=json</code> and
                debug traces via <code>?debug=1</code>.
            </p>
        </div>

        <?php if ($requestSubmitted && !empty($aggregateErrors)): ?>
        <div class="alert">
            <strong>⚠️ Issues detected:</strong>
            <ul>
                <?php foreach ($aggregateErrors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
        <div class="card">
            <h2>📊 Check Results</h2>
            <?php foreach ($results as $result): ?>
            <?php
                $statusClass = strtolower($result['status']);
                if ($statusClass === 'live') {
                    $cardClass = 'result-card success';
                } elseif ($statusClass === 'declined') {
                    $cardClass = 'result-card declined';
                } elseif ($statusClass === 'invalid') {
                    $cardClass = 'result-card error';
                } else {
                    $cardClass = 'result-card error';
                }
            ?>
            <div class="<?= $cardClass; ?>">
                <div style="font-weight: 700; margin-bottom: 10px;">
                    Card: <?= htmlspecialchars($result['card'], ENT_QUOTES); ?>
                    <span class="status-badge status-<?= htmlspecialchars($statusClass, ENT_QUOTES); ?>">
                        <?= htmlspecialchars($result['status'], ENT_QUOTES); ?>
                    </span>
                </div>
                <div class="result-item"><strong>Brand:</strong> <span><?= htmlspecialchars($result['brand'], ENT_QUOTES); ?></span></div>
                <div class="result-item"><strong>Gateway:</strong> <span><?= htmlspecialchars($result['gateway'], ENT_QUOTES); ?></span></div>
                <div class="result-item"><strong>Message:</strong> <span><?= htmlspecialchars($result['message'], ENT_QUOTES); ?></span></div>
                <div class="result-item"><strong>Customer:</strong> <span><?= htmlspecialchars($result['customer']['name'] ?? '', ENT_QUOTES); ?></span></div>
                <div class="result-item"><strong>Response Time:</strong> <span><?= (int)($result['response_time'] ?? 0); ?>ms</span></div>
                <?php if (!empty($result['proxy_used'])): ?>
                <div class="result-item"><strong>Proxy:</strong> <span><?= htmlspecialchars($result['proxy_used'], ENT_QUOTES); ?></span></div>
                <?php endif; ?>
                <?php if ($debug && isset($result['autosh_raw'])): ?>
                <details class="debug">
                    <summary>Autosh Raw Response</summary>
                    <pre><?= htmlspecialchars(json_encode($result['autosh_raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES); ?></pre>
                </details>
                <?php endif; ?>
                <?php if ($debug && isset($result['runner'])): ?>
                <details class="debug">
                    <summary>Runner Diagnostics</summary>
                    <pre><?= htmlspecialchars(json_encode(array_filter($result['runner'], static fn($value) => $value !== null && $value !== ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES); ?></pre>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($requestSubmitted && empty($aggregateErrors)): ?>
        <div class="card">
            <h2>📊 Check Results</h2>
            <p>No results yet. Provide credit cards to begin.</p>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>🚀 Check Credit Cards (Powered by autosh.php)</h2>
            <div class="alert-info" style="margin-bottom: 20px;">
                <strong>⚡ Highlights:</strong> autosh.php gateway engine • CLI integration via autosh_runner.php • Proxy rotation ready • <?= $proxyCount; ?> proxies detected
            </div>

            <form method="POST" action="">
                <div class="form-group">
                    <label>💳 Credit Card(s) <small>(Format: number|month|year|cvv)</small></label>
                    <textarea name="cc" placeholder="4111111111111111|12|2027|123" required><?= htmlspecialchars($input['cc'] ?? '', ENT_QUOTES); ?></textarea>
                </div>

                <div style="background: #f8fafc; padding: 20px; border-radius: 12px; margin: 20px 0;">
                    <h3 style="margin-bottom: 15px; color: #1e293b;">📋 Customer Information (All Required)</h3>
                    <div class="grid">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" placeholder="John" value="<?= htmlspecialchars($input['first_name'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" placeholder="Smith" value="<?= htmlspecialchars($input['last_name'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" placeholder="john@gmail.com" value="<?= htmlspecialchars($input['email'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone *</label>
                            <input type="tel" name="phone" placeholder="+12125551234" value="<?= htmlspecialchars($input['phone'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Cardholder Name <small>(Optional override)</small></label>
                        <input type="text" name="cardholder_name" placeholder="John Smith" value="<?= htmlspecialchars($input['cardholder_name'] ?? '', ENT_QUOTES); ?>">
                    </div>
                    <div class="form-group">
                        <label>Street Address *</label>
                        <input type="text" name="street_address" placeholder="350 5th Ave" value="<?= htmlspecialchars($input['street_address'] ?? '', ENT_QUOTES); ?>" required>
                    </div>
                    <div class="grid">
                        <div class="form-group">
                            <label>City *</label>
                            <input type="text" name="city" placeholder="New York" value="<?= htmlspecialchars($input['city'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>State *</label>
                            <input type="text" name="state" placeholder="NY" value="<?= htmlspecialchars($input['state'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Postal Code *</label>
                            <input type="text" name="postal_code" placeholder="10118" value="<?= htmlspecialchars($input['postal_code'] ?? '', ENT_QUOTES); ?>" required>
                        </div>
                    </div>
                    <div class="grid">
                        <div class="form-group">
                            <label>Country *</label>
                            <select name="country" required>
                                <?php
                                $countryOptions = [
                                    'US' => 'United States',
                                    'CA' => 'Canada',
                                    'GB' => 'United Kingdom',
                                    'AU' => 'Australia',
                                ];
                                foreach ($countryOptions as $code => $label) {
                                    $selected = $selectedCountry === $code ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($code, ENT_QUOTES) . '" ' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Currency *</label>
                            <select name="currency" required>
                                <?php
                                $currencyOptions = [
                                    'USD' => 'USD',
                                    'EUR' => 'EUR',
                                    'GBP' => 'GBP',
                                    'CAD' => 'CAD',
                                ];
                                foreach ($currencyOptions as $code => $label) {
                                    $selected = $selectedCurrency === $code ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($code, ENT_QUOTES) . '" ' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>🌐 Target Site *</label>
                    <input type="url" name="site" placeholder="https://example.myshopify.com" value="<?= htmlspecialchars($input['site'] ?? '', ENT_QUOTES); ?>" required>
                    <div class="help-text">Autosh handles gateway detection automatically.</div>
                </div>

                <div class="grid">
                    <div class="form-group">
                        <label>Proxy Rotation</label>
                        <select name="rotate">
                            <option value="1" <?= $selectedRotate === '0' ? '' : 'selected'; ?>>Enabled</option>
                            <option value="0" <?= $selectedRotate === '0' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Output</label>
                        <select name="format">
                            <option value="html" <?= $selectedOutput === 'json' ? '' : 'selected'; ?>>HTML</option>
                            <option value="json" <?= $selectedOutput === 'json' ? 'selected' : ''; ?>>JSON</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 20px;">
                    <button type="submit" class="btn">⚡ Check Cards</button>
                    <button type="button" class="btn btn-secondary" onclick="fillTestData()">🧪 Fill Test Data</button>
                </div>
            </form>
        </div>

        <div class="alert-info">
            <strong>ℹ️ Tip:</strong> Append <code>?debug=1</code> for diagnostics or <code>?format=json</code> for API style responses. Execution time: <?= $totalExecutionTime; ?>ms.
        </div>
    </div>

    <script>
        function fillTestData() {
            document.querySelector('[name="cc"]').value = '4111111111111111|12|2027|123';
            document.querySelector('[name="first_name"]').value = 'John';
            document.querySelector('[name="last_name"]').value = 'Smith';
            document.querySelector('[name="cardholder_name"]').value = 'John Smith';
            document.querySelector('[name="email"]').value = 'john.smith@gmail.com';
            document.querySelector('[name="phone"]').value = '+12125551234';
            document.querySelector('[name="street_address"]').value = '350 5th Ave';
            document.querySelector('[name="city"]').value = 'New York';
            document.querySelector('[name="state"]').value = 'NY';
            document.querySelector('[name="postal_code"]').value = '10118';
            document.querySelector('[name="country"]').value = 'US';
            document.querySelector('[name="currency"]').value = 'USD';
            document.querySelector('[name="site"]').value = 'https://example.myshopify.com';
            document.querySelector('[name="rotate"]').value = '1';
            document.querySelector('[name="format"]').value = 'html';
        }
    </script>
</body>
</html>
