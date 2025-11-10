<?php
declare(strict_types=1);

@set_time_limit(0);
error_reporting(E_ALL & ~E_DEPRECATED);

const AUTOSH_PATH = __DIR__ . '/autosh.php';

if (!file_exists(AUTOSH_PATH)) {
    http_response_code(500);
    echo 'autosh.php not found.';
    exit;
}

function html_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_card_lines(string $input): array
{
    $parts = preg_split('/[\r\n,;]+/', $input);
    $cards = [];
    foreach ($parts as $part) {
        $clean = trim($part);
        if ($clean === '') {
            continue;
        }
        $clean = preg_replace('/\s+/', '', $clean);
        if ($clean === null || $clean === '') {
            continue;
        }
        $cards[] = $clean;
    }
    return array_values(array_unique($cards));
}

function mask_card_number(string $cardLine): string
{
    $parts = explode('|', $cardLine);
    $raw = preg_replace('/\D/', '', $parts[0] ?? '');
    if ($raw === null || $raw === '') {
        return $parts[0] ?? $cardLine;
    }
    $length = strlen($raw);
    if ($length <= 10) {
        return $raw;
    }
    $prefix = substr($raw, 0, 6);
    $suffix = substr($raw, -4);
    return $prefix . str_repeat('*', $length - 10) . $suffix;
}

function normalize_state(string $state): string
{
    $filtered = preg_replace('/[^A-Za-z]/', '', strtoupper($state));
    if ($filtered === null) {
        return '';
    }
    return substr($filtered, 0, 2);
}

function normalize_zip(string $zip): string
{
    $digits = preg_replace('/[^0-9]/', '', $zip);
    if ($digits === null) {
        return '';
    }
    return substr($digits, 0, 10);
}

function run_autosh(array $params): array
{
    $query = http_build_query($params);
    $phpBinary = PHP_BINARY;
    $runCode = 'parse_str($argv[1] ?? "", $_GET); $_REQUEST = $_GET; $_SERVER["REQUEST_METHOD"] = "GET"; include ' . var_export(AUTOSH_PATH, true) . ';';
    $command = [
        $phpBinary,
        '-d',
        'detect_unicode=0',
        '-d',
        'variables_order=EGPCS',
        '-r',
        $runCode,
        $query
    ];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $start = microtime(true);
    $process = proc_open($command, $descriptorSpec, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to spawn autosh process');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $duration = microtime(true) - $start;

    return [
        'stdout' => $stdout !== false ? $stdout : '',
        'stderr' => $stderr !== false ? $stderr : '',
        'exitCode' => $exitCode,
        'duration' => $duration,
        'params' => $params,
    ];
}

$formData = [
    'cc' => $_POST['cc'] ?? '',
    'site' => $_POST['site'] ?? '',
    'addr_number' => $_POST['addr_number'] ?? '',
    'addr_line1' => $_POST['addr_line1'] ?? '',
    'addr_line2' => $_POST['addr_line2'] ?? '',
    'addr_city' => $_POST['addr_city'] ?? '',
    'addr_state' => $_POST['addr_state'] ?? '',
    'addr_zip' => $_POST['addr_zip'] ?? '',
    'addr_phone' => $_POST['addr_phone'] ?? '',
    'addr_first' => $_POST['addr_first'] ?? '',
    'addr_last' => $_POST['addr_last'] ?? '',
    'addr_email' => $_POST['addr_email'] ?? '',
    'proxy' => $_POST['proxy'] ?? '',
    'rotate' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['rotate']) : true,
    'require_proxy' => isset($_POST['require_proxy']) ? (bool)$_POST['require_proxy'] : false,
    'noproxy' => isset($_POST['noproxy']) ? (bool)$_POST['noproxy'] : false,
    'debug' => isset($_POST['debug']) ? (bool)$_POST['debug'] : false,
];

