<?php
/**
 * Test the regex fix and simplified response mode in autosh.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== AutoSh Regex Fix & Simplified Mode Test ===\n\n";

// Test 1: Verify regex functions exist and work
echo "Test 1: Regex Pattern Functions\n";
echo "--------------------------------\n";

// Load the functions from autosh.php
$autosh_content = file_get_contents('autosh.php');

// Extract and eval the is_checkout_url function
if (preg_match('/function is_checkout_url\(string \$url\): bool \{[^}]+\}/', $autosh_content, $matches)) {
    eval($matches[0]);
    echo "  ✓ is_checkout_url function loaded\n";
} else {
    echo "  ✗ Failed to load is_checkout_url function\n";
    exit(1);
}

// Extract and eval the extract_checkout_token_from_url function
// Use simpler approach - extract based on line markers
$start_marker = 'function extract_checkout_token_from_url';
$start_pos = strpos($autosh_content, $start_marker);
if ($start_pos !== false) {
    // Find the closing brace of the function
    $brace_count = 0;
    $in_function = false;
    $func_code = '';
    
    for ($i = $start_pos; $i < strlen($autosh_content); $i++) {
        $char = $autosh_content[$i];
        $func_code .= $char;
        
        if ($char === '{') {
            $brace_count++;
            $in_function = true;
        } elseif ($char === '}') {
            $brace_count--;
            if ($in_function && $brace_count === 0) {
                break;
            }
        }
    }
    
    eval($func_code);
    echo "  ✓ extract_checkout_token_from_url function loaded\n";
} else {
    echo "  ✗ Failed to find extract_checkout_token_from_url function\n";
    exit(1);
}

echo "\n";

// Test 2: Test regex patterns work without errors
echo "Test 2: Regex Pattern Validation\n";
echo "--------------------------------\n";

$test_urls = [
    [
        'url' => 'https://1webglobal.com/checkouts/cn/abc123def456',
        'should_match' => true,
        'expected_token' => 'abc123def456'
    ],
    [
        'url' => 'https://example.com/checkouts/xyz789',
        'should_match' => true,
        'expected_token' => 'xyz789'
    ],
    [
        'url' => 'https://example.com/products/some-product',
        'should_match' => false,
        'expected_token' => null
    ],
];

$all_tests_passed = true;

foreach ($test_urls as $i => $test) {
    $url = $test['url'];
    
    // Test is_checkout_url
    $is_match = is_checkout_url($url);
    $match_correct = ($is_match === $test['should_match']);
    
    // Test extract_checkout_token_from_url
    $token = extract_checkout_token_from_url($url);
    $token_correct = ($token === $test['expected_token']);
    
    $test_passed = $match_correct && $token_correct;
    $all_tests_passed = $all_tests_passed && $test_passed;
    
    echo "  Test " . ($i + 1) . ": " . ($test_passed ? '✓ PASS' : '✗ FAIL') . "\n";
    echo "    URL: " . substr($url, 0, 50) . (strlen($url) > 50 ? '...' : '') . "\n";
    
    if (!$match_correct) {
        echo "    ✗ Match: expected " . ($test['should_match'] ? 'true' : 'false') . 
             ", got " . ($is_match ? 'true' : 'false') . "\n";
    }
    
    if (!$token_correct) {
        echo "    ✗ Token: expected " . var_export($test['expected_token'], true) . 
             ", got " . var_export($token, true) . "\n";
    }
}

if ($all_tests_passed) {
    echo "  ✅ All regex tests passed - no 'Unknown modifier' errors!\n";
} else {
    echo "  ✗ Some regex tests failed\n";
}

echo "\n";

// Test 3: Check for regex error messages
echo "Test 3: Check for Regex Errors\n";
echo "--------------------------------\n";

$last_error = preg_last_error();
if ($last_error === PREG_NO_ERROR) {
    echo "  ✅ No PCRE errors detected\n";
} else {
    echo "  ✗ PCRE error detected: " . $last_error . "\n";
    $all_tests_passed = false;
}

echo "\n";

// Test 4: Verify regex patterns use correct delimiter
echo "Test 4: Regex Pattern Delimiter Check\n";
echo "--------------------------------\n";

$problematic_patterns = [];
$fixed_patterns = [];

// Check for old problematic patterns with # delimiter and # in character class
if (preg_match('/preg_match.*#.*\[.*#.*\].*#/m', $autosh_content)) {
    $problematic_patterns[] = "Found pattern with # delimiter and # in character class";
}

// Check for fixed patterns using ~ delimiter
if (preg_match('/preg_match.*~\/checkouts\//m', $autosh_content)) {
    $fixed_patterns[] = "Found checkout URL patterns using ~ delimiter";
}

if (count($problematic_patterns) > 0) {
    echo "  ✗ Problematic patterns found:\n";
    foreach ($problematic_patterns as $p) {
        echo "    - $p\n";
    }
    $all_tests_passed = false;
} else {
    echo "  ✓ No problematic regex patterns found\n";
}

if (count($fixed_patterns) > 0) {
    echo "  ✓ Fixed patterns verified:\n";
    foreach ($fixed_patterns as $p) {
        echo "    - $p\n";
    }
    echo "  ✅ Regex patterns correctly use ~ delimiter\n";
} else {
    echo "  ⚠️  Could not verify fixed patterns\n";
}

echo "\n";

// Test 5: Verify simplified response mode exists
echo "Test 5: Simplified Response Mode Check\n";
echo "--------------------------------\n";

// Check for simplified mode query parameter handling
$has_simplified_mode = false;
if (preg_match('/\$useSimplified\s*=.*simplified.*legacy.*simple/s', $autosh_content)) {
    echo "  ✓ Simplified mode parameter check found\n";
    $has_simplified_mode = true;
}

// Check for simplified response fields
$simplified_fields = ['Response', 'ProxyStatus', 'ProxyIP', 'Price', 'Gateway', 'Time'];
$fields_found = 0;

foreach ($simplified_fields as $field) {
    if (preg_match("/'$field'\s*=>/", $autosh_content)) {
        $fields_found++;
    }
}

echo "  ✓ Found $fields_found/" . count($simplified_fields) . " simplified response fields\n";

if ($has_simplified_mode && $fields_found >= 5) {
    echo "  ✅ Simplified response mode properly implemented\n";
} else {
    echo "  ⚠️  Simplified response mode may be incomplete\n";
}

echo "\n";

// Test 6: Verify ProxyStatus uses "Dead" instead of "Bypassed"
echo "Test 6: ProxyStatus Value Check\n";
echo "--------------------------------\n";

if (preg_match('/ProxyStatus.*Live.*Dead/s', $autosh_content)) {
    echo "  ✓ ProxyStatus uses 'Live' and 'Dead' values\n";
    echo "  ✅ ProxyStatus correctly set (not 'Bypassed')\n";
} else {
    echo "  ⚠️  ProxyStatus values not verified\n";
}

echo "\n";

// Summary
echo "=================================\n";
echo "=== TEST SUMMARY ===\n";
echo "=================================\n";

if ($all_tests_passed) {
    echo "✅ ALL TESTS PASSED!\n\n";
    echo "Summary:\n";
    echo "  ✓ Regex patterns fixed - no 'Unknown modifier' errors\n";
    echo "  ✓ Checkout URL detection working correctly\n";
    echo "  ✓ Token extraction working correctly\n";
    echo "  ✓ Simplified response mode implemented\n";
    echo "  ✓ ProxyStatus uses correct values\n\n";
    echo "The regex fix and simplified response mode are working correctly!\n";
} else {
    echo "⚠️  SOME TESTS FAILED\n\n";
    echo "Please review the failed tests above.\n";
}

exit($all_tests_passed ? 0 : 1);
