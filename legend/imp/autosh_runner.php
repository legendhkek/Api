<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "autosh_runner.php must be executed from the command line.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php autosh_runner.php \"query_string\" [post_payload]\n");
    exit(1);
}

$baseDir = __DIR__;
chdir($baseDir);

parse_str($argv[1], $getParams);
if (!is_array($getParams)) {
    $getParams = [];
}

$_GET = $getParams;
$_POST = [];
$_REQUEST = $getParams;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['QUERY_STRING'] = $argv[1];

if ($argc > 2) {
    $rawPost = $argv[2];
    $postParams = [];

    if ($rawPost !== '') {
        $jsonAttempt = json_decode($rawPost, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonAttempt)) {
            $postParams = $jsonAttempt;
        } else {
            parse_str($rawPost, $postAttempt);
            if (is_array($postAttempt)) {
                $postParams = $postAttempt;
            }
        }
    }

    if (!empty($postParams)) {
        $_POST = $postParams;
        $_REQUEST = array_merge($_REQUEST, $postParams);
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }
}

define('AUTOSH_RUN_AS_LIBRARY', true);
require_once $baseDir . '/autosh.php';