$errors = [];
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ccInput = trim((string)$formData['cc']);
    $site = trim((string)$formData['site']);

    if ($ccInput === '') {
        $errors[] = 'Enter at least one card in the format CC|MM|YYYY|CVV.';
    }

    if ($site === '' || filter_var($site, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Provide a valid checkout URL.';
    }

    $addrLine1 = trim((string)$formData['addr_line1']);
    $addrCity = trim((string)$formData['addr_city']);
    $addrState = normalize_state((string)$formData['addr_state']);
    $addrZip = normalize_zip((string)$formData['addr_zip']);

    if ($addrLine1 === '' || $addrCity === '' || $addrState === '' || $addrZip === '') {
        $errors[] = 'Address fields (street, city, state, ZIP) are required.';
    }

    $cards = $ccInput !== '' ? parse_card_lines($ccInput) : [];
    if (empty($cards) && empty($errors)) {
        $errors[] = 'No valid card entries detected.';
    }

    if (empty($errors)) {
        foreach ($cards as $cardLine) {
            $parts = explode('|', $cardLine);
            if (count($parts) < 4) {
                $errors[] = 'Invalid format for card: ' . html_escape($cardLine);
                continue;
            }
        }
    }

    if (empty($errors)) {
        foreach ($cards as $cardLine) {
            $params = [
                'cc' => $cardLine,
                'site' => $site,
                'addr_line1' => $addrLine1,
                'addr_line2' => trim((string)$formData['addr_line2']),
                'addr_city' => $addrCity,
                'addr_state' => $addrState,
                'addr_zip' => $addrZip,
            ];

            $addrNumber = trim((string)$formData['addr_number']);
            if ($addrNumber !== '') {
                $params['addr_number'] = $addrNumber;
            }

            $addrPhone = trim((string)$formData['addr_phone']);
            if ($addrPhone !== '') {
                $params['addr_phone'] = $addrPhone;
            }

            $addrFirst = trim((string)$formData['addr_first']);
            if ($addrFirst !== '') {
                $params['addr_first'] = $addrFirst;
            }

            $addrLast = trim((string)$formData['addr_last']);
            if ($addrLast !== '') {
                $params['addr_last'] = $addrLast;
            }

            $addrEmail = trim((string)$formData['addr_email']);
            if ($addrEmail !== '') {
                $params['addr_email'] = $addrEmail;
            }

            if (!$formData['rotate']) {
                $params['rotate'] = '0';
            } else {
                $params['rotate'] = '1';
            }

            if ($formData['require_proxy']) {
                $params['requireProxy'] = '1';
            }

            if ($formData['noproxy']) {
                $params['noproxy'] = '1';
            }

            if ($formData['debug']) {
                $params['debug'] = '1';
            }

            $proxy = trim((string)$formData['proxy']);
            if ($proxy !== '') {
                $params['proxy'] = $proxy;
            }

            try {
                $execution = run_autosh($params);
                $stdout = trim($execution['stdout']);
                $decoded = null;
                if ($stdout !== '') {
                    $decoded = json_decode($stdout, true);
                }

                $results[] = [
                    'card' => $cardLine,
                    'maskedCard' => mask_card_number($cardLine),
                    'stdout' => $stdout,
                    'stderr' => trim($execution['stderr']),
                    'exitCode' => $execution['exitCode'],
                    'duration' => $execution['duration'],
                    'parsed' => (json_last_error() === JSON_ERROR_NONE) ? $decoded : null,
                    'jsonError' => json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg(),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'card' => $cardLine,
                    'maskedCard' => mask_card_number($cardLine),
                    'stdout' => '',
                    'stderr' => $e->getMessage(),
                    'exitCode' => -1,
                    'duration' => 0.0,
                    'parsed' => null,
                    'jsonError' => $e->getMessage(),
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>hit.php - Address-first Checker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 30px;
            background: rgba(15, 23, 42, 0.8);
            border-radius: 16px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.6);
        }
        h1 {
            margin-top: 0;
            font-size: 32px;
            color: #38bdf8;
        }
        p.subtitle {
            margin: 8px 0 24px;
            color: #94a3b8;
        }
        form {
            display: grid;
            gap: 18px;
        }
        .grid {
            display: grid;
            gap: 16px;
        }
        .grid.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .grid.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #bae6fd;
        }
        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #1e293b;
            background: rgba(15, 23, 42, 0.9);
            color: #e2e8f0;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .options {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }
        .options label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-weight: 500;
        }
        .options input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        button {
            padding: 12px 18px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(90deg, #38bdf8, #6366f1);
            color: #0f172a;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.15s ease;
        }
        button:hover {
            transform: translateY(-1px);
        }
        .errors {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.4);
            padding: 16px;
            border-radius: 10px;
            color: #fecaca;
        }
        .results {
            margin-top: 40px;
            display: grid;
            gap: 20px;
        }
        .result-card {
            background: rgba(15, 23, 42, 0.85);
            border-radius: 14px;
            padding: 20px;
            border: 1px solid #1e293b;
        }
        .result-card h3 {
            margin: 0 0 8px;
            color: #f1f5f9;
        }
        .meta {
            font-size: 14px;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        pre {
            background: rgba(15, 23, 42, 0.7);
            padding: 14px;
            border-radius: 10px;
            overflow-x: auto;
            font-size: 13px;
        }
        .stderr {
            color: #fca5a5;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Manual Hit Checker</h1>
        <p class="subtitle">Provide the target site, card lines, and address details. The checker will reuse your address for every hit and rotate proxies via autosh.php.</p>

        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>Validation issues:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo html_escape($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <div>
                <label for="site">Target checkout URL</label>
                <input type="text" id="site" name="site" placeholder="https://example.myshopify.com" value="<?php echo html_escape($formData['site']); ?>" required>
            </div>

            <div>
                <label for="cc">Cards (one per line, format: CC|MM|YYYY|CVV)</label>
                <textarea id="cc" name="cc" placeholder="4111111111111111|12|2027|123"><?php echo html_escape($formData['cc']); ?></textarea>
            </div>

            <div class="grid two">
                <div>
                    <label for="addr_number">House number</label>
                    <input type="text" id="addr_number" name="addr_number" value="<?php echo html_escape($formData['addr_number']); ?>">
                </div>
                <div>
                    <label for="addr_line1">Street address</label>
                    <input type="text" id="addr_line1" name="addr_line1" value="<?php echo html_escape($formData['addr_line1']); ?>" required>
                </div>
            </div>

            <div>
                <label for="addr_line2">Address line 2 (optional)</label>
                <input type="text" id="addr_line2" name="addr_line2" value="<?php echo html_escape($formData['addr_line2']); ?>">
            </div>

            <div class="grid three">
                <div>
                    <label for="addr_city">City</label>
                    <input type="text" id="addr_city" name="addr_city" value="<?php echo html_escape($formData['addr_city']); ?>" required>
                </div>
                <div>
                    <label for="addr_state">State</label>
                    <input type="text" id="addr_state" name="addr_state" maxlength="2" value="<?php echo html_escape($formData['addr_state']); ?>" required>
                </div>
                <div>
                    <label for="addr_zip">ZIP</label>
                    <input type="text" id="addr_zip" name="addr_zip" value="<?php echo html_escape($formData['addr_zip']); ?>" required>
                </div>
            </div>

            <div class="grid three">
                <div>
                    <label for="addr_first">First name (optional)</label>
                    <input type="text" id="addr_first" name="addr_first" value="<?php echo html_escape($formData['addr_first']); ?>">
                </div>
                <div>
                    <label for="addr_last">Last name (optional)</label>
                    <input type="text" id="addr_last" name="addr_last" value="<?php echo html_escape($formData['addr_last']); ?>">
                </div>
                <div>
                    <label for="addr_email">Email (optional)</label>
                    <input type="email" id="addr_email" name="addr_email" value="<?php echo html_escape($formData['addr_email']); ?>">
                </div>
            </div>

            <div class="grid two">
                <div>
                    <label for="addr_phone">Phone (optional)</label>
                    <input type="text" id="addr_phone" name="addr_phone" value="<?php echo html_escape($formData['addr_phone']); ?>">
                </div>
                <div>
                    <label for="proxy">Explicit proxy (optional)</label>
                    <input type="text" id="proxy" name="proxy" placeholder="http://user:pass@ip:port" value="<?php echo html_escape($formData['proxy']); ?>">
                </div>
            </div>

            <div class="options">
                <label><input type="checkbox" name="rotate" value="1" <?php echo $formData['rotate'] ? 'checked' : ''; ?>> Rotate proxies</label>
                <label><input type="checkbox" name="require_proxy" value="1" <?php echo $formData['require_proxy'] ? 'checked' : ''; ?>> Require working proxy</label>
                <label><input type="checkbox" name="noproxy" value="1" <?php echo $formData['noproxy'] ? 'checked' : ''; ?>> Force no proxy</label>
                <label><input type="checkbox" name="debug" value="1" <?php echo $formData['debug'] ? 'checked' : ''; ?>> Debug</label>
            </div>

            <div>
                <button type="submit">Run Checks</button>
            </div>
        </form>

        <?php if (!empty($results)): ?>
            <div class="results">
                <?php foreach ($results as $result): ?>
                    <div class="result-card">
                        <h3>Card <?php echo html_escape($result['maskedCard']); ?></h3>
                        <div class="meta">
                            Exit code: <?php echo (int)$result['exitCode']; ?> |
                            Duration: <?php echo number_format((float)$result['duration'], 2); ?>s
                        </div>
                        <?php if ($result['parsed'] !== null): ?>
                            <pre><?php echo html_escape(json_encode($result['parsed'], JSON_PRETTY_PRINT)); ?></pre>
                        <?php else: ?>
                            <?php if (!empty($result['stdout'])): ?>
                                <pre><?php echo html_escape($result['stdout']); ?></pre>
                            <?php endif; ?>
                            <?php if (!empty($result['jsonError'])): ?>
                                <div class="meta">JSON decode warning: <?php echo html_escape($result['jsonError']); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($result['stderr'])): ?>
                            <pre class="stderr"><?php echo html_escape($result['stderr']); ?></pre>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
