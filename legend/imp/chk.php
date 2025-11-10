<?php
error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(120);
header('Content-Type: application/json');

require_once __DIR__ . '/ProxyManager.php';

// Utilities copied/lightweight versions from autosh.php
function flow_user_agent(): string {
    static $ua = null;
    if ($ua === null) $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129 Safari/537.36';
    return $ua;
}
function parse_proxy_components(string $proxyStr): array {
    $raw = trim($proxyStr);
    $type = 'http';
    $rest = $raw;
    if (preg_match('/^(https?|socks5h?|socks4a?|socks[45]):\/\/(.+)$/i', $raw, $m)) {
        $type = strtolower($m[1]);
        $rest = $m[2];
    }
    $user = '';$pass='';$hostport = $rest;
    if (strpos($rest, '@') !== false) {
        list($auth, $hostport) = explode('@', $rest, 2);
        if (strpos($auth, ':') !== false) { list($user,$pass) = explode(':', $auth, 2); } else { $user = $auth; }
    }
    $host = $hostport; $port='';
    if (strpos($hostport, ':') !== false) { list($host,$port)=explode(':',$hostport,2); }
    return ['type'=>$type,'host'=>$host,'port'=>$port,'user'=>$user,'pass'=>$pass];
}
function normalize_proxy_string(string $type, string $ip, string $port, string $user = '', string $pass = ''): string {
    $base = strtolower($type) . '://' . $ip . ':' . $port; if ($user!=='' && $pass!==''){ $base.=':'.$user.':'.$pass; } return $base;
}
function save_proxy_to_file(string $proxyString, string $file='ProxyList.txt'): void {
    $p = trim(strtolower($proxyString)); if ($p==='') return; $existing=[];
    if (file_exists($file)) { foreach ((file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]) as $l){ $existing[trim(strtolower($l))]=true; } }
    if (!isset($existing[$p])) { file_put_contents($file, $p.PHP_EOL, FILE_APPEND); }
}

function test_proxy_url(string $ip, string $port, string $username = '', string $password = '', string $type = 'http', string $testUrl = 'https://api.ipify.org?format=json', int $timeout=8): array {
    $start = microtime(true);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    $isSocks = ($type==='socks4'||$type==='socks4a'||$type==='socks5'||$type==='socks5h');
    $cto = $isSocks ? $timeout : max(4, (int)($timeout/2));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $cto);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: '.flow_user_agent(),
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
        'Connection: keep-alive'
    ]);
    $ptype = CURLPROXY_HTTP;
    if ($type==='socks4'||$type==='socks4a'){ $ptype = (defined('CURLPROXY_SOCKS4A')&&$type==='socks4a')?CURLPROXY_SOCKS4A:CURLPROXY_SOCKS4; }
    elseif ($type==='socks5'||$type==='socks5h'){ $ptype = (defined('CURLPROXY_SOCKS5_HOSTNAME')&&$type==='socks5h')?CURLPROXY_SOCKS5_HOSTNAME:CURLPROXY_SOCKS5; }
    elseif ($type==='https'){ $ptype = CURLPROXY_HTTPS; }
    curl_setopt($ch, CURLOPT_PROXY, $ip.':'.$port);
    curl_setopt($ch, CURLOPT_PROXYTYPE, $ptype);
    if ($username!=='' && $password!=='') { curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username.':'.$password); }
    $scheme = strtolower(parse_url($testUrl, PHP_URL_SCHEME)?:'http');
    $needsTunnel = ($scheme==='https' && ($ptype===CURLPROXY_HTTP || $ptype===CURLPROXY_HTTPS));
    curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $needsTunnel);

    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $elapsed = round((microtime(true)-$start)*1000);
    return ['ok'=>($resp!==false && $code>0), 'code'=>$code, 'ms'=>$elapsed, 'error'=>$err?:null, 'body'=>is_string($resp)?substr($resp,0,256):null];
}

// Inputs
$rawProxy = isset($_GET['proxy']) ? (string)$_GET['proxy'] : '';
$target = isset($_GET['site']) ? (string)$_GET['site'] : '';
$save = isset($_GET['save']);

if ($rawProxy==='') { echo json_encode(['success'=>false,'error'=>'proxy parameter required']); exit; }
if ($target==='') { $target = 'https://api.ipify.org?format=json'; }
$host = parse_url($target, PHP_URL_HOST); if ($host){ $target = 'https://'.$host; }

$pc = parse_proxy_components($rawProxy);
$tests = ['socks5','socks5h','http','socks4','socks4a','https'];
$results = [];$winner=null;$norm='';
foreach ($tests as $t){
    $r = test_proxy_url($pc['host'],$pc['port'],$pc['user'],$pc['pass'],$t,$target);
    $results[$t]=$r;
    if ($winner===null && $r['ok']) { $winner=$t; }
}
if ($winner===null){
    // retry via ipify fallback
    foreach ($tests as $t){
        $r = test_proxy_url($pc['host'],$pc['port'],$pc['user'],$pc['pass'],$t,'https://api.ipify.org?format=json');
        $results[$t.'_ipify']=$r;
        if ($winner===null && $r['ok']) { $winner=$t; }
    }
}

$response = [
    'success' => ($winner!==null),
    'detected_type' => $winner,
    'proxy' => $rawProxy,
    'normalized' => null,
    'target' => $target,
    'tested' => array_keys($results),
    'details' => $results
];

if ($winner!==null){
    $norm = normalize_proxy_string($winner,$pc['host'],$pc['port'],$pc['user'],$pc['pass']);
    $response['normalized'] = $norm;
    if ($save) { save_proxy_to_file($norm, __DIR__.'/ProxyList.txt'); $response['saved']=true; }
}

echo json_encode($response, JSON_PRETTY_PRINT);
